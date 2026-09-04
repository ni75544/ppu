<?php

namespace Drupal\custom_webform_handlers\Dispatch;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\custom_webform_handlers\Dispatch\Notify\NotificationLogInterface;
use Drupal\custom_webform_handlers\Dispatch\Status\ResourceStatusStoreInterface;
use Drupal\custom_webform_handlers\Dispatch\Storage\ReservationStoreInterface;
use Drupal\custom_webform_handlers\Review\ReviewManagerInterface;
use Drupal\custom_webform_handlers\Review\ReviewRequest;
use Drupal\webform\WebformSubmissionInterface;
use Psr\Log\LoggerInterface;

/**
 * Assembles the Dispatch board once, from projections that are already safe.
 *
 * The reader's whole job is to finish the read before anything can render it. It
 * resolves the Resource, decides which local days the request really covers, asks
 * the store for the administrator-safe timeline, scans for stalled quotes, and
 * hands back a DispatchBoard with nothing left to query. A template that receives
 * one cannot walk back into the database through it, because there is nothing in
 * it to walk.
 *
 * It reads. It never claims, confirms, releases, renews, expires, blacks out, or
 * reprices, and it holds no lock — capacity changes go through the planner and the
 * Resource Calendar Guard, and a board that could bypass them would defeat the
 * guarantee those exist to make.
 *
 * Two data sources, kept apart on purpose. Scheduled work comes from the store's
 * adminTimeline() projection, which is where the padded-interval and expired-Hold
 * rules already live. Manual Review comes from the Review Requests themselves,
 * consumes no capacity, and is returned as a separate queue so it can never be
 * mistaken for occupancy on a lane. Submissions are still read, but only to describe
 * a request that has already been found — never to discover one, because a list
 * assembled by scanning submissions and a list of the rows that record staff
 * decisions are two different things that can disagree.
 *
 * Two further sources are read for the board's integration panels and are kept apart
 * from both of those: what staff reported about the Resource, and what the site tried
 * to tell people. Neither is consulted when deciding what the timeline says. They are
 * read last, after the range has been proved, and a failure to read either would be a
 * missing panel rather than a wrong schedule — which is the whole reason they are
 * separate reads on separate seams and not columns on the timeline.
 */
final class DispatchBoardReader implements DispatchBoardReaderInterface {

  /**
   * Manual Review entries one board will carry.
   */
  private const QUEUE_LIMIT = 50;

  /**
   * The stall reasons that may be shown verbatim.
   *
   * An allow-list rather than a pattern, because the quote handler also records a
   * distance provider's own exception message, and that is free text which can
   * carry a request URL, a key, or an address. Anything not named here becomes
   * REASON_OTHER, so an operator still learns that the provider failed without a
   * provider's error string reaching an administrative screen.
   */
  private const KNOWN_REASONS = [
    'missing_route_addresses',
    'quote_calculation_failed',
  ];

  /**
   * Stands in for any reason that is not on the allow-list.
   */
  private const REASON_OTHER = 'quote_provider_error';

  /**
   * Resource Status reports one board carries.
   *
   * Short on purpose. The panel exists so an operator can see the last few things
   * staff said about the vehicle, not so the board becomes a log viewer.
   */
  private const STATUS_HISTORY_LIMIT = 10;

  /**
   * Notification attempts one board carries.
   *
   * Larger than the status history because one committed fact can produce two rows —
   * a customer copy and a staff copy — so a smaller window would show a single
   * afternoon's bookings and nothing before them.
   */
  private const NOTIFICATION_HISTORY_LIMIT = 20;

