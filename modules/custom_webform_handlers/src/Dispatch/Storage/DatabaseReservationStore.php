<?php

namespace Drupal\custom_webform_handlers\Dispatch\Storage;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\custom_webform_handlers\Dispatch\BlackoutResult;
use Drupal\custom_webform_handlers\Dispatch\DispatchBlackout;
use Drupal\custom_webform_handlers\Dispatch\DispatchClaim;
use Drupal\custom_webform_handlers\Dispatch\DispatchClaimSummary;
use Drupal\custom_webform_handlers\Dispatch\DispatchReason;
use Drupal\custom_webform_handlers\Dispatch\DispatchReservationDetail;
use Drupal\custom_webform_handlers\Dispatch\DispatchTimelineEntry;
use Drupal\custom_webform_handlers\Dispatch\Hold;
use Drupal\custom_webform_handlers\Dispatch\HoldResult;
use Drupal\custom_webform_handlers\Dispatch\OccupiedInterval;
use Drupal\custom_webform_handlers\Dispatch\ReplanResult;
use Drupal\custom_webform_handlers\Dispatch\ReservationResult;
use Drupal\custom_webform_handlers\Dispatch\Slot;
use Psr\Log\LoggerInterface;

/**
 * The only place dispatch SQL, expiry, conflict queries, and row writes live.
 *
 * How overlap is made impossible for a capacity-one Resource:
 *
 * Every mutation runs inside ResourceCalendarGuard::withLockedDays(), which
 * upserts and then row-locks the guard for each local day the candidate touches
 * before this class reads anything. The read and the write are therefore in the
 * same transaction as the locks, so the classic failure — SELECT says free,
 * something else INSERTs, then we INSERT too — cannot occur. A contender blocks
 * on the guard rows, and when it proceeds it reads the winner's row and refuses
 * with slot_unavailable.
 *
 * Why not a unique key, as the older fixed-slot table uses: a Dispatch Window's
 * length varies with route and work time, so overlap is a range predicate rather
 * than an equality on a derived key. No single column can encode "these two
 * different start times collide", which is why the guard rows exist.
 *
 * Three intervals are checked, all against the same live set:
 *   1. the candidate's occupied Dispatch Window;
 *   2. the inbound margin, covering a previous delivery repositioning to this
 *      pickup;
 *   3. the outbound margin, covering this delivery repositioning to the next
 *      pickup or back to base.
 * Checking 2 and 3 as protected margins around the window means one range query
 * covers all three: anything intersecting the protected interval is a conflict.
 *
 * Liveness is evaluated in every conflict query rather than relying on a sweep.
 * A held row past its expiry is already free even if nothing has relabelled it,
 * so a stalled cron can never cause a double booking — only stale-looking rows.
 */
final class DatabaseReservationStore implements ReservationStoreInterface {

  /**
   * The Dispatch Reservation table.
   */
  const TABLE = 'custom_webform_handlers_dispatch_reservation';

  /**
   * The Resource Blackout table.
   */
  const BLACKOUT_TABLE = 'custom_webform_handlers_dispatch_blackout';

  /**
   * Columns that are safe to read into a value object.
   *
   * The two hashes are excluded deliberately: nothing outside this class needs
   * them, and a column that is never selected cannot be leaked into a response.
   */
  const READ_FIELDS = [
    'dispatch_reservation_id',
    'resource_id',
    'webform_id',
    'submission_id',
    'state',
    'pickup_arrival',
    'timezone',
    'window_start',
    'window_end',
    'inbound_reposition_seconds',
    'outbound_reposition_seconds',
    'expires_at',
    'created',
    'changed',
  ];

  /**
   * Columns a Replan needs from the row it supersedes.
   *
   * The price snapshot is here because it is copied forward verbatim: moving the
   * van does not requote the job. The order reference is here for the same reason —
   * moving the van does not change who paid for the job, and a Replan that dropped
   * it would leave a paid Reservation with no order to name. Neither secret hash is
   * here, because a Replan neither reads nor rewrites them — the new row is
   * confirmed from the instant it exists and has no owner to prove. The stored
   * addresses are absent on purpose: the new row records the addresses the freshly
   * measured route was measured to, which the planner supplies.
   */
  const REPLAN_FIELDS = [
    'dispatch_reservation_id',
    'resource_id',
    'webform_id',
    'submission_id',
    'state',
    'timezone',
    'window_start',
    'window_end',
    'inbound_reposition_seconds',
    'outbound_reposition_seconds',
    'price_snapshot_id',
    'price_snapshot_cents',
    'price_snapshot_currency',
    'order_id',
  ];

  /**
   * Every Blackout column. There is no secret among them.
   */
  const BLACKOUT_FIELDS = [
    'blackout_id',
    'resource_id',
    'start_at',
    'end_at',
    'reason',
    'status',
    'created',
    'changed',
  ];

  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
    private readonly ResourceCalendarGuard $guard,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function claim(DispatchClaim $claim): HoldResult {
    return $this->claimLocked($claim, NULL);
  }

  /**
   * {@inheritdoc}
   */
  public function claimWithRevalidation(DispatchClaim $claim, callable $revalidate): HoldResult {
    return $this->claimLocked($claim, $revalidate);
  }

