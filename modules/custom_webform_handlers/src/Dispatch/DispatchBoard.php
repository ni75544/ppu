<?php

namespace Drupal\custom_webform_handlers\Dispatch;

use Drupal\custom_webform_handlers\Dispatch\Notify\NotificationRecord;
use Drupal\custom_webform_handlers\Dispatch\Status\ResourceStatus;

/**
 * Everything an administrative Dispatch board may know, and nothing more.
 *
 * The read model the board is rendered from. It is assembled once, by the board
 * reader, and is finished by the time a controller sees it: there is nothing left
 * to query, so a template cannot reach back through it into the database.
 *
 * What it deliberately does not carry: no Webform or submission entity, no
 * database row, no Hold token, no owner hash, and no PlanningNeighbour. The last
 * one matters most. Neighbour addresses belong to other customers and exist only
 * so the planner can measure repositioning; the board answers a different
 * question and has no business holding them.
 *
 * Two integrations are carried alongside the schedule and are not part of it. The
 * reported Resource Status says what a human observed; the notification history says
 * what was told to whom. Neither proves capacity — the timeline does that — and the
 * board holds them side by side precisely so an operator can see the two disagree.
 * The notification records carry no recipient address and no message body, so putting
 * them on a page discloses nothing a customer wrote.
 *
 * The Resource Profile is carried as-is. It is an immutable value object built
 * from configuration — capacity, grid, working hours, breaks, buffers, timezone —
 * so passing it through is how the board states its calendar facts without
 * copying them into a second shape that could drift.
 *
 * Two states. An available board describes one Resource over a bounded local
 * range. An unavailable one carries a notice and nothing else, which is how a
 * missing profile, an unknown Resource, or an unusable range is reported: the
 * board goes empty rather than silently widening to something answerable.
 *
 * Immutable.
 */
final class DispatchBoard {

  /**
   * Configuration yields no Resource Profile that can be planned against.
   */
  public const NOTICE_NO_PROFILE = 'no_resource_profile';

  /**
   * The requested Resource is not the configured one.
   */
  public const NOTICE_UNKNOWN_RESOURCE = 'unknown_resource';

  /**
   * The requested range ends before it starts.
   */
  public const NOTICE_INVALID_RANGE = 'invalid_range';

  /**
   * The request asked for more than a week and was cut to seven local days.
   */
  public const NOTICE_RANGE_CLAMPED = 'range_clamped_to_seven_days';

  /**
   * More Manual Review work exists than the bounded scan could examine.
   */
  public const NOTICE_MANUAL_REVIEW_TRUNCATED = 'manual_review_queue_truncated';

  /**
   * A dispatch Webform stores no quote status, so its stalls cannot be found.
   */
  public const NOTICE_MANUAL_REVIEW_UNCONFIGURED = 'manual_review_status_element_missing';

  /**
   * Local calendar days one board may span.
   */
  public const MAX_LOCAL_DAYS = 7;

  /**
   * Constructs a board. Use forResource() or unavailable().
   *
   * @param \Drupal\custom_webform_handlers\Dispatch\ResourceProfile|null $resource
   *   The Resource this board describes, or NULL when none can be described.
   * @param int $generatedAt
   *   When the board was assembled, in UTC. Hold expiry moves without any cache
   *   tag being invalidated, so a rendered board is only true as of this moment.
   * @param int $rangeStart
   *   Start of the covered range in UTC: local midnight of the first day.
   * @param int $rangeEnd
   *   End of the covered range in UTC, exclusive: local midnight after the last.
   * @param string[] $localDates
   *   The Y-m-d local dates the board covers, ascending.
   * @param \Drupal\custom_webform_handlers\Dispatch\DispatchTimelineEntry[] $timeline
   *   Safe timeline segments, ascending by the time they occupy.
   * @param string[] $legacyUnplannedDates
   *   Local dates closed to self-booking by an unproven legacy claim.
   * @param \Drupal\custom_webform_handlers\Dispatch\DispatchManualReviewEntry[] $manualReview
   *   The unscheduled queue. Consumes no capacity and belongs on no lane.
   * @param \Drupal\custom_webform_handlers\Dispatch\Status\ResourceStatus|null $resourceStatus
   *   The latest report about the Resource, or NULL when nothing has been reported.
   *   NULL is not Available: it means nobody has said, and the board must not turn
   *   an absence of reports into an assertion that the vehicle is ready.
   * @param \Drupal\custom_webform_handlers\Dispatch\Status\ResourceStatus[] $statusHistory
   *   Recent reports, newest first. A correction is a newer report, so the history is
   *   the only place the earlier claim survives.
   * @param \Drupal\custom_webform_handlers\Dispatch\Notify\NotificationRecord[] $notifications
   *   Recent notification attempts, newest first. Outcomes and identifiers only: no
   *   recipient address and no message body are ever carried here.
   * @param int $notificationsNeedingAttention
   *   How many recent attempts did not reach anybody. Counted over the same window the
   *   history covers, so a board showing no failures is saying something bounded.
   * @param string[] $notices
   *   Machine notice keys from the NOTICE_* constants, for the caller to phrase.
   */
  private function __construct(
    public readonly ?ResourceProfile $resource,
    public readonly int $generatedAt,
    public readonly int $rangeStart,
    public readonly int $rangeEnd,
    public readonly array $localDates,
    public readonly array $timeline,
    public readonly array $legacyUnplannedDates,
    public readonly array $manualReview,
    public readonly ?ResourceStatus $resourceStatus,
    public readonly array $statusHistory,
    public readonly array $notifications,
    public readonly int $notificationsNeedingAttention,
    public readonly array $notices,
  ) {
  }