  /**
   * Constructs the board reader.
   *
   * @param \Drupal\custom_webform_handlers\Dispatch\Storage\ReservationStoreInterface $store
   *   Read through its administrator-safe projections only. The planner-only
   *   reads on this seam, planningNeighbours() above all, are never called here.
   * @param \Drupal\custom_webform_handlers\Dispatch\ResourceProfileFactory $profileFactory
   *   Supplies the Resource and its calendar facts, or refuses with NULL.
   * @param \Drupal\custom_webform_handlers\Dispatch\DispatchRequestFactory $requestFactory
   *   Says which Webforms dispatch owns, and which element holds their quote
   *   status. Both questions belong to it, so they are not re-derived here.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   Loads the Webforms dispatch owns, and the submissions behind the Review
   *   Requests the Manual Review queue is built from. It describes those rows; it
   *   no longer finds them, and a submission it cannot load costs the queue a
   *   timestamp rather than an entry.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   Stamps the board. Hold expiry moves with no cache tag to invalidate, so a
   *   rendered board is only true as of this instant.
   * @param \Drupal\custom_webform_handlers\Dispatch\Status\ResourceStatusStoreInterface $statuses
   *   What staff reported about the Resource. Read for the board's own panel and
   *   never consulted when deciding what the timeline says: a report is somebody's
   *   observation, and capacity is proved by the Reservation rows.
   * @param \Drupal\custom_webform_handlers\Dispatch\Notify\NotificationLogInterface $notifications
   *   What the site tried to tell people. Carries no recipient and no message body,
   *   so a board built from it can be shown to anybody who may see the schedule.
   * @param \Psr\Log\LoggerInterface $logger
   *   Records a misconfigured dispatch Webform, which is an operational fault
   *   rather than a rendering one.
   * @param \Drupal\custom_webform_handlers\Review\ReviewManagerInterface $reviews
   *   The one authority on Review Requests, and now the only source of the Manual
   *   Review queue. Asked for its open queue and nothing else: the board reads
   *   requests, it does not open, decide, or expire them. Reading here is what makes
   *   the row an operator works from and the row that records their decision the same
   *   row, which is specification item 6.
   */
  public function __construct(
    private readonly ReservationStoreInterface $store,
    private readonly ResourceProfileFactory $profileFactory,
    private readonly DispatchRequestFactory $requestFactory,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly TimeInterface $time,
    private readonly ResourceStatusStoreInterface $statuses,
    private readonly NotificationLogInterface $notifications,
    private readonly LoggerInterface $logger,
    private readonly ReviewManagerInterface $reviews,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function board(string $resourceId, \DateTimeImmutable $from, \DateTimeImmutable $to): DispatchBoard {
    $generatedAt = $this->time->getRequestTime();

    $profile = $this->profileFactory->create();
    if ($profile === NULL) {
      return DispatchBoard::unavailable($generatedAt, DispatchBoard::NOTICE_NO_PROFILE);
    }

    // A malformed or foreign Resource is refused, never normalised. Substituting
    // the configured Resource would answer a question nobody asked, and widening
    // to every Resource is precisely the failure this guards against.
    $requested = trim($resourceId);
    if ($requested === '' || preg_match(ResourceProfileFactory::RESOURCE_ID_PATTERN, $requested) !== 1) {
      return DispatchBoard::unavailable($generatedAt, DispatchBoard::NOTICE_UNKNOWN_RESOURCE);
    }
    if ($requested !== $profile->resourceId) {
      return DispatchBoard::unavailable($generatedAt, DispatchBoard::NOTICE_UNKNOWN_RESOURCE);
    }

    // The range is the Resource's local calendar days, not the caller's instants.
    // A board is read as days, and whose days they are is a fact about the
    // Resource, so both ends widen to the local day they land in.
    $zone = $profile->timezoneObject();
    $firstDate = $from->setTimezone($zone)->format('Y-m-d');
    $lastDate = $to->setTimezone($zone)->format('Y-m-d');
    if ($lastDate < $firstDate) {
      return DispatchBoard::unavailable($generatedAt, DispatchBoard::NOTICE_INVALID_RANGE);
    }

    $localDates = $this->localDates($firstDate, $lastDate, $zone);
    if ($localDates === []) {
      return DispatchBoard::unavailable($generatedAt, DispatchBoard::NOTICE_INVALID_RANGE);
    }
    $lastCovered = end($localDates);

    $notices = [];
    if ($lastCovered !== $lastDate) {
      $notices[] = DispatchBoard::NOTICE_RANGE_CLAMPED;
    }

    $opensAt = $this->localMidnight($localDates[0], $zone);
    $lastDayOpensAt = $this->localMidnight($lastCovered, $zone);
    $closesAt = $lastDayOpensAt instanceof \DateTimeImmutable ? $lastDayOpensAt->modify('+1 day') : FALSE;
    if (!$opensAt instanceof \DateTimeImmutable || !$closesAt instanceof \DateTimeImmutable) {
      return DispatchBoard::unavailable($generatedAt, DispatchBoard::NOTICE_INVALID_RANGE);
    }

    $rangeStart = $opensAt->getTimestamp();
    $rangeEnd = $closesAt->getTimestamp();
    if ($rangeEnd <= $rangeStart) {
      return DispatchBoard::unavailable($generatedAt, DispatchBoard::NOTICE_INVALID_RANGE);
    }

    // The queue is deliberately not filtered to the range. Manual Review work is
    // unscheduled — it sits on no day — so scoping it to the days on screen would
    // hide it from the operator whenever they paged past its arrival date.
    $manualReview = $this->manualReviewQueue($notices);

    // Read last, and not filtered to the range either. Both answer "what is going on
    // with this vehicle now", which is a question about the Resource rather than about
    // the days on screen: paging to next week must not make the current report vanish
    // or a failed customer notification look like it never happened.
    return DispatchBoard::forResource(
      $profile,
      $generatedAt,
      $rangeStart,
      $rangeEnd,
      $localDates,
      $this->store->adminTimeline($profile->resourceId, $rangeStart, $rangeEnd),
      $this->store->legacyUnplannedDates($profile->resourceId, $localDates[0], $lastCovered),
      $manualReview,
      $this->statuses->current($profile->resourceId),
      $this->statuses->recent($profile->resourceId, self::STATUS_HISTORY_LIMIT),
      $this->notifications->recent($profile->resourceId, self::NOTIFICATION_HISTORY_LIMIT),
      $this->notifications->attentionCount($profile->resourceId, self::NOTIFICATION_HISTORY_LIMIT),
      $notices,
    );
  }

  /**
   * {@inheritdoc}
   */
  public function reservation(int $dispatchReservationId): ?DispatchReservationDetail {
    if ($dispatchReservationId <= 0) {
      return NULL;
    }

    return $this->store->adminReservation($dispatchReservationId);
  }

  /**
   * Expands a local date range into the days one board may cover.
   *
   * @param string $firstDate
   *   The first local Y-m-d date requested.
   * @param string $lastDate
   *   The last local Y-m-d date requested, inclusive.
   * @param \DateTimeZone $zone
   *   The Resource's zone.
   *
   * @return string[]
   *   Ascending Y-m-d dates, at most DispatchBoard::MAX_LOCAL_DAYS of them. A
   *   longer request is cut rather than refused, and the caller detects the cut
   *   by comparing the last date returned with the last one asked for.
   */
  private function localDates(string $firstDate, string $lastDate, \DateTimeZone $zone): array {
    $day = $this->localMidnight($firstDate, $zone);
    if ($day === NULL) {
      return [];
    }

    $dates = [];
    while (count($dates) < DispatchBoard::MAX_LOCAL_DAYS) {
      $date = $day->format('Y-m-d');
      $dates[] = $date;
      if ($date >= $lastDate) {
        break;
      }
      $next = $day->modify('+1 day');
      if (!$next instanceof \DateTimeImmutable) {
        break;
      }
      $day = $next;
    }

    return $dates;
  }

  /**
   * Midnight starting one local date, or NULL when the date cannot be read.
   *
   * The zone comes from the Resource Profile, which refuses to exist with a
   * timezone PHP does not recognise, so no default zone is substituted here.
   */
  private function localMidnight(string $date, \DateTimeZone $zone): ?\DateTimeImmutable {
    $midnight = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, $zone);

    return $midnight instanceof \DateTimeImmutable ? $midnight : NULL;
  }

