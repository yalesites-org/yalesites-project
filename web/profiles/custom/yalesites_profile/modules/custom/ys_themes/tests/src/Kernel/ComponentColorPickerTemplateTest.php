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
   * @param string|null $display
   *   The display variant, or NULL to use the theme hook's default.
   *
   * @return \DOMXPath
   *   An XPath over the rendered, filtered markup.
   */
  protected function renderPicker(string $selected_value, ?string $display = NULL): \DOMXPath {
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
    if ($display !== NULL) {
      $build['#display'] = $display;
    }
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

  /**
   * The dropdown is a named select-only combobox that controls a listbox.
   */
  public function testDropdownIsNamedSelectOnlyCombobox(): void {
    $xpath = $this->renderPicker('one', 'dropdown');

    $this->assertSame(0, $xpath->query('//*[@role="radiogroup"]')->length, 'The dropdown has no swatch radio group.');

    $comboboxes = $xpath->query('//*[@role="combobox"]');
    $this->assertSame(1, $comboboxes->length);
    $combobox = $comboboxes->item(0);
    $this->assertSame('div', $combobox->nodeName, 'A button element would be stripped by the admin XSS filter.');
    $this->assertSame('Section Theme', $combobox->getAttribute('aria-label'));
    $this->assertSame('listbox', $combobox->getAttribute('aria-haspopup'));
    $this->assertSame('false', $combobox->getAttribute('aria-expanded'));
    $this->assertSame('0', $combobox->getAttribute('tabindex'), 'The combobox is the tab stop.');

    // The combobox shows the selected option's swatch, name and hex.
    $this->assertSame('Blue Yale', trim($xpath->query('.//*[contains(@class, "palette-dropdown-name")]', $combobox)->item(0)->textContent));
    $this->assertSame('#00366b', trim($xpath->query('.//*[contains(@class, "palette-dropdown-hex")]', $combobox)->item(0)->textContent));
    $this->assertSame('#00366b', $xpath->query('.//*[contains(@class, "palette-dropdown-swatch")]', $combobox)->item(0)->getAttribute('data-hex'));
  }

  /**
   * Every option is in the listbox, named, with the selected one marked.
   */
  public function testDropdownOptionsAreNamedAndSelected(): void {
    $xpath = $this->renderPicker('one', 'dropdown');

    $listbox = $xpath->query('//*[@role="listbox"]');
    $this->assertSame(1, $listbox->length);
    $this->assertSame('Section Theme', $listbox->item(0)->getAttribute('aria-label'));

    $state = [];
    foreach ($xpath->query('//*[@role="listbox"]/*[@role="option"]') as $option) {
      $state[$option->getAttribute('data-palette')] = [
        $option->getAttribute('aria-label'),
        $option->getAttribute('aria-selected'),
        // Options are reached through aria-activedescendant, never by Tab.
        $option->hasAttribute('tabindex'),
      ];
    }
    $this->assertSame([
      'default' => ['Default - No Color #ffffff', 'false', FALSE],
      'one' => ['Blue Yale #00366b', 'true', FALSE],
      'two' => ['Two', 'false', FALSE],
    ], $state, 'Options are named "Name #hex", and only the selected one is aria-selected.');

    // An option with no resolved color still renders, with an em dash hex.
    $two = $xpath->query('//*[@role="option"][@data-palette="two"]')->item(0);
    $this->assertSame('Two', trim($xpath->query('.//*[contains(@class, "palette-dropdown-name")]', $two)->item(0)->textContent));
    $this->assertSame('—', trim($xpath->query('.//*[contains(@class, "palette-dropdown-hex")]', $two)->item(0)->textContent));
  }

  /**
   * With nothing selected, the combobox shows the first option.
   */
  public function testDropdownShowsFirstOptionWhenNothingSelected(): void {
    $xpath = $this->renderPicker('', 'dropdown');

    $name = $xpath->query('//*[@role="combobox"]//*[contains(@class, "palette-dropdown-name")]');
    $this->assertSame('Default - No Color', trim($name->item(0)->textContent));
    $this->assertSame(0, $xpath->query('//*[@role="option"][@aria-selected="true"]')->length);
  }

}
