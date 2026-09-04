<?php

namespace Drupal\custom_webform_handlers\Controller;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\custom_webform_handlers\Dispatch\BookingWorkflow;
use Drupal\custom_webform_handlers\Dispatch\BookingWorkflowGate;
use Drupal\custom_webform_handlers\Reservation\ReservationDefinitionProvider;
use Drupal\custom_webform_handlers\Reservation\ReservationException;
use Drupal\custom_webform_handlers\Reservation\ReservationManagerInterface;
use Drupal\custom_webform_handlers\Reservation\ReservationOwner;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Serves Availability, Hold, and Release over HTTP.
 *
 * This controller validates and throttles; it decides nothing. Every schedule
 * rule lives in ReservationDefinitionProvider and every state transition lives
 * behind ReservationManagerInterface, so a request cannot talk its way into a
 * Slot the server did not derive. Responses are deliberately thin: a code the
 * browser can act on, and never owner, submission, or row data.
 */
final class ReservationController implements ContainerInjectionInterface {

  /**
   * The largest mutation payload worth parsing.
   */
  const MAX_BODY_BYTES = 2048;

  /**
   * Availability reads per hour, per client.
   */
  const AVAILABILITY_LIMIT = 240;

  /**
   * Calendar range reads per hour, per client.
   *
   * A month grid is read on attach, on every month change, and after each hold,
   * release, and refusal, so this is higher than the single-day limit. It stays
   * bounded because each read scans a bounded range.
   */
  const CALENDAR_LIMIT = 360;

  /**
   * The largest number of local days one calendar response describes.
   *
   * A month grid needs at most 31, plus the leading and trailing days a week
   * layout shows. The manager enforces its own ceiling as well.
   */
  const CALENDAR_MAX_DAYS = 62;

  /**
   * Hold attempts per hour, per client.
   */
  const HOLD_LIMIT = 60;

  /**
   * Release attempts per hour, per client.
   */
  const RELEASE_LIMIT = 120;

  /**
   * Hold renewals per hour, per client.
   *
   * A renewal is cheap and a form may legitimately stay open for a long time, so
   * this is generous. It is still bounded, because the endpoint accepts a token
   * and must not become an unmetered oracle for guessing one.
   */
  const RENEW_LIMIT = 240;

  /**
   * The window every limit is measured over.
   */
  const WINDOW = 3600;

