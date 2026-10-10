<?php

namespace Drupal\Tests\custom_webform_handlers\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\custom_webform_handlers\Controller\DistancePreviewController;
use Drupal\custom_webform_handlers\Dispatch\Profile\BookingProfileResolverInterface;
use Drupal\custom_webform_handlers\Distance\DistanceProviderInterface;
use Drupal\custom_webform_handlers\Distance\RouteFactStore;
use Drupal\custom_webform_handlers\Distance\RouteOperationLedger;
use Drupal\custom_webform_handlers\Distance\RouteRequestOwner;
use Drupal\custom_webform_handlers\Distance\RoutingModeResolver;
use Drupal\custom_webform_handlers\Quote\QuoteCalculator;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Tests privacy-related response behavior.
 *
 * @group custom_webform_handlers
 */
final class DistancePreviewControllerTest extends UnitTestCase {

  public function testPreviewResponsesDisableCaching(): void {
    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $time = $this->createMock(TimeInterface::class);
    $logger = $this->createMock(LoggerInterface::class);
    // RouteFactStore, RouteOperationLedger, RouteRequestOwner and
    // RoutingModeResolver are final and cannot be mocked, but this case exercises
    // the oversized-body guard, which returns 413 before the controller touches
    // any of them. Real instances over mockable collaborators keep the constructor
    // honest without reaching their behaviour.
    $route_request_owner = new RouteRequestOwner(
      $this->createMock(AccountInterface::class),
      new RequestStack(),
    );
    $route_fact_store = new RouteFactStore(
      $this->createMock(PrivateTempStoreFactory::class),
      $config_factory,
      $time,
      $logger,
      $this->createMock(LockBackendInterface::class),
      $route_request_owner,
    );
    $operation_ledger = new RouteOperationLedger(
      $this->createMock(KeyValueExpirableFactoryInterface::class),
      $time,
      $logger,
    );
    $controller = new DistancePreviewController(
      $this->createMock(DistanceProviderInterface::class),
      $this->createMock(FloodInterface::class),
      $config_factory,
      $logger,
      new QuoteCalculator(
        $config_factory,
        $this->createMock(BookingProfileResolverInterface::class),
      ),
      $time,
      $route_fact_store,
      new RoutingModeResolver($config_factory, 'false'),
      $operation_ledger,
      $route_request_owner,
      $this->createMock(LockBackendInterface::class),
    );
    $request = Request::create(
      '/custom-webform-handlers/distance-preview',
      'POST',
      [],
      [],
      [],
      [],
      str_repeat('x', 4097),
    );

    $response = $controller->preview($request);

    $this->assertSame(413, $response->getStatusCode());
    $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    $this->assertSame('no-cache', $response->headers->get('Pragma'));
  }

}
