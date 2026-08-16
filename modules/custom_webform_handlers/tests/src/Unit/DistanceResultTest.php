<?php

namespace Drupal\Tests\custom_webform_handlers\Unit;

use Drupal\custom_webform_handlers\Distance\DistanceResult;
use Drupal\Tests\UnitTestCase;

/**
 * Tests normalized route conversions.
 *
 * @group custom_webform_handlers
 */
final class DistanceResultTest extends UnitTestCase {

  public function testUnitConversions(): void {
    $result = new DistanceResult(1609344, 3600, 'test', 1234567890, '_p~iF~ps|U_ulLnnqC_mqNvxq`@');

    $this->assertSame(1609344, $result->distanceMeters());
    $this->assertEqualsWithDelta(1000.0, $result->distanceMiles(), 0.0001);
    $this->assertEqualsWithDelta(1609.344, $result->distanceKilometers(), 0.0001);
    $this->assertSame(3600, $result->durationSeconds());
    $this->assertSame(1234567890, $result->calculatedAt());
    $this->assertSame('_p~iF~ps|U_ulLnnqC_mqNvxq`@', $result->encodedPolyline());
    $this->assertSame('_p~iF~ps|U_ulLnnqC_mqNvxq`@', $result->toArray()['encoded_polyline']);
  }

  public function testZeroDistanceIsRejected(): void {
    $this->expectException(\InvalidArgumentException::class);
    new DistanceResult(0, 0);
  }

}
