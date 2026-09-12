<?php

namespace Drupal\custom_webform_handlers\Controller;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\custom_webform_handlers\Distance\AddressCoordinateResolver;
use Drupal\custom_webform_handlers\Distance\DistanceProviderException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Supplies throttled address labels from the approved self-hosted geocoder.
 */
final class AddressSuggestionController implements ContainerInjectionInterface {

  private const MIN_QUERY_LENGTH = 6;

  private const MAX_QUERY_LENGTH = 200;

  private const HOURLY_REQUEST_LIMIT = 120;

  /**
   * Constructs the address suggestion controller.
   */
  public function __construct(
    private readonly AddressCoordinateResolver $resolver,
    private readonly FloodInterface $flood,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('custom_webform_handlers.address_coordinate_resolver'),
      $container->get('flood'),
      $container->get('logger.channel.custom_webform_handlers'),
    );
  }

  /**
   * Returns address labels for one partial street-address query.
   */
  public function suggest(Request $request): JsonResponse {
    $parameters = $request->query->all();
    $raw = $parameters['q'] ?? NULL;
    if (!is_string($raw)) {
      return $this->response([]);
    }

    $query = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', trim($raw)) ?? '';
    $query = preg_replace('/\s+/u', ' ', $query) ?? '';
    $length = mb_strlen($query, 'UTF-8');
    if ($length < self::MIN_QUERY_LENGTH || $length > self::MAX_QUERY_LENGTH) {
      return $this->response([]);
    }

    $identifier = hash('sha256', (string) $request->getClientIp());
    $event = 'custom_webform_handlers.address_suggestions';
    if (!$this->flood->isAllowed($event, self::HOURLY_REQUEST_LIMIT, 3600, $identifier)) {
      return $this->response([], 429);
    }
    $this->flood->register($event, 3600, $identifier);

    try {
      $suggestions = array_map(
        static fn (string $value): array => [
          'value' => $value,
          'label' => $value,
        ],
        $this->resolver->suggest($query),
      );
      return $this->response($suggestions);
    }
    catch (DistanceProviderException $exception) {
      $this->logger->notice('Address suggestions failed with classified code @code.', [
        '@code' => $exception->getMessage(),
      ]);
      return $this->response([]);
    }
    catch (\Throwable) {
      $this->logger->warning('Address suggestions failed without exposing the submitted query.');
      return $this->response([]);
    }
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

}
