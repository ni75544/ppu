<?php

namespace Drupal\custom_webform_handlers\Controller;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\custom_webform_handlers\Distance\DistanceProviderException;
use Drupal\custom_webform_handlers\Distance\DistanceProviderInterface;
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

  public function __construct(
    private readonly DistanceProviderInterface $distanceProvider,
    private readonly FloodInterface $flood,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerInterface $logger,
    private readonly QuoteCalculator $quoteCalculator,
  ) {}

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('custom_webform_handlers.distance_provider'),
      $container->get('flood'),
      $container->get('config.factory'),
      $container->get('logger.channel.custom_webform_handlers'),
      $container->get('custom_webform_handlers.quote_calculator'),
    );
  }

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

    $origin = trim((string) ($data['origin'] ?? ''));
    $destination = trim((string) ($data['destination'] ?? ''));
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
      $result = $this->distanceProvider->calculate($origin, $destination);
      $quote_input = $this->sanitizeQuoteInput($data['quote_input'] ?? []);
      $quote = $this->quoteCalculator->calculate($quote_input, $result);
      return $this->response([
        'status' => 'success',
        'distance' => $result->toArray(),
        'quote' => $quote->toArray(),
        'authoritative' => FALSE,
      ]);
    }
    catch (DistanceProviderException $exception) {
      $this->logger->notice('Distance preview failed with classified code @code.', ['@code' => $exception->getMessage()]);
      return $this->response(['status' => 'error', 'code' => $exception->getMessage()], 422);
    }
  }

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