  /**
   * Claims one Slot inside the transaction, optionally re-proving the plan first.
   *
   * One body for both claim paths, so the locking order, the legacy-day rule, the
   * conflict reads and the insert cannot drift apart between them. The only
   * difference is whether the planner is asked to re-derive its plan while the
   * guard rows are held.
   */
  private function claimLocked(DispatchClaim $claim, ?callable $revalidate): HoldResult {
    $resourceId = $claim->slot->resourceId;
    $protectedStart = $claim->protectedStart();
    $protectedEnd = $claim->protectedEnd();

    if ($protectedEnd <= $protectedStart || $claim->window->endTimestamp <= $claim->window->startTimestamp) {
      // A window that does not move forward cannot be reasoned about at all.
      return HoldResult::refused(DispatchReason::InvalidRequest);
    }

    return $this->guard->withLockedDays(
      $resourceId,
      $claim->guardDates(),
      function (array $guards) use ($claim, $resourceId, $protectedStart, $protectedEnd, $revalidate): HoldResult {
        // A day carrying an unproven legacy claim is closed. The older table has
        // no route data, so nothing can prove the day is free; withholding it is
        // the only safe answer short of inventing a route.
        foreach ($guards as $date => $row) {
          if ((int) $row->legacy_unplanned === 1) {
            $this->logger->notice('Dispatch claim refused: Resource @resource day @date is legacy-unplanned.', [
              '@resource' => $resourceId,
              '@date' => $date,
            ]);
            return HoldResult::refused(DispatchReason::LegacyUnplanned);
          }
        }

        $now = $this->time->getRequestTime();

        if ($this->hasReservationConflict($resourceId, $protectedStart, $protectedEnd, $now)) {
          return HoldResult::refused(DispatchReason::SlotUnavailable);
        }
        if ($this->hasBlackoutConflict($resourceId, $protectedStart, $protectedEnd)) {
          return HoldResult::refused(DispatchReason::Blackout);
        }

        // The interval checks above prove nothing overlaps the span this claim
        // would protect. They cannot prove the span is still the right span: the
        // exact Repositioning it encodes was derived from neighbours read before
        // the locks were taken. Re-deriving it here, under the locks, is what
        // makes the figures written the figures the day actually supports.
        if ($revalidate !== NULL) {
          $reason = $revalidate();
          if ($reason !== DispatchReason::Available) {
            $this->logger->notice('Dispatch claim refused on locked revalidation for Resource @resource: @reason.', [
              '@resource' => $resourceId,
              '@reason' => $reason->value,
            ]);
            return HoldResult::refused($reason);
          }
        }

        $token = bin2hex(random_bytes(32));
        $expires = $now + max(1, $claim->holdSeconds);

        $this->database->insert(self::TABLE)
          ->fields([
            'resource_id' => $resourceId,
            'webform_id' => $claim->slot->webformId,
            'submission_id' => NULL,
            'state' => self::STATE_HELD,
            'pickup_arrival' => $claim->slot->startTimestamp,
            'timezone' => $claim->slot->timezone,
            'window_start' => $claim->window->startTimestamp,
            'window_end' => $claim->window->endTimestamp,
            'inbound_reposition_seconds' => max(0, $claim->inboundRepositionSeconds),
            'outbound_reposition_seconds' => max(0, $claim->outboundRepositionSeconds),
            'pickup_location' => $claim->pickupLocation,
            'delivery_location' => $claim->deliveryLocation,
            'route_seconds' => $claim->routeSeconds,
            'route_meters' => $claim->routeMeters,
            'price_snapshot_id' => $claim->priceSnapshotId,
            'price_snapshot_cents' => $claim->priceSnapshotCents,
            'price_snapshot_currency' => $claim->priceSnapshotCurrency,
            'hold_token_hash' => $this->hash($token),
            'owner_hash' => $this->hash($claim->ownerId),
            'expires_at' => $expires,
            'created' => $now,
            'changed' => $now,
          ])
          ->execute();

        return HoldResult::held(new Hold($claim->slot, $token, $expires));
      }
    );
  }

  /**
   * {@inheritdoc}
   */
  public function confirm(string $rawHoldToken, string $rawOwnerId, int $submissionId): ReservationResult {
    if (trim($rawHoldToken) === '' || trim($rawOwnerId) === '') {
      return ReservationResult::refused(DispatchReason::HoldNotFound);
    }

    $row = $this->database->select(self::TABLE, 'r')
      ->fields('r', self::READ_FIELDS)
      ->condition('r.hold_token_hash', $this->hash($rawHoldToken))
      ->condition('r.owner_hash', $this->hash($rawOwnerId))
      ->range(0, 1)
      ->execute()
      ->fetchObject();

    if (!$row) {
      return ReservationResult::refused(DispatchReason::HoldNotFound);
    }

    // Already confirmed for this same submission: a repeated postSave must not
    // read as a failure.
    if ($row->state === self::STATE_CONFIRMED && (int) $row->submission_id === $submissionId) {
      try {
        return ReservationResult::confirmed(
          (string) $row->dispatch_reservation_id,
          $this->slotFromRow($row),
          (int) $row->window_start,
          (int) $row->window_end,
        );
      }
      catch (StoredSlotException) {
        return ReservationResult::refused(DispatchReason::InvalidRequest);
      }
    }

    $resourceId = (string) $row->resource_id;
    $dates = $this->datesForRow($row);

    return $this->guard->withLockedDays(
      $resourceId,
      $dates,
      function () use ($rawHoldToken, $rawOwnerId, $submissionId): ReservationResult {
        $now = $this->time->getRequestTime();

        // Re-read under the lock. Between the read above and this point the Hold
        // may have lapsed or been released, so the row that matters is this one.
        $locked = $this->database->select(self::TABLE, 'r')
          ->fields('r', self::READ_FIELDS)
          ->condition('r.hold_token_hash', $this->hash($rawHoldToken))
          ->condition('r.owner_hash', $this->hash($rawOwnerId))
          ->condition('r.state', self::STATE_HELD)
          ->condition('r.expires_at', $now, '>')
          ->range(0, 1)
          ->execute()
          ->fetchObject();

        if (!$locked) {
          return ReservationResult::refused(DispatchReason::HoldNotFound);
        }

        $updated = $this->database->update(self::TABLE)
          ->fields([
            'state' => self::STATE_CONFIRMED,
            'submission_id' => $submissionId,
            // A Reservation does not expire; only an administrative release or a
            // successful Replan ends it.
            'expires_at' => NULL,
            'changed' => $now,
          ])
          ->condition('dispatch_reservation_id', (int) $locked->dispatch_reservation_id)
          ->condition('state', self::STATE_HELD)
          ->execute();

        if ((int) $updated === 0) {
          return ReservationResult::refused(DispatchReason::HoldNotFound);
        }

        try {
          return ReservationResult::confirmed(
            (string) $locked->dispatch_reservation_id,
            $this->slotFromRow($locked),
            (int) $locked->window_start,
            (int) $locked->window_end,
          );
        }
        catch (StoredSlotException $exception) {
          // The Reservation exists and holds its capacity; only its local
          // reading is untrustworthy. Report the refusal rather than hand back a
          // Slot with a substituted zone.
          $this->logger->error('Dispatch Reservation @id was confirmed but its stored timezone is unreadable: @message', [
            '@id' => (int) $locked->dispatch_reservation_id,
            '@message' => $exception->getMessage(),
          ]);
          return ReservationResult::refused(DispatchReason::InvalidRequest);
        }
      }
    );
  }