  /**
   * Builds the bounded queue of quotes that are waiting on a human.
   *
   * Read from the Review Requests themselves, which is specification item 6: one
   * queue. It used to be a scan of every dispatch Webform's newest submissions,
   * filtered on a saved quote status — so the list an operator worked from and the
   * rows that recorded their decisions were two different things, discovered
   * separately, and able to disagree. A submission whose review had been declined
   * still carried the manual-review status and so still appeared as work; a request
   * somebody had already taken looked identical to one nobody had touched.
   *
   * openQueue() answers both. It returns Submitted and UnderReview only, so a
   * declined, cancelled, expired, or approved-and-awaiting-payment request leaves the
   * queue by changing state rather than by being filtered out here, and the entry
   * carries the request's identity so the board's operations are its own transitions.
   *
   * Order is the manager's — oldest first, the order work was received in. Nothing is
   * re-sorted here: how this queue should be ranked is a separate question from where
   * it comes from, and imposing an order would answer it by accident.
   *
   * @param string[] $notices
   *   Notice keys, appended to in place.
   *
   * @return \Drupal\custom_webform_handlers\Dispatch\DispatchManualReviewEntry[]
   *   Entries, oldest arrival first.
   */
  private function manualReviewQueue(array &$notices): array {
    // Configuration is still checked per Webform, and now only for that: a dispatch
    // Webform with no quote status element cannot record that a job needs pricing, so
    // it is a fault an operator should see on the board. It is no longer how the work
    // is found, so a misconfigured Webform no longer hides work that does exist.
    foreach ($this->dispatchWebforms() as $webformId) {
      $settings = $this->requestFactory->settingsFor($webformId);
      if (trim((string) ($settings['quote_status_field'] ?? '')) === '') {
        $this->noteUnconfigured($webformId, $notices);
      }
    }

    try {
      // One more than the board shows, which is how the cap is detected without a
      // second count query: asking for 51 and receiving 51 means there is a 51st.
      $requests = $this->reviews->openQueue(self::QUEUE_LIMIT + 1);
    }
    catch (\Throwable $exception) {
      // The board still renders. Its timeline is built from Reservation rows and is
      // unaffected, and an empty queue beside a working schedule is a visible fault
      // rather than a silent wrong answer — the log says which.
      $this->logger->error('Dispatch board: the Review Request queue could not be read: @type.', [
        '@type' => get_class($exception),
      ]);

      return [];
    }

    if (count($requests) > self::QUEUE_LIMIT) {
      $requests = array_slice($requests, 0, self::QUEUE_LIMIT);
      $notices[] = DispatchBoard::NOTICE_MANUAL_REVIEW_TRUNCATED;
    }

    return $this->entriesFor($requests);
  }

