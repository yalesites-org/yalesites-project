<?php

namespace Drupal\Tests\ys_themes\Kernel;

use Drupal\Component\Utility\Xss;
use Drupal\Tests\ys_core\Kernel\YsKernelTestBase;

/**
 * Kernel tests for the accessible markup of the color picker swatches.
 *
 * The palette is rendered into the select's #suffix, which Drupal passes
 * through Xss::filterAdmin(), so every assertion runs on the filtered markup:
 * an attribute the filter strips never reaches the browser.
 *
 * @group ys_themes
 * @group yalesites
 */
class ComponentColorPickerTemplateTest extends YsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'ys_themes',
  ];

  /**
   * Renders the picker the way processColorPicker() does.
   *
   * @param string $selected_value
   *   The selected palette key.
   *
   * @return \DOMXPath
   *   An XPath over the rendered, filtered markup.
   */
  protected function renderPicker(string $selected_value): \DOMXPath {
    $build = [
      '#theme' => 'component_color_picker',
      '#group_label' => 'Section Theme',
      '#palette_options' => [
        'default' => 'Default - No Color',
        'one' => 'One',
        'two' => 'Two',
      ],
      '#selected_value' => $selected_value,
      '#color_info' => [
        'default' => [
          'css_var' => '#ffffff',
          'hex' => '#ffffff',
          'token_name' => 'Default - No Color',
          'token_ref' => 'default',
        ],
        'one' => [
          'css_var' => 'var(--x)',
          'hex' => '#00366b',
          'token_name' => 'Blue Yale',
          'token_ref' => 'slot-one',
        ],
        // No color resolved: the swatch falls back to its option label.
        'two' => [
          'css_var' => '',
          'hex' => '',
          'token_name' => '',
          'token_ref' => '',
        ],
      ],
    ];
    $html = Xss::filterAdmin((string) $this->container->get('renderer')->renderInIsolation($build));

    $dom = new \DOMDocument();
    @$dom->loadHTML('<?xml encoding="utf-8"?><div>' . $html . '</div>');
    return new \DOMXPath($dom);
  }

  /**
   * The swatches form a named radio group with one tab stop.
   */
  public function testSwatchesFormNamedRadioGroup(): void {
    $xpath = $this->renderPicker('one');

    $group = $xpath->query('//*[@role="radiogroup"]');
    $this->assertSame(1, $group->length, 'The swatch container is a radiogroup.');
    $this->assertSame('Section Theme', $group->item(0)->getAttribute('aria-label'));

    $radios = $xpath->query('//*[@role="radio"]');
    $this->assertSame(3, $radios->length, 'Every swatch is a radio.');

    $state = [];
    foreach ($radios as $radio) {
      $state[$radio->getAttribute('data-palette')] = [
        $radio->getAttribute('aria-label'),
        $radio->getAttribute('aria-checked'),
        $radio->getAttribute('tabindex'),
      ];
    }
    $this->assertSame([
      'default' => ['Default - No Color', 'false', '-1'],
      'one' => ['Blue Yale', 'true', '0'],
      'two' => ['Two', 'false', '-1'],
    ], $state, 'Swatches are named, the selected one is checked and is the only tab stop.');
  }

  /**
   * With nothing selected, the first swatch is still reachable by Tab.
   */
  public function testFirstSwatchIsTabStopWhenNothingSelected(): void {
    $xpath = $this->renderPicker('');

    $tab_stops = $xpath->query('//*[@role="radio"][@tabindex="0"]');
    $this->assertSame(1, $tab_stops->length);
    $this->assertSame('default', $tab_stops->item(0)->getAttribute('data-palette'));
  }

}
