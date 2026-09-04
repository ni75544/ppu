<?php

namespace Drupal\custom_webform_handlers\Dispatch\Route;

/**
 * What one route-warming pass managed to measure, in counts only.
 *
 * Three integers, and deliberately nothing else. This object is what a queue job
 * turns into a job message, and a job message is stored on the job row and read by
 * anyone with queue access — a wider audience than the addresses have. The legs it
 * describes are other customers' pickup and delivery locations, so the report
 * carries how many there were and never which ones.
 *
 * Not an outcome anyone acts on. A warmed route is a cache entry the planner would
 * have fetched anyway on its next read; a leg that failed to warm means that read
 * pays the provider's latency instead of finding it ready. Nothing here decides
 * capacity, and a report of all failures leaves the schedule exactly as it was.
 */
final readonly class RouteWarmReport {

  /**
   * @param int $legs
   *   Distinct journeys the day required, after duplicates were collapsed.
   * @param int $warmed
   *   Journeys the provider answered for, and which are now in the route cache.
   * @param int $failed
   *   Journeys the provider could not answer for. Not an error on its own: an
   *   address the provider cannot geocode fails on every attempt and is the
   *   planner's problem to refuse, not this job's problem to retry.
   */
  public function __construct(
    public int $legs,
    public int $warmed,
    public int $failed,
  ) {}

  /**
   * Tells whether the pass achieved nothing at all despite having work.
   *
   * The one condition worth retrying. A pass where some legs warmed and some did
   * not is most likely a day containing an address the provider dislikes, which the
   * next attempt would fail on identically; a pass where every leg failed and there
   * was at least one leg to try is most likely the provider being unreachable,
   * which is exactly what a bounded retry is for.
   */
  public function isTotalFailure(): bool {
    return $this->legs > 0 && $this->warmed === 0;
  }

  /**
   * A one-line summary safe to store on a job row.
   */
  public function summary(): string {
    return sprintf('%d legs, %d warmed, %d failed', $this->legs, $this->warmed, $this->failed);
  }

}