  /**
   * Turns open requests into board rows, adding only what the request cannot say.
   *
   * A request knows which job it is and when it was raised; it does not know why the
   * quote engine gave up, because that is the quote's own record on the submission.
   * So the submissions are loaded — the ones named by this page of the queue and no
   * others, in one query rather than one per row — purely for their breakdown.
   *
   * A request whose submission has been deleted is still shown. It is still work
   * somebody is owed an answer on, and the alternative is a job that quietly stops
   * appearing on the board while its customer's tracking link still works.
   *
   * @param \Drupal\custom_webform_handlers\Review\ReviewRequest[] $requests
   *   The page of open requests to render.
   *
   * @return \Drupal\custom_webform_handlers\Dispatch\DispatchManualReviewEntry[]
   *   Entries in the order the requests were given.
   */
  private function entriesFor(array $requests): array {
    if ($requests === []) {
      return [];
    }

    $submissions = $this->submissionsFor($requests);
    $breakdownElements = [];

    $entries = [];
    foreach ($requests as $request) {
      if (!array_key_exists($request->webformId, $breakdownElements)) {
        $settings = $this->requestFactory->settingsFor($request->webformId);
        $breakdownElements[$request->webformId] = trim((string) ($settings['quote_breakdown_field'] ?? ''));
      }

      $element = $breakdownElements[$request->webformId];
      $submission = $submissions[$request->submissionId] ?? NULL;

      $entries[] = new DispatchManualReviewEntry(
        $request->submissionId,
        $request->webformId,
        $this->arrivedAt($submission, $request),
        DispatchManualReviewEntry::STATUS_MANUAL_REVIEW,
        $element === '' || $submission === NULL ? [] : $this->reasonsFor($submission->getElementData($element)),
        $request->reviewId,
        $request->state,
      );
    }

    return $entries;
  }

  /**
   * Loads exactly the submissions this page of the queue names, keyed by id.
   *
   * A read failure is not fatal to the queue. What is lost is the reason column and
   * the arrival times taken from the submission rows, and a queue that says less is
   * better than a board that shows no waiting work at all while work is waiting.
   *
   * @param \Drupal\custom_webform_handlers\Review\ReviewRequest[] $requests
   *   The requests whose submissions are wanted.
   *
   * @return \Drupal\webform\WebformSubmissionInterface[]
   *   Submissions keyed by submission id. Missing ids simply have no entry.
   */
  private function submissionsFor(array $requests): array {
    $ids = [];
    foreach ($requests as $request) {
      if ($request->submissionId > 0) {
        $ids[] = $request->submissionId;
      }
    }

    if ($ids === []) {
      return [];
    }

    try {
      $loaded = $this->entityTypeManager->getStorage('webform_submission')->loadMultiple($ids);
    }
    catch (\Exception $e) {
      $this->logger->error('Dispatch board: submissions named by the Review Request queue could not be read: @message', [
        '@message' => $e->getMessage(),
      ]);

      return [];
    }

    $submissions = [];
    foreach ($loaded as $submission) {
      if ($submission instanceof WebformSubmissionInterface) {
        $submissions[(int) $submission->id()] = $submission;
      }
    }

    return $submissions;
  }