  /**
   * Builds the board for one Resource over one bounded local range.
   *
   * The collections are filtered by type on the way in. Only this class can say
   * what a board is allowed to contain, so it refuses anything that is not a safe
   * value object rather than trusting every future caller to have been careful.
   *
   * That filtering is why the two integrations can be carried at all. A Resource Status
   * and a NotificationRecord are both built by a store that already dropped what it
   * would not vouch for, and anything else offered here is discarded rather than
   * rendered.
   */
  public static function forResource(
    ResourceProfile $resource,
    int $generatedAt,
    int $rangeStart,
    int $rangeEnd,
    array $localDates,
    array $timeline,
    array $legacyUnplannedDates,
    array $manualReview,
    ?ResourceStatus $resourceStatus,
    array $statusHistory,
    array $notifications,
    int $notificationsNeedingAttention,
    array $notices
  ): self {
    return new self(
      $resource,
      $generatedAt,
      $rangeStart,
      $rangeEnd,
      self::strings($localDates),
      array_values(array_filter($timeline, static fn ($entry): bool => $entry instanceof DispatchTimelineEntry)),
      self::strings($legacyUnplannedDates),
      array_values(array_filter($manualReview, static fn ($entry): bool => $entry instanceof DispatchManualReviewEntry)),
      $resourceStatus,
      array_values(array_filter($statusHistory, static fn ($entry): bool => $entry instanceof ResourceStatus)),
      array_values(array_filter($notifications, static fn ($entry): bool => $entry instanceof NotificationRecord)),
      max(0, $notificationsNeedingAttention),
      self::strings($notices),
    );
  }

  /**
   * Builds a board that describes nothing, and says why.
   *
   * The empty state is deliberate. When the Resource, the profile, or the range
   * cannot be trusted, the board reports a notice and covers no time at all,
   * rather than falling back to a default Resource or a default range and showing
   * an administrator a schedule they did not ask for.
   *
   * The integrations go empty with everything else. A board that cannot name its
   * Resource must not report a status or a message history, because both would be
   * about some Resource the caller never asked about.
   *
   * @param int $generatedAt
   *   When the board was assembled, in UTC.
   * @param string $notice
   *   One of the NOTICE_* constants.
   */
  public static function unavailable(int $generatedAt, string $notice): self {
    return new self(NULL, $generatedAt, 0, 0, [], [], [], [], NULL, [], [], 0, [$notice]);
  }

  /**
   * Whether this board describes a Resource.
   */
  public function isAvailable(): bool {
    return $this->resource !== NULL;
  }

  /**
   * Whether the board carries one particular notice.
   */
  public function hasNotice(string $notice): bool {
    return in_array($notice, $this->notices, TRUE);
  }

  /**
   * Reduces a list to its plain string members, re-indexed.
   *
   * Dates and notice keys reach a template, so anything that is not already a
   * string is dropped instead of being coerced into one.
   *
   * @param array $values
   *   Candidate values.
   *
   * @return string[]
   *   The string members, in order.
   */
  private static function strings(array $values): array {
    return array_values(array_filter($values, static fn ($value): bool => is_string($value)));
  }

}
