<?php

namespace Drupal\custom_webform_handlers\Controller;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\custom_webform_handlers\Dispatch\BookingOutcome;
use Drupal\custom_webform_handlers\Dispatch\BookingWorkflow;
use Drupal\custom_webform_handlers\Dispatch\BookingWorkflowGate;
use Drupal\custom_webform_handlers\Dispatch\DispatchBooking;
use Drupal\custom_webform_handlers\Dispatch\DispatchReason;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Serves the four dispatch booking operations over HTTP.
 *
 * This controller parses, bounds, and throttles. It decides nothing: every
 * schedule rule, every feasibility check, and every state transition lives behind
 * DispatchBooking, which in turn keeps the planner and the storage seam apart. A
 * request cannot argue its way into a Slot the server did not derive, because the
 * only thing it gets to name is an instant.
 *
 * All four are POST, including the read. Offering depends on the job — weight,
 * mode, access, add-ons — and mid-form those answers are unsaved, so they travel in
 * the body rather than in a query string that would be logged, cached by
 * intermediaries, and length-limited. Being a POST also means all four carry the
 * request-header CSRF token under one consistent rule.
 *
 * What comes back is what DispatchBooking chose to publish. This class never sees a
 * hold token, an owner identifier, a Dispatch Window, or another customer's address,
 * so no response can leak one.
 */
final class DispatchController implements ContainerInjectionInterface {

  /**
   * The largest body worth parsing on an endpoint carrying element values.
   *
   * Offer and Hold receive the visitor's current answers, which is a whole form's
   * worth of values rather than a couple of identifiers.
   */
  const MAX_VALUES_BODY_BYTES = 16384;

  /**
   * The largest body worth parsing on an endpoint that only names a Hold.
   */
  const MAX_BODY_BYTES = 512;

  /**
   * The largest number of element values one request may carry.
   */
  const MAX_VALUES = 200;

  /**
   * Offer reads per hour, per client.
   *
   * Higher than the mutating limits: a visitor legitimately re-reads availability
   * on every date change and after every refusal. Still bounded, because each read
   * plans a bounded day and issues route lookups to do it.
   */
  const OFFER_LIMIT = 240;

  /**
   * Hold attempts per hour, per client.
   */
  const HOLD_LIMIT = 60;

  /**
   * Renewals per hour, per client.
   *
   * A form may legitimately stay open a long time, and losing a Slot silently
   * because of a stingy renewal budget is the failure the endpoint exists to
   * prevent. Cheap, so generous.
   */
  const RENEW_LIMIT = 240;

  /**
   * Releases per hour, per client.
   */
  const RELEASE_LIMIT = 120;

  /**
   * The window every limit is measured over.
   */
  const WINDOW = 3600;