  /**
   * {@inheritdoc}
   */
  public function confirmWithRevalidation(string $rawHoldToken, string $rawOwnerId, int $submissionId, callable $revalidate): ReservationResult {
    if (trim($rawHoldToken) === '' || trim($rawOwnerId) === '' || $submissionId < 1) {
      return ReservationResult::refused(DispatchReason::HoldNotFound);
    }

    // Unlocked, and used for one purpose only: deciding which local days to lock.
    // Nothing is decided from it. Every fact that matters is re-read below, inside
    // the transaction, after the guards are held.
    $row = $this->database->select(self::TABLE, 'r')
      ->fields('r', self::READ_FIELDS)
      ->condition('r.hold_token_hash', $this->hash($rawHoldToken))
      ->condition('r.owner_hash', $this->hash($rawOwnerId))
      ->range(0, 1)
      ->execute()
      ->fetchObject();

    if (!$row) {
      return ReservationResult::refused(DispatchReason::HoldNotFound);
    }

    // Already confirmed against this same submission. A repeated postSave, a queued
    // retry, or a re-fired save must read as success, and must not re-run the
    // revalidation: the Reservation exists and re-proving it could only refuse
    // capacity that has already been granted.
    if ($row->state === self::STATE_CONFIRMED && (int) $row->submission_id === $submissionId) {
      try {
        return ReservationResult::confirmed(
          (string) $row->dispatch_reservation_id,
          $this->slotFromRow($row),
          (int) $row->window_start,
          (int) $row->window_end,
        );
      }
      catch (StoredSlotException) {
        return ReservationResult::refused(DispatchReason::InvalidRequest);
      }
    }

    return $this->guard->withLockedDays(
      (string) $row->resource_id,
      $this->datesForRow($row),
      function () use ($rawHoldToken, $rawOwnerId, $submissionId, $revalidate): ReservationResult {
        $now = $this->time->getRequestTime();

        // The authoritative read. A Hold that lapsed, was released, was confirmed
        // against another submission, or never belonged to this owner is simply not
        // here, and no reason beyond hold_not_found is disclosed about it.
        $locked = $this->database->select(self::TABLE, 'r')
          ->fields('r', self::READ_FIELDS)
          ->condition('r.hold_token_hash', $this->hash($rawHoldToken))
          ->condition('r.owner_hash', $this->hash($rawOwnerId))
          ->condition('r.state', self::STATE_HELD)
          ->condition('r.expires_at', $now, '>')
          ->range(0, 1)
          ->execute()
          ->fetchObject();

        if (!$locked) {
          return ReservationResult::refused(DispatchReason::HoldNotFound);
        }

        try {
          $slot = $this->slotFromRow($locked);
        }
        catch (StoredSlotException $exception) {
          $this->logger->error('Dispatch Hold @id cannot be read as a Slot, so it cannot be confirmed: @message', [
            '@id' => (int) $locked->dispatch_reservation_id,
            '@message' => $exception->getMessage(),
          ]);
          return ReservationResult::refused(DispatchReason::InvalidRequest);
        }

        // The planner re-proves the plan here, with the guards still held.
        $reason = $revalidate(new LockedHold(
          (int) $locked->dispatch_reservation_id,
          $slot,
          (int) $locked->window_start,
          (int) $locked->window_end,
          (int) $locked->inbound_reposition_seconds,
          (int) $locked->outbound_reposition_seconds,
          (string) $locked->webform_id,
          (int) $locked->expires_at,
        ));

        if (!$reason instanceof DispatchReason) {
          // A callback that did not answer has not granted anything. Treating an
          // unusable answer as approval is the one mistake this seam must not make.
          $this->logger->error('Dispatch revalidation returned no usable reason, so Hold @id was not confirmed.', [
            '@id' => (int) $locked->dispatch_reservation_id,
          ]);
          return ReservationResult::refused(DispatchReason::InvalidRequest);
        }

        if ($reason !== DispatchReason::Available) {
          $this->logger->notice('Dispatch Hold @id failed revalidation at confirmation: @reason.', [
            '@id' => (int) $locked->dispatch_reservation_id,
            '@reason' => $reason->value,
          ]);
          // Nothing was written, so capacity is exactly as it was. The Hold keeps
          // its remaining life rather than being consumed by a failed attempt.
          return ReservationResult::refused($reason);
        }

        $updated = $this->database->update(self::TABLE)
          ->fields([
            'state' => self::STATE_CONFIRMED,
            'submission_id' => $submissionId,
            // A Reservation does not expire; only an administrative release or a
            // successful Replan ends it.
            'expires_at' => NULL,
            'changed' => $now,
          ])
          ->condition('dispatch_reservation_id', (int) $locked->dispatch_reservation_id)
          ->condition('state', self::STATE_HELD)
          ->execute();

        if ((int) $updated === 0) {
          return ReservationResult::refused(DispatchReason::HoldNotFound);
        }

        // The update above changed state, submission and expiry and left the window
        // exactly as the Hold proved it, so the locked row still holds the instants
        // that were written. They travel back with the result because a caller that
        // has to say what was promised must not have to ask the database again.
        return ReservationResult::confirmed(
          (string) $locked->dispatch_reservation_id,
          $slot,
          (int) $locked->window_start,
          (int) $locked->window_end,
        );
      }
    );
  }