  /**
   * When this work arrived, preferring the submission's own record of it.
   *
   * The submission is the better answer because it is when the customer finished
   * asking; the request was raised in the same save, but a backfilled request was
   * raised whenever the backfill ran, which for historical work is much later. A
   * legacy row that never recorded a completion falls back to its creation time, and
   * a submission that has been deleted falls back to the request — in that order,
   * because an entry with no arrival time reads as the oldest work on the board.
   */
  private function arrivedAt(?WebformSubmissionInterface $submission, ReviewRequest $request): int {
    if ($submission !== NULL) {
      $completed = (int) ($submission->get('completed')->value ?? 0);
      if ($completed > 0) {
        return $completed;
      }

      $created = (int) ($submission->get('created')->value ?? 0);
      if ($created > 0) {
        return $created;
      }
    }

    return $request->created;
  }

  /**
   * Lists the Webform IDs the Dispatch Planner owns.
   *
   * @return string[]
   *   Webform IDs, ascending. Ownership is DispatchRequestFactory's judgement and
   *   is not second-guessed here: a Webform still on the legacy reservation flow
   *   is not a dispatch Webform, however its quote elements are configured.
   */
  private function dispatchWebforms(): array {
    try {
      $ids = $this->entityTypeManager->getStorage('webform')
        ->getQuery()
        ->accessCheck(FALSE)
        ->execute();
    }
    catch (\Exception $e) {
      $this->logger->error('Dispatch board: the Webform list could not be read: @message', ['@message' => $e->getMessage()]);

      return [];
    }

    $owned = [];
    foreach ($ids as $id) {
      $webformId = (string) $id;
      if ($webformId !== '' && $this->requestFactory->dispatchOwns($webformId)) {
        $owned[] = $webformId;
      }
    }
    sort($owned);

    return $owned;
  }

  /**
   * Records that one dispatch Webform cannot be searched for stalled quotes.
   *
   * @param string $webformId
   *   The Webform with no configured quote status element.
   * @param string[] $notices
   *   Notice keys, appended to in place. The notice is added once however many
   *   Webforms are misconfigured; the log names each one.
   */
  private function noteUnconfigured(string $webformId, array &$notices): void {
    if (!in_array(DispatchBoard::NOTICE_MANUAL_REVIEW_UNCONFIGURED, $notices, TRUE)) {
      $notices[] = DispatchBoard::NOTICE_MANUAL_REVIEW_UNCONFIGURED;
    }
    $this->logger->warning('Dispatch board: Webform @webform stores no quote status, so quotes stalled on it cannot be listed.', ['@webform' => $webformId]);
  }

  /**
   * Reduces a stored quote breakdown to reason keys that are safe to display.
   *
   * The breakdown is the quote engine's own record, not an administrative
   * projection: it can hold a price, a route, and a distance provider's raw
   * exception text. So nothing is copied out of it except reasons on the
   * allow-list, and anything else collapses to one opaque key. That is why the
   * queue needs no sanitising downstream — there is no free text left in it.
   *
   * @param mixed $breakdown
   *   The stored element value: a JSON string, an already-decoded array, or
   *   whatever else a site has put in that element.
   *
   * @return string[]
   *   Distinct reason keys in allow-list order, with REASON_OTHER last when
   *   anything unrecognised was present.
   */
  private function reasonsFor(mixed $breakdown): array {
    if (is_string($breakdown)) {
      $breakdown = $breakdown === '' ? NULL : json_decode($breakdown, TRUE);
    }
    if (!is_array($breakdown)) {
      return [];
    }

    $raw = $breakdown['manual_review_reasons'] ?? [];
    if (!is_array($raw)) {
      return [];
    }

    $known = [];
    $other = FALSE;
    foreach ($raw as $reason) {
      if (is_string($reason) && in_array($reason, self::KNOWN_REASONS, TRUE)) {
        $known[$reason] = TRUE;
        continue;
      }
      $other = TRUE;
    }

    $reasons = array_values(array_intersect(self::KNOWN_REASONS, array_keys($known)));
    if ($other) {
      $reasons[] = self::REASON_OTHER;
    }

    return $reasons;
  }

}
