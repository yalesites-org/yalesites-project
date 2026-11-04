<?php

namespace Drupal\Tests\ys_views_basic\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\ys_views_basic\ViewsBasicManager;

/**
 * Tests dropping excluded terms from old listing URLs (#2102).
 *
 * @coversDefaultClass \Drupal\ys_views_basic\ViewsBasicManager
 *
 * @group yalesites
 */
class DropUnavailableTermsTest extends UnitTestCase {

  /**
   * An excluded term is dropped and the other terms stay.
   *
   * @covers ::dropUnavailableTerms
   */
  public function testDropsOnlyUnavailableTerms(): void {
    $query = ['field_audience_target_id' => ['361', '5'], 'page' => '1'];
    $result = ViewsBasicManager::dropUnavailableTerms($query, ['field_audience_target_id' => [5, 6]]);
    $this->assertSame(['field_audience_target_id' => ['5'], 'page' => '1'], $result);
  }

  /**
   * A filter left with no values keeps its key, so the input is not empty.
   *
   * @covers ::dropUnavailableTerms
   */
  public function testEmptiedFilterKeepsKey(): void {
    $result = ViewsBasicManager::dropUnavailableTerms(['field_category_target_id' => ['21']], ['field_category_target_id' => [1]]);
    $this->assertSame(['field_category_target_id' => []], $result);
  }

  /**
   * Scalar values, "All" and unrelated keys are handled.
   *
   * @covers ::dropUnavailableTerms
   */
  public function testScalarAllAndUnrelatedKeys(): void {
    $allowed = ['a' => [1]];
    $this->assertSame(['a' => ['All']], ViewsBasicManager::dropUnavailableTerms(['a' => 'All'], $allowed));
    $this->assertSame(['a' => []], ViewsBasicManager::dropUnavailableTerms(['a' => '9'], $allowed));
    $this->assertSame(['b' => ['9']], ViewsBasicManager::dropUnavailableTerms(['b' => ['9']], $allowed));
  }

}
