<?php

namespace Drupal\Tests\ys_layouts\Unit;

use Drupal\Core\Template\Attribute;
use Drupal\Tests\UnitTestCase;
use Drupal\gin_lb\TwigExtension\GinLbExtension;

/**
 * Tests that gin_lb keeps the views-exposed-form class in Layout Builder.
 *
 * Views AJAX binds exposed filters by form.views-exposed-form; without it the
 * media library "Apply filters" hits Access denied in Layout Builder
 * (yalesites-org/YaleSites-Internal#1791, drupal.org #3563771).
 *
 * @group yalesites
 * @group ys_layouts
 */
class GinLbExposedFormClassTest extends UnitTestCase {

  /**
   * Tests the exposed form keeps both the glb- and the original class.
   */
  public function testExposedFormClassIsKept(): void {
    $attribute = new Attribute(['class' => ['views-exposed-form', 'form']]);

    $classes = (new GinLbExtension())->ginClasses($attribute)->getClass()->value();

    $this->assertContains('views-exposed-form', $classes);
    $this->assertContains('glb-views-exposed-form', $classes);
  }

}
