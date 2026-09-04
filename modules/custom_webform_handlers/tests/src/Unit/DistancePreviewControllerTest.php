<?php

namespace Drupal\Tests\custom_webform_handlers\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\custom_webform_handlers\Controller\DistancePreviewController;
use Drupal\custom_webform_handlers\Dispatch\Profile\BookingProfileResolverInterface;
use Drupal\custom_webform_handlers\Distance\DistanceProviderInterface;
use Drupal\custom_webform_handlers\Quote\QuoteCalculator;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests privacy-related response behavior.
 *
 * @group custom_webform_handlers
 */
final class DistancePreviewControllerTest extends UnitTestCase {

  public function testPreviewResponsesDisableCaching(): void {
    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $controller = new DistancePreviewController(
      $this->createMock(DistanceProviderInterface::class),
      $this->createMock(FloodInterface::class),
      $config_factory,
      $this->createMock(LoggerInterface::class),
      new QuoteCalculator(
        $config_factory,
        $this->createMock(BookingProfileResolverInterface::class),
      ),
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