  /**
   * {@inheritdoc}
   */
  public function replan(DispatchReplan $replan, callable $revalidate): ReplanResult {
    if ($replan->reservationId <= 0 || $replan->submissionId <= 0) {
      return ReplanResult::refused(DispatchReason::InvalidRequest);
    }

    $protectedStart = $replan->protectedStart();
    $protectedEnd = $replan->protectedEnd();
    if ($protectedEnd <= $protectedStart || $replan->window->endTimestamp <= $replan->window->startTimestamp) {
      return ReplanResult::refused(DispatchReason::InvalidRequest);
    }

    // Read before the locks, and only to learn which local days the row occupies
    // now. Nothing is decided on it: the authoritative re-read happens under the
    // locks, and the locked day set is checked against that re-read before
    // anything is written, so a row that moved in the meantime is refused rather
    // than written with one of its days unguarded.
    $existing = $this->database->select(self::TABLE, 'r')
      ->fields('r', self::REPLAN_FIELDS)
      ->condition('r.dispatch_reservation_id', $replan->reservationId)
      ->condition('r.state', self::STATE_CONFIRMED)
      ->range(0, 1)
      ->execute()
      ->fetchObject();

    if (!$existing) {
      return ReplanResult::refused(DispatchReason::ReservationNotFound);
    }
    if ((string) $existing->resource_id !== $replan->slot->resourceId) {
      // One call cannot serialize two Resources, and half a move is worse than
      // none. Moving work to another vehicle is a release and a fresh booking.
      return ReplanResult::refused(DispatchReason::InvalidRequest);
    }

    return $this->guard->withLockedDays(
      $replan->slot->resourceId,
      array_merge($this->datesForRow($existing), $replan->guardDates()),
      function (array $guards) use ($replan, $protectedStart, $protectedEnd, $revalidate): ReplanResult {
        $resourceId = $replan->slot->resourceId;

        // A day carrying an unproven legacy claim is closed to planning here for
        // the same reason it is closed to a first claim: nothing in the older
        // table can prove the day is free, and a Replan is still a plan.
        foreach ($guards as $date => $guard) {
          if ((int) $guard->legacy_unplanned === 1) {
            $this->logger->notice('Dispatch Replan refused: Resource @resource day @date is legacy-unplanned.', [
              '@resource' => $resourceId,
              '@date' => $date,
            ]);
            return ReplanResult::refused(DispatchReason::LegacyUnplanned);
          }
        }

        $current = $this->database->select(self::TABLE, 'r')
          ->fields('r', self::REPLAN_FIELDS)
          ->condition('r.dispatch_reservation_id', $replan->reservationId)
          ->range(0, 1)
          ->execute()
          ->fetchObject();

        if (!$current || (string) $current->state !== self::STATE_CONFIRMED) {
          // Released, cancelled, or already superseded by a move that reached the
          // row first. There is nothing here to move.
          return ReplanResult::refused(DispatchReason::ReservationNotFound);
        }
        if ((int) $current->submission_id !== $replan->submissionId
          || (string) $current->resource_id !== $resourceId
          || (string) $current->webform_id !== $replan->slot->webformId) {
          // Not the row the planner proved a plan for. Writing this plan onto a
          // different job is the one outcome worse than refusing.
          return ReplanResult::refused(DispatchReason::InvalidRequest);
        }

        // The locked set was chosen from the pre-transaction read. If the row has
        // moved since, one of the days it currently occupies may not be held, and
        // vacating an unserialized day is exactly the gap a competing claim slips
        // into. Refuse; a retry reads the row where it now is.
        foreach ($this->datesForRow($current) as $date) {
          if (!array_key_exists($date, $guards)) {
            return ReplanResult::refused(DispatchReason::SlotUnavailable);
          }
        }

        $now = $this->time->getRequestTime();

        // Every other live claim and every active Blackout still applies. This
        // claim alone is excluded, because a job always overlaps the space it is
        // being moved out of and would otherwise conflict with itself.
        if ($this->hasReservationConflict($resourceId, $protectedStart, $protectedEnd, $now, $replan->reservationId)) {
          return ReplanResult::refused(DispatchReason::SlotUnavailable);
        }
        if ($this->hasBlackoutConflict($resourceId, $protectedStart, $protectedEnd)) {
          return ReplanResult::refused(DispatchReason::Blackout);
        }

        $reason = $revalidate();
        if ($reason !== DispatchReason::Available) {
          $this->logger->notice('Dispatch Replan refused on locked revalidation for Reservation @id: @reason.', [
            '@id' => $replan->reservationId,
            '@reason' => $reason->value,
          ]);
          return ReplanResult::refused($reason);
        }

        // Supersede, then insert, both while these days are held. No contender can
        // enter the gap between the two statements, because it would have to take
        // guard rows this transaction owns. Conditioning the update on the state
        // means a race that reached the row first stops the move with nothing
        // written rather than producing two confirmed rows for one job.
        $superseded = (int) $this->database->update(self::TABLE)
          ->fields([
            'state' => self::STATE_REPLANNED,
            'expires_at' => NULL,
            'changed' => $now,
          ])
          ->condition('dispatch_reservation_id', $replan->reservationId)
          ->condition('state', self::STATE_CONFIRMED)
          ->execute();

        if ($superseded < 1) {
          return ReplanResult::refused(DispatchReason::ReservationNotFound);
        }

        $newId = (int) $this->database->insert(self::TABLE)
          ->fields([
            'resource_id' => $resourceId,
            'webform_id' => $replan->slot->webformId,
            'submission_id' => $replan->submissionId,
            // Confirmed from the instant it exists: this replaces a Reservation,
            // not a Hold, so there is no owner to prove and nothing to expire.
            'state' => self::STATE_CONFIRMED,
            'pickup_arrival' => $replan->slot->startTimestamp,
            'timezone' => $replan->slot->timezone,
            'window_start' => $replan->window->startTimestamp,
            'window_end' => $replan->window->endTimestamp,
            'inbound_reposition_seconds' => max(0, $replan->inboundRepositionSeconds),
            'outbound_reposition_seconds' => max(0, $replan->outboundRepositionSeconds),
            'pickup_location' => $replan->pickupLocation,
            'delivery_location' => $replan->deliveryLocation,
            'route_seconds' => $replan->routeSeconds,
            'route_meters' => $replan->routeMeters,
            // Copied verbatim, never recalculated. What the customer was quoted
            // does not change because the van comes at a different hour.
            'price_snapshot_id' => $current->price_snapshot_id,
            'price_snapshot_cents' => $current->price_snapshot_cents,
            'price_snapshot_currency' => $current->price_snapshot_currency,
            // Carried forward for the same reason. The order that paid for this job
            // paid for the job, not for the hour it was first planned at.
            'order_id' => $current->order_id,
            'hold_token_hash' => NULL,
            'owner_hash' => NULL,
            'expires_at' => NULL,
            'created' => $now,
            'changed' => $now,
          ])
          ->execute();

        $this->logger->notice('Dispatch Reservation @old replanned as @new on Resource @resource.', [
          '@old' => $replan->reservationId,
          '@new' => $newId,
          '@resource' => $resourceId,
        ]);

        return ReplanResult::moved($newId, $replan->reservationId, $replan->slot);
      }
    );
  }

  /**
   * {@inheritdoc}
   */
  public function release(string $rawHoldToken, string $rawOwnerId): bool {
    if (trim($rawHoldToken) === '' || trim($rawOwnerId) === '') {
      return FALSE;
    }

    $released = $this->database->update(self::TABLE)
      ->fields([
        'state' => self::STATE_RELEASED,
        // The claim is over, so the secrets have no further purpose and keeping
        // them would leave a session fingerprint on a historical row.
        'hold_token_hash' => NULL,
        'owner_hash' => NULL,
        'expires_at' => NULL,
        'changed' => $this->time->getRequestTime(),
      ])
      ->condition('hold_token_hash', $this->hash($rawHoldToken))
      ->condition('owner_hash', $this->hash($rawOwnerId))
      // Only a Hold. A confirmed Reservation is released administratively.
      ->condition('state', self::STATE_HELD)
      ->execute();

    return (int) $released > 0;
  }

