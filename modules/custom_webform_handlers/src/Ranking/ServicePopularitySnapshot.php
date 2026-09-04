<?php

namespace Drupal\custom_webform_handlers\Ranking;

/**
 * How often each Service Type was booked over one closed window of history.
 *
 * Counts and two timestamps. No submission identifier, no customer, no order, no price
 * — the aggregation that builds this reduces every booking to "one more of this
 * service" before the value ever exists, so there is nothing here that identifies
 * anybody even though it is written to durable storage and read on a public page.
 *
 * The window is closed on purpose. A snapshot describes the period ending at
 * computedAt and never updates itself; a booking taken a minute after the nightly run
 * is counted by the next one. That is what makes the object safe to cache and safe to
 * reason about — two readers holding the same snapshot always produce the same order,
 * so a customer refreshing a page never sees the cards move for reasons nobody can
 * reconstruct.
 *
 * Ordering lives here rather than in the reader because it is a pure function of these
 * counts and the caller's configured order, and pure functions belong next to the data
 * they read. The reader's job is only to find a snapshot; deciding what it means is
 * this class's job, and there is exactly one implementation of that decision.
 */
final readonly class ServicePopularitySnapshot {

  /**
   * The Service Type that is shown first regardless of what the counts say.
   *
   * Express Courier is the only self-bookable service — see self_bookable.service_types
   * in custom_webform_handlers.dispatch — so it is the only card that leads anywhere
   * other than a staff review. A popularity measure that could push it below a service
   * a visitor cannot actually book would be actively worse than no measure at all, and
   * on a young site it would: the other services accumulate manual-review requests
   * while Courier accumulates paid bookings, and the two are not the same denominator.
   *
   * A PHP constant and not configuration, because "permanently first" is the stated
   * invariant. An operator who could switch it off would be able to break the one
   * ordering promise this feature makes, and would have no way to know they had.
   */
  const PINNED_FIRST = 'courier';

  /**
   * The storage format version.
   *
   * Read back on every load. A snapshot written by a different release is discarded
   * rather than reinterpreted, because the alternative — guessing at an older shape —
   * risks ordering a public page from numbers that meant something else.
   */
  const VERSION = 1;

  /**
   * @param array<string, int> $counts
   *   Service Type machine value to the number of settled bookings in the window.
   *   Services with no bookings may be absent; absent and zero mean the same thing.
   * @param int $computedAt
   *   When the aggregation ran, as a Unix timestamp. The window ends here.
   * @param int $windowDays
   *   How many days back the aggregation looked. Carried with the counts so an
   *   operator reading the stored value knows what they are looking at, and so a
   *   snapshot computed under an older setting is self-describing.
   */
  public function __construct(
    public array $counts,
    public int $computedAt,
    public int $windowDays,
  ) {}

  /**
   * The number of bookings this snapshot actually counted.
   *
   * Derived rather than stored. A separately recorded total is one more thing that can
   * disagree with the counts it is supposed to summarise, and the threshold check below
   * has to be answered from the same numbers the ordering uses or the two can diverge.
   */
  public function totalBookings(): int {
    return array_sum($this->counts);
  }

  /**
   * Tells whether there is enough history here to reorder anything.
   *
   * Below the threshold the counts are real but not yet meaningful: with a handful of
   * bookings one customer's choice decides a rank, and the cards would visibly shuffle
   * week to week for no reason a visitor could perceive. The configured order is the
   * better answer then, and this is the question that chooses it.
   *
   * The floor of one is not defensive tidiness. A configured threshold of zero would
   * otherwise make an empty snapshot "usable" and produce an order derived from no
   * bookings at all, which is the configured order arrived at expensively and by
   * accident rather than deliberately.
   */
  public function isUsable(int $minimumSamples): bool {
    return $this->totalBookings() >= max(1, $minimumSamples);
  }

  /**
   * Returns the given services most-booked first, with the pinned service leading.
   *
   * Total and deterministic. Two services with equal counts are separated by their
   * position in the caller's own list, so the result never depends on hash order,
   * insertion order, or the sort implementation — the same counts and the same input
   * always produce the same output, which is what makes this cacheable.
   *
   * A permutation, guaranteed structurally: every returned member comes from the input,
   * and the pinned service is only moved when it is already there. A service missing
   * from the counts sorts as zero and stays in the list.
   */
  public function order(array $serviceIds): array {
    $ids = [];
    foreach ($serviceIds as $id) {
      if (is_string($id) && $id !== '' && !in_array($id, $ids, TRUE)) {
        $ids[] = $id;
      }
    }

    if (count($ids) < 2) {
      return $ids;
    }

    // The configured position of each service, which is both the tie-breaker and the
    // fallback. Captured before sorting, because the sort is about to destroy it.
    $configured = array_flip($ids);

    $pinned = in_array(self::PINNED_FIRST, $ids, TRUE) ? [self::PINNED_FIRST] : [];
    $rest = array_values(array_diff($ids, $pinned));

    usort($rest, function (string $a, string $b) use ($configured): int {
      $byBookings = ($this->counts[$b] ?? 0) <=> ($this->counts[$a] ?? 0);

      return $byBookings !== 0 ? $byBookings : ($configured[$a] <=> $configured[$b]);
    });

    return array_merge($pinned, $rest);
  }

  /**
   * Reduces this to the scalars the store persists.
   */
  public function toStorage(): array {
    return [
      'v' => self::VERSION,
      'counts' => $this->counts,
      'computed_at' => $this->computedAt,
      'window_days' => $this->windowDays,
    ];
  }

  /**
   * Rebuilds a snapshot from stored scalars, or returns NULL when it cannot.
   *
   * NULL for anything unexpected — wrong version, wrong shape, a count that is not a
   * number. The caller's response to NULL is the configured order, which is always a
   * correct answer, so there is nothing to be gained by salvaging a value this cannot
   * fully understand and something to lose by ordering a page from it.
   */
  public static function fromStorage(mixed $stored): ?self {
    if (!is_array($stored) || ($stored['v'] ?? NULL) !== self::VERSION) {
      return NULL;
    }

    $counts = $stored['counts'] ?? NULL;
    if (!is_array($counts)) {
      return NULL;
    }

    $clean = [];
    foreach ($counts as $service => $bookings) {
      if (is_string($service) && $service !== '' && is_int($bookings) && $bookings >= 0) {
        $clean[$service] = $bookings;
      }
    }

    return new self(
      $clean,
      (int) ($stored['computed_at'] ?? 0),
      (int) ($stored['window_days'] ?? 0),
    );
  }

}
