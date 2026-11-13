<?php

namespace Drupal\Tests\ys_layouts\Unit;

use Drupal\Core\Form\FormState;
use Drupal\Core\Layout\LayoutDefinition;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_layouts\Plugin\Layout\YSLayoutOneColumn;
use Drupal\ys_themes\ColorTokenResolver;

/**
 * Tests the YSLayoutOneColumn section layout plugin.
 *
 * @coversDefaultClass \Drupal\ys_layouts\Plugin\Layout\YSLayoutOneColumn
 *
 * @group yalesites
 * @group ys_layouts
 */
class YSLayoutOneColumnTest extends UnitTestCase {

  /**
   * Builds the layout plugin under test.
   *
   * @return \Drupal\ys_layouts\Plugin\Layout\YSLayoutOneColumn
   *   The layout plugin.
   */
  protected function buildLayout(): YSLayoutOneColumn {
    $definition = new LayoutDefinition([
      'regions' => ['content' => ['label' => 'Content']],
    ]);
    return new YSLayoutOneColumn(
      [],
      'layout_onecol',
      $definition,
      $this->createMock(ColorTokenResolver::class),
    );
  }

  /**
   * One column exposes the Component theme picker, but hides the divider.
   *
   * One column is a single-region layout, so the inherited divider checkbox
   * has nothing to divide and is hidden via '#access' (not unset -- see
   * buildConfigurationForm()'s docblock for why removing the element
   * entirely would corrupt the stored value on save). The theme element's
   * own shape and option list stay pinned in YSLayoutOptionsTest.
   *
   * @covers ::buildConfigurationForm
   */
  public function testBuildConfigurationFormExposesThemeWithoutDivider(): void {
    $layout = $this->buildLayout();
    $layout->setStringTranslation($this->getStringTranslationStub());

    $form = $layout->buildConfigurationForm([], new FormState());

    $this->assertArrayHasKey('divider', $form);
    $this->assertFalse($form['divider']['#access']);
    $this->assertArrayHasKey('theme', $form);
  }

}