  public function __construct(
    private readonly DispatchBooking $booking,
    private readonly BookingWorkflowGate $workflowGate,
    private readonly FloodInterface $flood,
    private readonly LoggerInterface $logger,
  ) {}

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('custom_webform_handlers.dispatch_booking'),
      $container->get('custom_webform_handlers.booking_workflow_gate'),
      $container->get('flood'),
      $container->get('logger.channel.custom_webform_handlers'),
    );
  }

  /**
   * Refuses this Webform when the planner does not own it, or NULL to proceed.
   *
   * The route access checker asks the same authority the same question earlier,
   * and this is deliberately not a substitute for it but a repeat of it: the
   * router's answer depends on a route declaration, and an interlock that stops
   * two workflows from selling the same capacity should not be one YAML edit away
   * from being switched off.
   *
   * The refusal is the one the planner already gives for a Webform it does not
   * serve, byte for byte. A Webform still owned by the fixed-slot workflow, one
   * whose dispatch configuration is incomplete, and one that does not exist are
   * therefore indistinguishable from here, so this endpoint cannot be used to read
   * back how a Webform is configured.
   */
  private function refuseUnlessDispatch(string $webform): ?JsonResponse {
    if ($this->workflowGate->allows($webform, BookingWorkflow::Dispatch)) {
      return NULL;
    }

    return $this->error(DispatchReason::NotConfigured->value, $this->status(DispatchReason::NotConfigured));
  }

  /**
   * Returns the selectable pickup arrivals on one local date.
   */
  public function offer(Request $request, string $webform): JsonResponse {
    $payload = $this->payload($request, self::MAX_VALUES_BODY_BYTES);
    if ($payload instanceof JsonResponse) {
      return $payload;
    }
    if (!$this->allow($request, 'offer', self::OFFER_LIMIT)) {
      return $this->error('rate_limited', 429);
    }
    if ($refusal = $this->refuseUnlessDispatch($webform)) {
      return $refusal;
    }

    $date = (string) ($payload['date'] ?? '');
    if (strlen($date) !== 10) {
      return $this->error('invalid_request', 400);
    }

    $values = $this->boundedValues($payload['values'] ?? NULL);
    if ($values === NULL) {
      return $this->error('invalid_request', 400);
    }

    return $this->respond($this->booking->offer($webform, $values, $date));
  }

  /**
   * Claims one pickup arrival for this session.
   */
  public function hold(Request $request, string $webform): JsonResponse {
    $payload = $this->payload($request, self::MAX_VALUES_BODY_BYTES);
    if ($payload instanceof JsonResponse) {
      return $payload;
    }
    if (!$this->allow($request, 'hold', self::HOLD_LIMIT)) {
      return $this->error('rate_limited', 429);
    }
    if ($refusal = $this->refuseUnlessDispatch($webform)) {
      return $refusal;
    }

    $at = $this->pickupArrival($payload);
    if ($at === NULL) {
      return $this->error('invalid_request', 400);
    }

    $values = $this->boundedValues($payload['values'] ?? NULL);
    if ($values === NULL) {
      return $this->error('invalid_request', 400);
    }

    return $this->respond($this->booking->hold($webform, $values, $at));
  }

  /**
   * Extends the Hold this session took on one pickup arrival.
   *
   * There is no token parameter, on purpose. The Hold is named by the instant it
   * was taken for, and the token is recovered from the session receipt the server
   * issued — so this endpoint cannot be used to present a token, and therefore
   * cannot be used to guess one either.
   */
  public function renew(Request $request, string $webform): JsonResponse {
    $payload = $this->payload($request, self::MAX_BODY_BYTES);
    if ($payload instanceof JsonResponse) {
      return $payload;
    }
    if (!$this->allow($request, 'renew', self::RENEW_LIMIT)) {
      return $this->error('rate_limited', 429);
    }
    if ($refusal = $this->refuseUnlessDispatch($webform)) {
      return $refusal;
    }

    $at = $this->pickupArrival($payload);
    if ($at === NULL) {
      return $this->error('invalid_request', 400);
    }

    return $this->respond($this->booking->renew($webform, $at));
  }

  /**
   * Gives up the Hold this session took on one pickup arrival.
   */
  public function release(Request $request, string $webform): JsonResponse {
    $payload = $this->payload($request, self::MAX_BODY_BYTES);
    if ($payload instanceof JsonResponse) {
      return $payload;
    }
    if (!$this->allow($request, 'release', self::RELEASE_LIMIT)) {
      return $this->error('rate_limited', 429);
    }
    if ($refusal = $this->refuseUnlessDispatch($webform)) {
      return $refusal;
    }

    $at = $this->pickupArrival($payload);
    if ($at === NULL) {
      return $this->error('invalid_request', 400);
    }

    return $this->respond($this->booking->release($webform, $at));
  }

  /**
   * Turns a booking outcome into a response, reason and status together.
   */
  private function respond(BookingOutcome $outcome): JsonResponse {
    if ($outcome->ok) {
      return $this->json(['status' => 'success'] + $outcome->data);
    }

    return $this->error($outcome->reason->value, $this->status($outcome->reason));
  }

  /**
   * Maps a refusal to the status that describes what the client should do.
   */
  private function status(DispatchReason $reason): int {
    return match ($reason) {
      // Lost the race. The one outcome a browser must handle specially, because
      // re-reading availability and picking again is the correct response.
      DispatchReason::SlotUnavailable => 409,
      // This session has no Hold for that instant. Said identically whether the
      // Slot is held by somebody else or by nobody, so the endpoint reveals
      // nothing about who holds what.
      DispatchReason::HoldNotFound => 404,
      // Dispatch does not serve this Webform — not enabled, half-configured, or
      // still owned by the fixed-slot guard. Indistinguishable between those, so
      // the response is not a probe for how a Webform is configured.
      DispatchReason::NotConfigured => 404,
      DispatchReason::InvalidRequest => 400,
      // Understood, and refused for an operational reason: manual review, weight,
      // working hours, a break, a blackout, the horizon, repositioning, return to
      // base. The request was well-formed; the answer is no.
      default => 422,
    };
  }

  /**
   * Reads the pickup-arrival instant a request names, or NULL.
   *
   * An absolute UTC timestamp, so no client-side timezone can shift which instant
   * is meant. Bounded to a sane epoch range purely to reject nonsense early; the
   * planner decides whether the instant is on-grid, in hours, and in horizon.
   */
  private function pickupArrival(array $payload): ?int {
    $at = $payload['at'] ?? NULL;
    if (!is_int($at) && !(is_string($at) && preg_match('/^\d{1,12}$/', $at) === 1)) {
      return NULL;
    }
    $at = (int) $at;

    return $at > 0 ? $at : NULL;
  }

  /**
   * Bounds the submitted element values, or NULL when they are not usable.
   *
   * Element keys and scalars only, plus the flat lists a multi-value element
   * produces. Nesting deeper than that is not something a Webform submission looks
   * like, and normalising it would mean guessing what the visitor answered.
   *
   * @return array|null
   *   The accepted values, or NULL to refuse the request.
   */
  private function boundedValues(mixed $values): ?array {
    if ($values === NULL) {
      return [];
    }
    if (!is_array($values) || count($values) > self::MAX_VALUES) {
      return NULL;
    }

    $bounded = [];
    foreach ($values as $key => $value) {
      if (!is_string($key) || preg_match('/^[a-z0-9_]{1,128}$/', $key) !== 1) {
        continue;
      }
      if (is_scalar($value)) {
        $bounded[$key] = $value;
        continue;
      }
      if (!is_array($value) || count($value) > self::MAX_VALUES) {
        continue;
      }
      $list = [];
      foreach ($value as $item) {
        if (is_scalar($item)) {
          $list[] = $item;
        }
      }
      $bounded[$key] = $list;
    }

    return $bounded;
  }

  /**
   * Reads a bounded JSON body, or returns the response that refuses it.
   */
  private function payload(Request $request, int $maxBytes): array|JsonResponse {
    $body = (string) $request->getContent();
    if (strlen($body) > $maxBytes) {
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
    $event = 'custom_webform_handlers.dispatch_' . $operation;
    $identifier = hash('sha256', (string) $request->getClientIp());

    if (!$this->flood->isAllowed($event, $limit, self::WINDOW, $identifier)) {
      $this->logger->notice('Dispatch @operation was rate limited.', ['@operation' => $operation]);
      return FALSE;
    }
    $this->flood->register($event, self::WINDOW, $identifier);

    return TRUE;
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
