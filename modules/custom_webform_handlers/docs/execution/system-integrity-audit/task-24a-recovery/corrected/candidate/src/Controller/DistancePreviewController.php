<?php

namespace Drupal\custom_webform_handlers\Controller;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\custom_webform_handlers\Distance\DistanceProviderException;
use Drupal\custom_webform_handlers\Distance\DistanceProviderInterface;
use Drupal\custom_webform_handlers\Distance\DistanceResult;
use Drupal\custom_webform_handlers\Plugin\WebformHandler\AddressDistanceHandler;
use Drupal\custom_webform_handlers\Quote\QuoteCalculator;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Returns a throttled, non-authoritative distance preview.
 */
final class DistancePreviewController implements ContainerInjectionInterface {

  /**
   * Constructs the distance preview controller.
   */
  public function __construct(
    private readonly DistanceProviderInterface $distanceProvider,
    private readonly FloodInterface $flood,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerInterface $logger,
    private readonly QuoteCalculator $quoteCalculator,
    private readonly TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('custom_webform_handlers.customer_route_entry_provider'),
      $container->get('flood'),
      $container->get('config.factory'),
      $container->get('logger.channel.custom_webform_handlers'),
      $container->get('custom_webform_handlers.quote_calculator'),
      $container->get('datetime.time'),
    );
  }

  /**
   * Returns a throttled preliminary quote for one address pair.
   */
  public function preview(Request $request): JsonResponse {
    if (strlen((string) $request->getContent()) > 4096) {
      return $this->response(['status' => 'error', 'code' => 'invalid_request'], 413);
    }

    try {
      $data = json_decode((string) $request->getContent(), TRUE, 16, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      return $this->response(['status' => 'error', 'code' => 'invalid_request'], 400);
    }

    $origin = $this->normalizeAddressText((string) ($data['origin'] ?? ''));
    $destination = $this->normalizeAddressText((string) ($data['destination'] ?? ''));
    if ($origin === '' || $destination === '' || mb_strlen($origin) > 500 || mb_strlen($destination) > 500) {
      return $this->response(['status' => 'error', 'code' => 'invalid_address'], 400);
    }

    $config = $this->configFactory->get('custom_webform_handlers.settings');
    $limit = max(5, min(120, (int) ($config->get('preview_rate_limit') ?: 30)));
    $identifier = hash('sha256', (string) $request->getClientIp());
    $event = 'custom_webform_handlers.distance_preview';
    if (!$this->flood->isAllowed($event, $limit, 3600, $identifier)) {
      return $this->response(['status' => 'error', 'code' => 'rate_limited'], 429);
    }
    $this->flood->register($event, 3600, $identifier);

    try {
      $result = $this->reusableDistance($data, $origin, $destination)
        ?? $this->distanceProvider->calculate($origin, $destination);
      $quote_input = $this->sanitizeQuoteInput($data['quote_input'] ?? []);
      $quote = $this->quoteCalculator->calculate($quote_input, $result);
      return $this->response([
        'status' => 'success',
        'distance' => $result->toArray(),
        'quote' => $quote->toArray(),
        'authoritative' => FALSE,
        'fingerprint' => $this->pairFingerprint($origin, $destination),
      ]);
    }
    catch (DistanceProviderException $exception) {
      $this->logger->notice('Distance preview failed with classified code @code.', ['@code' => $exception->getMessage()]);
      return $this->response(['status' => 'error', 'code' => $exception->getMessage()], 422);
    }
  }

  /**
   * Rebuilds the non-authoritative quote from this session's stored route fact.
   *
   * The browser may use this only for preview presentation. Submission still
   * recalculates through GoogleRoutesDistanceProvider, whose server cache makes
   * that verification free for an unchanged pair.
   */
  private function reusableDistance(
    array $data,
    string $origin,
    string $destination,
  ): ?DistanceResult {
    $distance = $data['cached_distance'] ?? NULL;
    $fingerprint = $data['route_fingerprint'] ?? NULL;
    if (!is_array($distance)
      || !is_string($fingerprint)
      || !hash_equals(
        $this->pairFingerprint($origin, $destination),
        $fingerprint,
      )) {
      return NULL;
    }

    $meters = filter_var(
      $distance['distance_meters'] ?? NULL,
      FILTER_VALIDATE_INT,
    );
    $seconds = filter_var(
      $distance['duration_seconds'] ?? NULL,
      FILTER_VALIDATE_INT,
    );
    $calculated = filter_var(
      $distance['calculated_at'] ?? NULL,
      FILTER_VALIDATE_INT,
    );
    if (($distance['provider'] ?? '') !== 'google_routes'
      || $meters === FALSE || $meters < 1 || $meters > 5000000
      || $seconds === FALSE || $seconds < 1 || $seconds > 604800
      || $calculated === FALSE) {
      return NULL;
    }

    $ttl = max(60, min(86400, (int) ($this->configFactory
      ->get('custom_webform_handlers.settings')
      ->get('route_cache_ttl') ?: 3600)));
    $now = $this->time->getCurrentTime();
    if ($calculated > $now + 60 || $calculated < $now - $ttl) {
      return NULL;
    }

    return new DistanceResult(
      $meters,
      $seconds,
      'google_routes',
      $calculated,
    );
  }

  /**
   * Collapses an address to the text the route cache is keyed on.
   *
   * The route provider's own cache identity already lowercases both endpoints,
   * but it does not collapse whitespace or comma spacing — so "123 Main St,
   * Springfield" and "123 Main St,Springfield" miss each other and buy the same
   * journey twice, from any number of browsers. Normalizing here feeds the
   * existing cache rather than adding a second one.
   *
   * This is semantically neutral for address resolution and invisible to the
   * response: DistanceResult carries distances, not the addresses it was asked
   * about. Case is deliberately left alone for the provider request; the shared
   * fingerprint helper folds it when identity, rather than display, is needed.
   */
  private function normalizeAddressText(string $value): string {
    return DistanceResult::normalizeAddressText($value);
  }

  /**
   * The identity of one ordered address pair.
   *
   * Echoed so a client, a log line, or an operator reading a response can say
   * which journey was priced without repeating the address. A route is
   * directional, so the pair is ordered and A to B never collides with B to A.
   *
   * A hash organizes identity. It is not anonymization and confers no licensing
   * right over the addresses it was computed from, and it is returned only to
   * the client that supplied them.
   */
  private function pairFingerprint(string $origin, string $destination): string {
    return DistanceResult::pairFingerprint($origin, $destination);
  }

  /**
   * Builds a private, non-cacheable JSON response.
   */
  private function response(array $data, int $status = 200): JsonResponse {
    $response = new JsonResponse($data, $status);
    $response->headers->set('Cache-Control', 'no-store, private');
    $response->headers->set('Pragma', 'no-cache');
    return $response;
  }

  /**
   * Reduces a browser-supplied quote payload to the pricing allowlist.
   *
   * The allowlist is AddressDistanceHandler::QUOTE_INPUT_FIELDS and never a copy
   * of it. A second list here silently starved QuoteCalculator's Express Courier
   * branch of delivery_mode, total_weight_lbs, total_weight_unit, courier_urgency
   * and prohibited_items_ack, so every courier preview came back
   * "manual_review" — missing_package_weight, missing_delivery_mode and
   * package_contents_require_review — while the Dispatch offer endpoint, which
   * receives the full value set, correctly reported the same request as
   * self-bookable and served Slots. One quote status cannot have two input
   * contracts.
   *
   * The caps stay here, because they are this endpoint's concern rather than the
   * calculator's: the offer path is fed by validated Webform values, whereas this
   * one is fed by an unauthenticated POST body.
   */
  private function sanitizeQuoteInput(mixed $input): array {
    if (!is_array($input)) {
      return [];
    }

    $clean = [];
    foreach (AddressDistanceHandler::QUOTE_INPUT_FIELDS as $key) {
      if (!array_key_exists($key, $input)) {
        continue;
      }
      $value = $input[$key];
      if (is_array($value)) {
        $clean[$key] = array_slice(array_values(array_filter(array_map(
          static fn ($item) => is_scalar($item) ? mb_substr(trim((string) $item), 0, 100) : '',
          $value,
        ))), 0, 20);
      }
      elseif (is_scalar($value)) {
        $clean[$key] = mb_substr(trim((string) $value), 0, 500);
      }
    }
    return $clean;
  }

}