  /**
   * {@inheritdoc}
   */
  public function renew(string $rawHoldToken, string $rawOwnerId, int $holdSeconds): ?int {
    if (trim($rawHoldToken) === '' || trim($rawOwnerId) === '') {
      return NULL;
    }

    $now = $this->time->getRequestTime();
    $expires = $now + max(1, $holdSeconds);

    // One statement, and never a read followed by a write. The conditions that
    // decide whether the Hold still stands are the conditions the update matches
    // on, so a Hold that lapses in between cannot be extended. Nothing about the
    // occupied window changes, so no guard lock is needed: this cannot create or
    // move a claim, only postpone the end of one that already exists.
    $renewed = $this->database->update(self::TABLE)
      ->fields([
        'expires_at' => $expires,
        'changed' => $now,
      ])
      ->condition('hold_token_hash', $this->hash($rawHoldToken))
      ->condition('owner_hash', $this->hash($rawOwnerId))
      ->condition('state', self::STATE_HELD)
      ->condition('expires_at', $now, '>')
      ->execute();

    return (int) $renewed > 0 ? $expires : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function expireDue(int $now): int {
    $expired = $this->database->update(self::TABLE)
      ->fields([
        'state' => self::STATE_EXPIRED,
        'hold_token_hash' => NULL,
        'owner_hash' => NULL,
        'changed' => $now,
      ])
      ->condition('state', self::STATE_HELD)
      ->condition('expires_at', $now, '<=')
      ->execute();

    $expired = (int) $expired;
    if ($expired > 0) {
      $this->logger->info('Expired @count dispatch hold(s).', ['@count' => $expired]);
    }
    return $expired;
  }

  /**
   * {@inheritdoc}
   */
  public function occupiedIntervals(string $resourceId, int $from, int $to, ?int $excludeReservationId = NULL): array {
    $now = $this->time->getRequestTime();
    $intervals = [];

    $query = $this->database->select(self::TABLE, 'r')
      ->fields('r', ['state', 'window_start', 'window_end', 'inbound_reposition_seconds', 'outbound_reposition_seconds']);
    $query->condition('r.resource_id', $resourceId);
    if ($excludeReservationId !== NULL) {
      // Revalidating one claim must not let that claim's own reserved space refuse
      // it. Every other live claim still applies, and so does every Blackout.
      $query->condition('r.dispatch_reservation_id', $excludeReservationId, '<>');
    }
    // Protected margins are included in the reported interval, so a caller
    // building availability sees the space a claim really needs rather than only
    // the window a customer would recognise.
    $query->where('(r.window_start - r.inbound_reposition_seconds) < :to', [':to' => $to]);
    $query->where('(r.window_end + r.outbound_reposition_seconds) > :from', [':from' => $from]);
    $this->conditionOnLive($query, $now);
    $query->orderBy('r.window_start');

    foreach ($query->execute() as $row) {
      $intervals[] = new OccupiedInterval(
        $resourceId,
        (int) $row->window_start - (int) $row->inbound_reposition_seconds,
        (int) $row->window_end + (int) $row->outbound_reposition_seconds,
        $row->state === self::STATE_CONFIRMED ? OccupiedInterval::SOURCE_CONFIRMED : OccupiedInterval::SOURCE_HELD,
      );
    }

    $blackouts = $this->database->select(self::BLACKOUT_TABLE, 'b')
      ->fields('b', ['start_at', 'end_at', 'reason'])
      ->condition('b.resource_id', $resourceId)
      ->condition('b.status', 1)
      ->condition('b.start_at', $to, '<')
      ->condition('b.end_at', $from, '>')
      ->orderBy('b.start_at')
      ->execute();

    foreach ($blackouts as $row) {
      $intervals[] = new OccupiedInterval(
        $resourceId,
        (int) $row->start_at,
        (int) $row->end_at,
        OccupiedInterval::SOURCE_BLACKOUT,
        (string) $row->reason,
      );
    }

    usort($intervals, static fn (OccupiedInterval $a, OccupiedInterval $b): int => $a->startTimestamp <=> $b->startTimestamp);

    return $intervals;
  }

  /**
   * {@inheritdoc}
   */
  public function planningNeighbours(string $resourceId, int $from, int $to, ?int $excludeReservationId = NULL): array {
    $now = $this->time->getRequestTime();

    $query = $this->database->select(self::TABLE, 'r')
      ->fields('r', ['window_start', 'window_end', 'pickup_location', 'delivery_location']);
    $query->condition('r.resource_id', $resourceId);
    if ($excludeReservationId !== NULL) {
      $query->condition('r.dispatch_reservation_id', $excludeReservationId, '<>');
    }
    // Matched on the Dispatch Windows themselves, not on the padded intervals used
    // for conflict detection. A neighbour is something the driver physically does,
    // and the margins around it are the space this planner is about to compute.
    $query->condition('r.window_start', $to, '<');
    $query->condition('r.window_end', $from, '>');
    $this->conditionOnLive($query, $now);
    $query->orderBy('r.window_start');

    $neighbours = [];
    foreach ($query->execute() as $row) {
      $neighbours[] = new PlanningNeighbour(
        (int) $row->window_start,
        (int) $row->window_end,
        trim((string) $row->pickup_location),
        trim((string) $row->delivery_location),
      );
    }

    return $neighbours;
  }

  /**
   * {@inheritdoc}
   */
  public function adminTimeline(string $resourceId, int $from, int $to): array {
    // An unusable request is answered with nothing. Left to the query, an
    // inverted range would not narrow the result — it would match any interval
    // long enough to straddle both ends, so the board would show an
    // authoritative-looking answer to a question nobody asked.
    if ($resourceId === '' || $to <= $from) {
      return [];
    }

    $now = $this->time->getRequestTime();
    $entries = [];

    $query = $this->database->select(self::TABLE, 'r')
      ->fields('r', [
        'dispatch_reservation_id',
        'webform_id',
        'submission_id',
        'state',
        'pickup_arrival',
        'timezone',
        'window_start',
        'window_end',
        'inbound_reposition_seconds',
        'outbound_reposition_seconds',
        'expires_at',
      ]);
    // Never hold_token_hash and never owner_hash. The secrets are not selected
    // here, so nothing downstream can leak what it was never handed.
    $query->condition('r.resource_id', $resourceId);
    // Matched on the padded interval, exactly as occupiedIntervals() matches it,
    // so the board shows every claim whose reserved space touches the range.
    $query->where('(r.window_start - r.inbound_reposition_seconds) < :to', [':to' => $to]);
    $query->where('(r.window_end + r.outbound_reposition_seconds) > :from', [':from' => $from]);
    // The same liveness rule the planner reads by: an expired Hold has stopped
    // consuming capacity, so it is not on the board either.
    $this->conditionOnLive($query, $now);
    $query->orderBy('r.window_start');

    foreach ($query->execute() as $row) {
      if ($row->state === self::STATE_CONFIRMED) {
        $entries[] = DispatchTimelineEntry::reservation(
          $resourceId,
          (string) $row->state,
          (int) $row->dispatch_reservation_id,
          (string) $row->webform_id,
          $row->submission_id === NULL ? NULL : (int) $row->submission_id,
          (int) $row->pickup_arrival,
          (string) $row->timezone,
          (int) $row->window_start,
          (int) $row->window_end,
          (int) $row->inbound_reposition_seconds,
          (int) $row->outbound_reposition_seconds,
        );
        continue;
      }

      // A Hold is reported as one opaque blocked span, repositioning folded in,
      // plus the moment it lapses. That is the whole of what an administrator
      // needs from an unfinished booking, and the whole of what it may disclose.
      $entries[] = DispatchTimelineEntry::hold(
        $resourceId,
        (string) $row->state,
        (int) $row->window_start - (int) $row->inbound_reposition_seconds,
        (int) $row->window_end + (int) $row->outbound_reposition_seconds,
        $row->expires_at === NULL ? NULL : (int) $row->expires_at,
      );
    }

    $blackouts = $this->database->select(self::BLACKOUT_TABLE, 'b')
      ->fields('b', ['start_at', 'end_at', 'reason'])
      ->condition('b.resource_id', $resourceId)
      ->condition('b.status', 1)
      ->condition('b.start_at', $to, '<')
      ->condition('b.end_at', $from, '>')
      ->orderBy('b.start_at')
      ->execute();

    foreach ($blackouts as $row) {
      $entries[] = DispatchTimelineEntry::blackout(
        $resourceId,
        (int) $row->start_at,
        (int) $row->end_at,
        (string) $row->reason,
      );
    }

    // Ordered by the time each segment actually removes from the Resource, so a
    // lane can be drawn straight from this list. The end is a tie-break only, to
    // keep two segments that begin together in a stable order.
    usort($entries, static fn (DispatchTimelineEntry $a, DispatchTimelineEntry $b): int => [$a->occupiedStart(), $a->occupiedEnd()] <=> [$b->occupiedStart(), $b->occupiedEnd()]);

    return $entries;
  }

  /**
   * {@inheritdoc}
   */
  public function adminReservation(int $dispatchReservationId): ?DispatchReservationDetail {
    if ($dispatchReservationId <= 0) {
      return NULL;
    }

    $row = $this->database->select(self::TABLE, 'r')
      ->fields('r', [
        'dispatch_reservation_id',
        'resource_id',
        'state',
        'webform_id',
        'submission_id',
        'pickup_arrival',
        'timezone',
        'window_start',
        'window_end',
        'inbound_reposition_seconds',
        'outbound_reposition_seconds',
        'pickup_location',
        'delivery_location',
        'route_seconds',
        'route_meters',
        'price_snapshot_id',
        'price_snapshot_cents',
        'price_snapshot_currency',
        'created',
        'changed',
      ])
      // Confirmed only, decided in SQL. A Hold and a missing row are therefore
      // the same answer here, which is the answer a Hold deserves: it is an
      // unfinished attempt with no detail anyone is entitled to read.
      ->condition('r.dispatch_reservation_id', $dispatchReservationId)
      ->condition('r.state', self::STATE_CONFIRMED)
      ->execute()
      ->fetchObject();

    if (!$row) {
      return NULL;
    }

    // Snapshots are passed through verbatim. This is what was true when the
    // claim was made, and the detail page exists to show exactly that.
    return new DispatchReservationDetail(
      (int) $row->dispatch_reservation_id,
      (string) $row->resource_id,
      (string) $row->state,
      (string) $row->webform_id,
      $row->submission_id === NULL ? NULL : (int) $row->submission_id,
      (int) $row->pickup_arrival,
      (string) $row->timezone,
      (int) $row->window_start,
      (int) $row->window_end,
      (int) $row->inbound_reposition_seconds,
      (int) $row->outbound_reposition_seconds,
      trim((string) $row->pickup_location),
      trim((string) $row->delivery_location),
      $row->route_seconds === NULL ? NULL : (int) $row->route_seconds,
      $row->route_meters === NULL ? NULL : (int) $row->route_meters,
      $row->price_snapshot_id === NULL ? NULL : (string) $row->price_snapshot_id,
      $row->price_snapshot_cents === NULL ? NULL : (int) $row->price_snapshot_cents,
      $row->price_snapshot_currency === NULL ? NULL : (string) $row->price_snapshot_currency,
      (int) $row->created,
      (int) $row->changed,
    );
  }

  /**
   * {@inheritdoc}
   */
  public function legacyUnplannedDates(string $resourceId, string $fromDate, string $toDate): array {
    return $this->guard->legacyUnplannedDates($resourceId, $fromDate, $toDate);
  }

  /**
   * {@inheritdoc}
   */
  public function adminClaim(int $dispatchReservationId): ?DispatchClaimSummary {
    if ($dispatchReservationId <= 0) {
      return NULL;
    }

    // READ_FIELDS carries neither hash, so no query on this path can select one.
    $row = $this->database->select(self::TABLE, 'r')
      ->fields('r', self::READ_FIELDS)
      ->condition('r.dispatch_reservation_id', $dispatchReservationId)
      ->range(0, 1)
      ->execute()
      ->fetchObject();

    if (!$row) {
      return NULL;
    }

    $state = (string) $row->state;
    $occupiedStart = (int) $row->window_start - (int) $row->inbound_reposition_seconds;
    $occupiedEnd = (int) $row->window_end + (int) $row->outbound_reposition_seconds;

    if ($state === self::STATE_CONFIRMED) {
      return DispatchClaimSummary::reservation(
        (int) $row->dispatch_reservation_id,
        (string) $row->resource_id,
        $occupiedStart,
        $occupiedEnd,
        (string) $row->timezone,
        (int) $row->pickup_arrival,
        (int) $row->window_start,
        (int) $row->window_end,
        (string) $row->webform_id,
        $row->submission_id === NULL ? NULL : (int) $row->submission_id,
      );
    }

    // Released, cancelled, expired and superseded rows are history: they hold no
    // capacity, so there is nothing left to do to them, and offering one for
    // release would offer an operation that cannot happen.
    if ($state !== self::STATE_HELD) {
      return NULL;
    }

    // A lapsed Hold is already free even if nothing has relabelled it yet, and
    // this seam answers about capacity rather than about rows.
    $expires = $row->expires_at === NULL ? 0 : (int) $row->expires_at;
    if ($expires <= $this->time->getRequestTime()) {
      return NULL;
    }

    // The hold() factory takes no Webform, submission, window or arrival, so the
    // impoverishment of a Hold is structural rather than remembered here.
    return DispatchClaimSummary::hold(
      (int) $row->dispatch_reservation_id,
      (string) $row->resource_id,
      $occupiedStart,
      $occupiedEnd,
      (string) $row->timezone,
      $expires,
    );
  }

  /**
   * {@inheritdoc}
   */
  public function adminRelease(int $dispatchReservationId, string $expectedState): ?string {
    if ($dispatchReservationId <= 0) {
      return NULL;
    }

    $target = match ($expectedState) {
      self::STATE_HELD => self::STATE_RELEASED,
      self::STATE_CONFIRMED => self::STATE_CANCELLED,
      default => NULL,
    };
    if ($target === NULL) {
      // Only a live claim holds capacity to free. Any other expected state names a
      // row that is already history, so the caller is working from a stale page.
      return NULL;
    }

    $row = $this->database->select(self::TABLE, 'r')
      ->fields('r', self::READ_FIELDS)
      ->condition('r.dispatch_reservation_id', $dispatchReservationId)
      ->condition('r.state', $expectedState)
      ->range(0, 1)
      ->execute()
      ->fetchObject();

    if (!$row) {
      return NULL;
    }

    return $this->guard->withLockedDays(
      (string) $row->resource_id,
      $this->datesForRow($row),
      function () use ($dispatchReservationId, $expectedState, $target): ?string {
        // Releasing only ever frees space, so it needs no lock for its own sake.
        // The locks are here so that a release and a Replan of the same row cannot
        // both believe they won: whichever takes the guard rows second reads what
        // the first wrote, and the state condition below then settles it.
        $freed = (int) $this->database->update(self::TABLE)
          ->fields([
            'state' => $target,
            // The claim is over, so the secrets have no further purpose, and
            // keeping them would leave a session fingerprint on a historical row.
            'hold_token_hash' => NULL,
            'owner_hash' => NULL,
            'expires_at' => NULL,
            'changed' => $this->time->getRequestTime(),
          ])
          ->condition('dispatch_reservation_id', $dispatchReservationId)
          ->condition('state', $expectedState)
          ->execute();

        if ($freed < 1) {
          return NULL;
        }

        $this->logger->notice('Dispatch claim @id freed administratively from @was to @state.', [
          '@id' => $dispatchReservationId,
          '@was' => $expectedState,
          '@state' => $target,
        ]);

        return $target;
      }
    );
  }

  /**
   * Rebuilds a Blackout from one row and the Resource's own zone.
   *
   * The zone is a parameter because the Blackout table records instants and a
   * Resource, never a zone. Which zone those instants are read in is a Resource
   * Profile fact, and the Profile is configuration this seam does not read.
   */
  private function blackoutFromRow(object $row, string $timezone): DispatchBlackout {
    return new DispatchBlackout(
      (int) $row->blackout_id,
      (string) $row->resource_id,
      (int) $row->start_at,
      (int) $row->end_at,
      (string) $row->reason,
      (int) $row->status === 1,
      $timezone,
      (int) $row->created,
      (int) $row->changed,
    );
  }

  /**
   * {@inheritdoc}
   */
  public function addBlackout(string $resourceId, int $startAt, int $endAt, string $reason): int {
    if ($endAt <= $startAt) {
      throw new \InvalidArgumentException('A Resource Blackout must end after it starts.');
    }

    $now = $this->time->getRequestTime();
    $id = (int) $this->database->insert(self::BLACKOUT_TABLE)
      ->fields([
        'resource_id' => $resourceId,
        'start_at' => $startAt,
        'end_at' => $endAt,
        'reason' => mb_substr($reason, 0, 255),
        'status' => 1,
        'created' => $now,
        'changed' => $now,
      ])
      ->execute();

    $this->logger->notice('Added Resource Blackout @id for @resource.', [
      '@id' => $id,
      '@resource' => $resourceId,
    ]);

    return $id;
  }

  /**
   * {@inheritdoc}
   */
  public function withdrawBlackout(int $blackoutId): bool {
    $withdrawn = $this->database->update(self::BLACKOUT_TABLE)
      ->fields([
        'status' => 0,
        'changed' => $this->time->getRequestTime(),
      ])
      ->condition('blackout_id', $blackoutId)
      ->condition('status', 1)
      ->execute();

    return (int) $withdrawn > 0;
  }

  /**
   * {@inheritdoc}
   */
  public function scheduleBlackoutGuarded(
    string $resourceId,
    int $startAt,
    int $endAt,
    string $reason,
    string $timezone,
    ?int $replacesBlackoutId = NULL,
  ): BlackoutResult {
    if (trim($resourceId) === '' || $endAt <= $startAt) {
      return BlackoutResult::refused(DispatchReason::InvalidRequest);
    }

    return $this->guard->withLockedDays(
      $resourceId,
      $this->datesForInterval($startAt, $endAt, $timezone),
      function () use ($resourceId, $startAt, $endAt, $reason, $replacesBlackoutId): BlackoutResult {
        $now = $this->time->getRequestTime();

        // The conflict read comes before either write. A refusal returned after a
        // withdrawal would commit that withdrawal, leaving the Resource open in a
        // span the operator has been told is closed.
        $conflicts = $this->conflictingReservationIds($resourceId, $startAt, $endAt, $now);
        if ($conflicts !== []) {
          return BlackoutResult::refused(DispatchReason::SlotUnavailable, $conflicts);
        }

        $withdrew = NULL;
        if ($replacesBlackoutId !== NULL && $replacesBlackoutId > 0) {
          // Scoped to this Resource so one operator's identifier cannot withdraw
          // another Resource's closure. The revised row may lie on days outside
          // the locked set, which is harmless: withdrawing a closure only returns
          // capacity, and nothing schedules against a row while it is being freed.
          $revised = (int) $this->database->update(self::BLACKOUT_TABLE)
            ->fields(['status' => 0, 'changed' => $now])
            ->condition('blackout_id', $replacesBlackoutId)
            ->condition('resource_id', $resourceId)
            ->condition('status', 1)
            ->execute();

          if ($revised < 1) {
            // Somebody withdrew or revised it while this form was open. Inserting
            // anyway would add a second closure beside the one being replaced.
            return BlackoutResult::refused(DispatchReason::InvalidRequest);
          }
          $withdrew = $replacesBlackoutId;
        }

        $id = (int) $this->database->insert(self::BLACKOUT_TABLE)
          ->fields([
            'resource_id' => $resourceId,
            'start_at' => $startAt,
            'end_at' => $endAt,
            'reason' => mb_substr($reason, 0, 255),
            'status' => 1,
            'created' => $now,
            'changed' => $now,
          ])
          ->execute();

        $this->logger->notice('Resource Blackout @id scheduled for @resource@revision.', [
          '@id' => $id,
          '@resource' => $resourceId,
          '@revision' => $withdrew === NULL ? '' : ' revising ' . $withdrew,
        ]);

        return BlackoutResult::scheduled($id, $withdrew);
      }
    );
  }

  /**
   * {@inheritdoc}
   */
  public function loadBlackout(int $blackoutId, string $timezone): ?DispatchBlackout {
    if ($blackoutId <= 0) {
      return NULL;
    }

    $row = $this->database->select(self::BLACKOUT_TABLE, 'b')
      ->fields('b', self::BLACKOUT_FIELDS)
      ->condition('b.blackout_id', $blackoutId)
      ->range(0, 1)
      ->execute()
      ->fetchObject();

    return $row ? $this->blackoutFromRow($row, $timezone) : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function listBlackouts(string $resourceId, int $from, int $to, string $timezone): array {
    if (trim($resourceId) === '' || $to <= $from) {
      // An unusable range yields nothing, and is never widened to every Resource
      // or to a span the caller did not ask for.
      return [];
    }

    $rows = $this->database->select(self::BLACKOUT_TABLE, 'b')
      ->fields('b', self::BLACKOUT_FIELDS)
      ->condition('b.resource_id', $resourceId)
      ->condition('b.status', 1)
      ->condition('b.start_at', $to, '<')
      ->condition('b.end_at', $from, '>')
      ->orderBy('b.start_at')
      ->execute();

    $blackouts = [];
    foreach ($rows as $row) {
      $blackouts[] = $this->blackoutFromRow($row, $timezone);
    }

    return $blackouts;
  }

  /**
   * {@inheritdoc}
   */
  public function slotForReservation(int $dispatchReservationId): Slot {
    $row = $this->database->select(self::TABLE, 'r')
      ->fields('r', self::READ_FIELDS)
      ->condition('r.dispatch_reservation_id', $dispatchReservationId)
      ->range(0, 1)
      ->execute()
      ->fetchObject();

    if (!$row) {
      throw new StoredSlotException(sprintf('Dispatch Reservation %d does not exist.', $dispatchReservationId));
    }

    return $this->slotFromRow($row);
  }

  /**
   * {@inheritdoc}
   */
  public function liveClaimForSubmission(int $submissionId): ?int {
    if ($submissionId <= 0) {
      return NULL;
    }

    $query = $this->database->select(self::TABLE, 'r');
    $query->addField('r', 'dispatch_reservation_id');
    $query->condition('r.submission_id', $submissionId);
    $this->conditionOnLive($query, $this->time->getRequestTime());
    // Oldest first, so the answer names the claim that got there first rather than
    // whichever the database happened to return.
    $query->orderBy('r.dispatch_reservation_id');
    $query->range(0, 1);

    $id = $query->execute()->fetchField();

    return $id === FALSE || $id === NULL ? NULL : (int) $id;
  }

  /**
   * {@inheritdoc}
   */
  public function linkOrder(int $dispatchReservationId, int $orderId): bool {
    if ($dispatchReservationId < 1 || $orderId < 1) {
      return FALSE;
    }

    $row = $this->database->select(self::TABLE, 'r')
      ->fields('r', ['dispatch_reservation_id', 'order_id'])
      ->condition('r.dispatch_reservation_id', $dispatchReservationId)
      ->condition('r.state', self::STATE_CONFIRMED)
      ->range(0, 1)
      ->execute()
      ->fetchObject();

    if ($row === FALSE || $row === NULL) {
      // No confirmed row. A Hold is not linked to an order: capacity that is only
      // held has not been paid for, and labelling it as though it had would make a
      // pending checkout look like a booking on the Dispatch board.
      return FALSE;
    }

    // Read as a whole row rather than as one field, because an unlinked row and a
    // missing row both return NULL from fetchField() and they are opposite answers.
    $existing = $row->order_id === NULL ? 0 : (int) $row->order_id;

    if ($existing === $orderId) {
      // Already recorded. A repeated payment notification arrives here, and the
      // honest answer is that the row already names this order.
      return TRUE;
    }

    if ($existing > 0) {
      $this->logger->error('Dispatch Reservation @id already names order @existing; order @order was refused.', [
        '@id' => $dispatchReservationId,
        '@existing' => $existing,
        '@order' => $orderId,
      ]);
      return FALSE;
    }

    $linked = (int) $this->database->update(self::TABLE)
      ->fields([
        'order_id' => $orderId,
        'changed' => $this->time->getRequestTime(),
      ])
      ->condition('dispatch_reservation_id', $dispatchReservationId)
      ->condition('state', self::STATE_CONFIRMED)
      ->isNull('order_id')
      ->execute();

    return $linked > 0;
  }

  /**
   * Tells whether any live claim intrudes into a protected interval.
   *
   * @param int|null $excludeReservationId
   *   A claim to ignore. Set only when that claim is the one being re-proved or
   *   moved, where counting it would make it conflict with the space it already
   *   occupies. Never set to make room for anything else.
   */
  private function hasReservationConflict(string $resourceId, int $protectedStart, int $protectedEnd, int $now, ?int $excludeReservationId = NULL): bool {
    $query = $this->database->select(self::TABLE, 'r');
    $query->addExpression('1', 'conflict');
    $query->condition('r.resource_id', $resourceId);
    // Half-open overlap on both sides, so a claim that ends exactly when this
    // one's protected interval begins is contiguous rather than conflicting. The
    // existing row's own margins count too: its repositioning needs the road as
    // much as ours does.
    $query->where('(r.window_start - r.inbound_reposition_seconds) < :protected_end', [':protected_end' => $protectedEnd]);
    $query->where('(r.window_end + r.outbound_reposition_seconds) > :protected_start', [':protected_start' => $protectedStart]);
    if ($excludeReservationId !== NULL && $excludeReservationId > 0) {
      $query->condition('r.dispatch_reservation_id', $excludeReservationId, '<>');
    }
    $this->conditionOnLive($query, $now);
    $query->range(0, 1);

    return (bool) $query->execute()->fetchField();
  }

  /**
   * Names the live claims intruding into an interval, for an operator message.
   *
   * The counterpart to hasReservationConflict() for the Blackout path, which has
   * to tell staff which bookings are in the way so they can release or replan them
   * first. Identifiers only, and identifiers the board already shows: no address,
   * no customer, no submission, nothing this seam would not otherwise disclose.
   *
   * @return int[]
   *   Ascending identifiers, bounded so a wide span cannot build an unbounded
   *   message out of a badly chosen closure.
   */
  private function conflictingReservationIds(string $resourceId, int $protectedStart, int $protectedEnd, int $now): array {
    $query = $this->database->select(self::TABLE, 'r');
    $query->addField('r', 'dispatch_reservation_id');
    $query->condition('r.resource_id', $resourceId);
    $query->where('(r.window_start - r.inbound_reposition_seconds) < :protected_end', [':protected_end' => $protectedEnd]);
    $query->where('(r.window_end + r.outbound_reposition_seconds) > :protected_start', [':protected_start' => $protectedStart]);
    $this->conditionOnLive($query, $now);
    $query->orderBy('r.window_start');
    $query->range(0, 50);

    return array_map('intval', $query->execute()->fetchCol());
  }

  /**
   * Tells whether an active Blackout intrudes into a protected interval.
   */
  private function hasBlackoutConflict(string $resourceId, int $protectedStart, int $protectedEnd): bool {
    $query = $this->database->select(self::BLACKOUT_TABLE, 'b');
    $query->addExpression('1', 'conflict');
    $query->condition('b.resource_id', $resourceId);
    $query->condition('b.status', 1);
    $query->condition('b.start_at', $protectedEnd, '<');
    $query->condition('b.end_at', $protectedStart, '>');
    $query->range(0, 1);

    return (bool) $query->execute()->fetchField();
  }

  /**
   * Restricts a query to claims that still consume capacity.
   *
   * Active means confirmed, or held and not yet past its expiry. Released,
   * expired, and cancelled rows are history and consume nothing. Evaluating
   * expiry here rather than trusting a sweep is what makes a stalled cron
   * harmless.
   */
  private function conditionOnLive(SelectInterface $query, int $now): void {
    $live = $query->orConditionGroup()
      ->condition('r.state', self::STATE_CONFIRMED)
      ->condition($query->andConditionGroup()
        ->condition('r.state', self::STATE_HELD)
        ->condition('r.expires_at', $now, '>'));
    $query->condition($live);
  }

  /**
   * Returns the local dates one stored row's protected interval touches.
   *
   * Falls back to UTC dates only for the purpose of choosing which guard rows to
   * lock, and never for reading a pickup time. Over-locking is harmless; the
   * worst case is that a contender waits on a day it did not need.
   *
   * @return string[]
   */
  private function datesForRow(object $row): array {
    return $this->datesForInterval(
      (int) $row->window_start - (int) $row->inbound_reposition_seconds,
      (int) $row->window_end + (int) $row->outbound_reposition_seconds,
      (string) $row->timezone,
    );
  }

  /**
   * Returns the local dates a UTC interval touches in one zone.
   *
   * Shared by the claim rows, which carry their own stored zone, and by Blackouts,
   * which carry none and are read in the Resource's zone the caller supplies.
   *
   * @return string[]
   */
  private function datesForInterval(int $start, int $end, string $timezone): array {
    try {
      $zone = new \DateTimeZone($timezone);
    }
    catch (\Exception) {
      $zone = new \DateTimeZone('UTC');
    }

    $cursor = (new \DateTimeImmutable('@' . $start))->setTimezone($zone)->setTime(0, 0, 0);
    $last = (new \DateTimeImmutable('@' . max($start, $end)))->setTimezone($zone)->setTime(0, 0, 0);

    $dates = [];
    for ($i = 0; $i <= 370 && $cursor <= $last; $i++) {
      $dates[] = $cursor->format('Y-m-d');
      $cursor = $cursor->modify('+1 day');
    }

    return $dates;
  }

  /**
   * Rebuilds a Slot from one row, refusing an unrecognised stored timezone.
   *
   * @throws \Drupal\custom_webform_handlers\Dispatch\Storage\StoredSlotException
   */
  private function slotFromRow(object $row): Slot {
    try {
      return new Slot(
        (string) $row->webform_id,
        (string) $row->resource_id,
        (int) $row->pickup_arrival,
        (string) $row->timezone,
      );
    }
    catch (\InvalidArgumentException $exception) {
      throw new StoredSlotException(sprintf(
        'Dispatch Reservation %d cannot be read as a Slot: %s',
        (int) $row->dispatch_reservation_id,
        $exception->getMessage()
      ), 0, $exception);
    }
  }

  /**
   * Hashes a secret so no raw token or owner identifier is ever stored.
   */
  private function hash(string $value): string {
    return hash('sha256', $value);
  }

}