  public function __construct(
    private readonly ReservationManagerInterface $reservationManager,
    private readonly ReservationDefinitionProvider $definitionProvider,
    private readonly ReservationOwner $owner,
    private readonly BookingWorkflowGate $workflowGate,
    private readonly FloodInterface $flood,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
  ) {}

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('custom_webform_handlers.reservation_manager'),
      $container->get('custom_webform_handlers.reservation_definition_provider'),
      $container->get('custom_webform_handlers.reservation_owner'),
      $container->get('custom_webform_handlers.booking_workflow_gate'),
      $container->get('flood'),
      $container->get('datetime.time'),
      $container->get('logger.channel.custom_webform_handlers'),
    );
  }

  /**
   * Refuses a Webform this workflow no longer owns, or NULL to proceed.
   *
   * This is the interlock that keeps the two public Hold families apart. A
   * Webform an operator has transferred to the Dispatch Planner stops being
   * served here — no availability, no calendar, no Hold, no renewal — even though
   * its fixed-slot rows are still live and still visible to staff. Only one guard
   * may enforce for one Webform, and the transfer is what says which.
   *
   * The refusal reuses the code this controller already returns for a Webform it
   * cannot serve, at the status it already returns, so nothing here tells a
   * visitor whether a form was transferred, never configured, or does not exist.
   */
  private function refuseUnlessLegacy(string $webform): ?JsonResponse {
    if ($this->workflowGate->allows($webform, BookingWorkflow::Legacy)) {
      return NULL;
    }

    return $this->error(ReservationException::NOT_CONFIGURED, 422);
  }

  /**
   * Returns the canonical HH:MM values already taken on one day.
   */
  public function availability(Request $request, string $webform): JsonResponse {
    if (!$this->allow($request, 'availability', self::AVAILABILITY_LIMIT)) {
      return $this->error('rate_limited', 429);
    }
    // Before the day is even parsed. This endpoint's whole output is what is
    // taken and what is free, so a Webform the planner now owns must be refused
    // here rather than answered from rows the planner cannot see.
    if ($refusal = $this->refuseUnlessLegacy($webform)) {
      return $refusal;
    }

    $date = (string) $request->query->get('date', '');
    $resource = (string) $request->query->get('resource', '');
    if (!$this->isBoundedDate($date) || strlen($resource) > 128) {
      return $this->error('invalid_request', 400);
    }

    try {
      $definition = $this->definitionProvider->definition($webform, $resource);
      $day = $this->definitionProvider->day($definition, $date);
      $booked = $this->reservationManager->availability($definition, $day);
    }
    catch (ReservationException $exception) {
      return $this->error($exception->getReservationCode(), 422);
    }
    catch (\Exception) {
      return $this->error('invalid_request', 400);
    }

    // The booked list is authoritative for what is already taken: it is read
    // from the rows that hold the active slot keys. The only thing this response
    // cannot promise is the opposite direction — a Slot reported free may still
    // lose the race in hold(), which is why hold() re-derives everything and
    // relies on the unique key. No flag says otherwise, deliberately: an
    // "authoritative: false" marker here previously invited a client to discard
    // a real booked list and render taken times as selectable.
    return $this->json([
      'status' => 'success',
      'booked' => $booked,
    ]);
  }

  /**
   * Returns per-day capacity for a bounded range inside the booking horizon.
   *
   * This exists because a native date input cannot be told that a day is full.
   * The browser draws that calendar itself and will not style individual days,
   * so a grid the module renders needs one authoritative answer per day. The
   * single-day availability endpoint stays exactly as it was, for refreshing the
   * time options once a day has been chosen.
   *
   * The response carries counts and nothing else. There is no token, owner,
   * hash, submission reference, or reservation identifier anywhere in it, so a
   * visitor learns how much of a day is gone and never who took it.
   *
   * The requested range is clamped, never trusted: to today at the earliest, to
   * the configured horizon at the latest, and to a bounded number of days.
   */
  public function calendar(Request $request, string $webform): JsonResponse {
    if (!$this->allow($request, 'calendar', self::CALENDAR_LIMIT)) {
      return $this->error('rate_limited', 429);
    }
    // A month of per-day capacity is the widest disclosure this controller makes,
    // so it is gated the same way and just as early as the single-day read.
    if ($refusal = $this->refuseUnlessLegacy($webform)) {
      return $refusal;
    }

    $resource = (string) $request->query->get('resource', '');
    $requestedFrom = (string) $request->query->get('from', '');
    $requestedTo = (string) $request->query->get('to', '');
    if (strlen($resource) > 128
      || ($requestedFrom !== '' && !$this->isBoundedDate($requestedFrom))
      || ($requestedTo !== '' && !$this->isBoundedDate($requestedTo))) {
      return $this->error('invalid_request', 400);
    }

    try {
      $definition = $this->definitionProvider->definition($webform, $resource);
    }
    catch (ReservationException $exception) {
      return $this->error($exception->getReservationCode(), 422);
    }
    catch (\Exception) {
      return $this->error('invalid_request', 400);
    }

    $timezone = $definition->timezoneObject();
    // The horizon is the server's, expressed in the schedule's own timezone. A
    // browser in another zone cannot widen it by asking from its own today.
    $today = (new \DateTimeImmutable('@' . $this->time->getRequestTime()))
      ->setTimezone($timezone)
      ->setTime(0, 0, 0);
    $horizonEnd = $today->modify('+' . $definition->bookingWindowDays . ' days');

    $from = $this->clampDay($requestedFrom, $today, $today, $horizonEnd);
    $ceiling = $from->modify('+' . (self::CALENDAR_MAX_DAYS - 1) . ' days');
    if ($ceiling > $horizonEnd) {
      $ceiling = $horizonEnd;
    }
    $to = $this->clampDay($requestedTo, $ceiling, $from, $ceiling);

    return $this->json([
      'status' => 'success',
      'timezone' => $definition->timezone,
      'horizon_start' => $today->format('Y-m-d'),
      'horizon_end' => $horizonEnd->format('Y-m-d'),
      'from' => $from->format('Y-m-d'),
      'to' => $to->format('Y-m-d'),
      'configured_hours' => count($definition->timeOptions),
      'days' => $this->reservationManager->calendar($definition, $from, $to),
    ]);
  }

  /**
   * Resolves one requested day into the allowed window.
   *
   * An absent or unparseable value falls back rather than failing, because a
   * grid asking for a month that runs past the horizon is ordinary, not an
   * error. What it must never do is answer outside the window.
   */
  private function clampDay(string $requested, \DateTimeImmutable $fallback, \DateTimeImmutable $earliest, \DateTimeImmutable $latest): \DateTimeImmutable {
    if ($requested === '') {
      return $fallback;
    }

    $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $requested, $earliest->getTimezone());
    if (!$parsed || $parsed->format('Y-m-d') !== $requested) {
      return $fallback;
    }
    if ($parsed < $earliest) {
      return $earliest;
    }
    if ($parsed > $latest) {
      return $latest;
    }
    return $parsed;
  }

  /**
   * Claims one Slot for this session, or reports that it is taken.
   */
  public function hold(Request $request): JsonResponse {
    $payload = $this->payload($request);
    if ($payload instanceof JsonResponse) {
      return $payload;
    }
    if (!$this->allow($request, 'hold', self::HOLD_LIMIT)) {
      return $this->error('rate_limited', 429);
    }

    $owner = $this->owner->ownerId();
    if ($owner === '') {
      return $this->error('session_required', 400);
    }

    $webform = (string) ($payload['webform'] ?? '');
    $date = (string) ($payload['date'] ?? '');
    $time = (string) ($payload['time'] ?? '');
    $resource = (string) ($payload['resource'] ?? '');
    if (!$this->isBoundedDate($date) || strlen($time) > 8 || strlen($resource) > 128 || strlen($webform) > 128) {
      return $this->error('invalid_request', 400);
    }

    // The Webform is named in the body here, where no route requirement can see
    // it, so this is the only guard standing between a transferred Webform and a
    // second live Hold on capacity the planner is already selling. It runs before
    // the Slot is derived and therefore before anything can be claimed.
    if ($refusal = $this->refuseUnlessLegacy($webform)) {
      return $refusal;
    }

    try {
      $definition = $this->definitionProvider->definition($webform, $resource);
      $slot = $this->definitionProvider->slot($definition, $date, $time);
      $held = $this->reservationManager->hold($slot, $owner);
    }
    catch (ReservationException $exception) {
      $code = $exception->getReservationCode();
      // A lost race is the normal competitive outcome, and the only one the
      // browser must handle specially.
      $status = $code === ReservationException::SLOT_UNAVAILABLE ? 409 : 422;
      return $this->error($code, $status);
    }

    // Keep a server-side receipt for this Slot. The browser is given the token in
    // the response, but the hidden element it lands in can be replaced by a
    // Webform AJAX rebuild before the response arrives, and the Hold would then
    // be alive and owned with nothing left to present at submission.
    $this->owner->rememberHold($slot, $held->token);

    return $this->json(['status' => 'success'] + $held->toArray());
  }

  /**
   * Extends the Hold this session owns on a Slot it is still filling in.
   *
   * The Hold window is short so an abandoned Slot returns to sale quickly. That
   * window is not a limit on how long the visitor may take: a form open longer
   * than the window would otherwise lose its Slot silently and be refused at
   * submission, which is the failure this endpoint exists to prevent.
   *
   * Nothing here trusts the browser with more than it already had. The Slot is
   * re-derived from the Webform's own configuration exactly as hold() derives
   * it, the token must match, the owner must match, and the manager refuses a
   * Hold that has already lapsed. A renewal therefore cannot claim a Slot, take
   * one from another session, or revive one that was let go.
   */
  public function renew(Request $request): JsonResponse {
    $payload = $this->payload($request);
    if ($payload instanceof JsonResponse) {
      return $payload;
    }
    if (!$this->allow($request, 'renew', self::RENEW_LIMIT)) {
      return $this->error('rate_limited', 429);
    }

    $owner = $this->owner->ownerId();
    if ($owner === '') {
      return $this->error('session_required', 400);
    }

    $webform = (string) ($payload['webform'] ?? '');
    $date = (string) ($payload['date'] ?? '');
    $time = (string) ($payload['time'] ?? '');
    $resource = (string) ($payload['resource'] ?? '');
    $token = (string) ($payload['hold_token'] ?? '');
    if (!$this->isBoundedDate($date)
      || strlen($time) > 8
      || strlen($resource) > 128
      || strlen($webform) > 128
      || $token === ''
      || strlen($token) > 128) {
      return $this->error('invalid_request', 400);
    }

    // Renewing keeps capacity withheld, so it is a mutation and carries the same
    // guard as taking the Hold in the first place. A Webform transferred while a
    // form sat open stops being renewable, which is the outcome that matters: the
    // Hold lapses on its own and the planner's picture of the day becomes the only
    // one.
    if ($refusal = $this->refuseUnlessLegacy($webform)) {
      return $refusal;
    }

    try {
      $definition = $this->definitionProvider->definition($webform, $resource);
      $slot = $this->definitionProvider->slot($definition, $date, $time);
    }
    catch (ReservationException $exception) {
      return $this->error($exception->getReservationCode(), 422);
    }

    $expires = $this->reservationManager->renewHold($slot, $token, $owner);
    if ($expires === NULL) {
      // Nothing was extended, so this session does not hold this Slot: the Hold
      // lapsed, was released, was already confirmed, or never belonged here. The
      // browser must stop believing otherwise and take a fresh Hold when the
      // visitor chooses a time again.
      //
      // The server-side receipt is dropped for the same reason. Keeping it would
      // let the submission path resurrect a token for a Hold the server has just
      // said is gone, turning a clear failure now into a refusal at the end of
      // the form.
      $this->owner->forgetHold($token);
      return $this->error('hold_not_found', 404);
    }

    return $this->json([
      'status' => 'success',
      'expires_at' => $expires,
    ]);
  }

  /**
   * Gives up a Hold this session owns.
   */
  public function release(Request $request): JsonResponse {
    $payload = $this->payload($request);
    if ($payload instanceof JsonResponse) {
      return $payload;
    }
    if (!$this->allow($request, 'release', self::RELEASE_LIMIT)) {
      return $this->error('rate_limited', 429);
    }

    // Release is the one operation that cannot be decided per Webform. It carries
    // a Hold token and a session identity and nothing else, and the session
    // receipt is filed under ReservationSlot::activeSlotKey(), which is a SHA-256
    // digest and so cannot be turned back into a Webform identifier. Accepting a
    // Webform in the body would not fix that either: a caller who wanted past the
    // guard would simply name a form that passes it.
    //
    // So the question asked here is the weaker one the endpoint can actually
    // answer — is the fixed-slot workflow still enforcing for anything at all —
    // and that is sound because releasing narrows a claim rather than making one.
    // The worst a permitted release can do is hand back capacity this session
    // already held; it can never take capacity from the planner, and the manager
    // still refuses a row that is not a Hold owned by this session.
    if (!$this->workflowGate->legacyReachable()) {
      return $this->error(ReservationException::NOT_CONFIGURED, 422);
    }

    $owner = $this->owner->ownerId();
    $token = (string) ($payload['hold_token'] ?? '');
    if ($owner === '' || $token === '' || strlen($token) > 128) {
      return $this->error('invalid_request', 400);
    }

    // A confirmed Reservation can never be reached from here: the manager only
    // ever releases a row that is still a Hold and still owned by this session.
    $released = $this->reservationManager->release($token, $owner);

    // The receipt goes either way. On success the Hold is gone; on
    // hold_not_found it was already gone, so in both cases this session has
    // nothing left to present for it and must not keep a copy that outlives it.
    $this->owner->forgetHold($token);

    if (!$released) {
      return $this->error('hold_not_found', 404);
    }

    return $this->json(['status' => 'success']);
  }

  /**
   * Reads a bounded JSON body, or returns the response that refuses it.
   */
  private function payload(Request $request): array|JsonResponse {
    $body = (string) $request->getContent();
    if (strlen($body) > self::MAX_BODY_BYTES) {
      return $this->error('invalid_request', 413);
    }

    try {
      $data = json_decode($body, TRUE, 8, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      return $this->error('invalid_request', 400);
    }

    return is_array($data) ? $data : [];
  }

  /**
   * Applies the module's existing per-client flood pattern.
   */
  private function allow(Request $request, string $operation, int $limit): bool {
    $event = 'custom_webform_handlers.reservation_' . $operation;
    $identifier = hash('sha256', (string) $request->getClientIp());

    if (!$this->flood->isAllowed($event, $limit, self::WINDOW, $identifier)) {
      $this->logger->notice('Reservation @operation was rate limited.', ['@operation' => $operation]);
      return FALSE;
    }
    $this->flood->register($event, self::WINDOW, $identifier);
    return TRUE;
  }

  /**
   * Tells whether a date parameter is short enough and shaped like Y-m-d.
   */
  private function isBoundedDate(string $date): bool {
    return strlen($date) === 10 && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1;
  }

  private function error(string $code, int $status): JsonResponse {
    return $this->json(['status' => 'error', 'code' => $code], $status);
  }

  private function json(array $data, int $status = 200): JsonResponse {
    $response = new JsonResponse($data, $status);
    $response->headers->set('Cache-Control', 'no-store, private');
    $response->headers->set('Pragma', 'no-cache');
    return $response;
  }

}
