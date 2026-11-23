<?php

namespace Drupal\Tests\ys_views_basic\Unit;

use Drupal\Tests\UnitTestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Pins the Chosen accessibility library and its admin-only selector scope.
 *
 * @group ys_views_basic
 * @group yalesites
 */
class ChosenA11yLibraryTest extends UnitTestCase {

  /**
   * The library declares the script and its dependencies.
   */
  public function testLibraryEntry(): void {
    $libraries = Yaml::parseFile(dirname(__DIR__, 3) . '/ys_views_basic.libraries.yml');
    $this->assertArrayHasKey('chosen_a11y', $libraries);
    $this->assertArrayHasKey('assets/js/views-basic-chosen-a11y.js', $libraries['chosen_a11y']['js']);
    $this->assertContains('core/once', $libraries['chosen_a11y']['dependencies']);
  }

  /**
   * All three widgets that build the multi-selects attach the library.
   *
   * @dataProvider widgetProvider
   */
  public function testWidgetAttachesLibrary(string $file): void {
    $source = file_get_contents(dirname(__DIR__, 3) . '/src/Plugin/Field/FieldWidget/' . $file);
    $this->assertStringContainsString("'ys_views_basic/chosen_a11y'", $source);
  }

  /**
   * The Resource View widget attaches the library too.
   */
  public function testResourceWidgetAttachesLibrary(): void {
    $source = file_get_contents(dirname(__DIR__, 4) . '/ys_views_content_resources/src/Plugin/Field/FieldWidget/ViewsContentResourcesDefaultWidget.php');
    $this->assertStringContainsString("'ys_views_basic/chosen_a11y'", $source);
  }

  /**
   * Names use pass-through placeholders and the select comes from the DOM.
   */
  public function testNamesAreNotHtmlEscapedAndSelectFoundBySibling(): void {
    $js = file_get_contents(dirname(__DIR__, 3) . '/assets/js/views-basic-chosen-a11y.js');
    $this->assertStringNotContainsString("'@label'", $js);
    $this->assertStringNotContainsString("'@item'", $js);
    $this->assertStringContainsString('previousElementSibling', $js);
    $this->assertStringNotContainsString('_chosen$', $js);
  }

  /**
   * The script marks selection and groups without wrapping Chosen's rows.
   */
  public function testSelectionAndGroupAttributes(): void {
    $js = file_get_contents(dirname(__DIR__, 3) . '/assets/js/views-basic-chosen-a11y.js');
    $this->assertStringContainsString("'aria-multiselectable', 'true'", $js);
    $this->assertStringContainsString("'aria-selected'", $js);
    $this->assertStringContainsString("classList.contains('result-selected')", $js);
    $this->assertStringContainsString("'group-result'", $js);
    $this->assertStringContainsString("'role', 'group'", $js);
    $this->assertStringContainsString("'aria-owns'", $js);
    $this->assertStringNotContainsString('createElement', $js);
    $this->assertStringNotContainsString('wrapAll', $js);
  }

  /**
   * Widget files.
   */
  public static function widgetProvider(): array {
    return [
      ['ViewsBasicWidgetBase.php'],
      ['ViewsBasicDefaultWidget.php'],
      ['EventCalendarDefaultWidget.php'],
    ];
  }

  /**
   * Every selector in the script is scoped to the block configuration form.
   */
  public function testSelectorsAreAdminScoped(): void {
    $js = file_get_contents(dirname(__DIR__, 3) . '/assets/js/views-basic-chosen-a11y.js');
    $this->assertStringContainsString("const SCOPE = '.layout-builder-configure-block';", $js);
    $this->assertStringContainsString('${SCOPE} .chosen-container-multi', $js);
    // The only document-wide queries allowed are the select and its label.
    preg_match_all('/querySelector(?:All)?\(([^)]*)\)/', $js, $m);
    foreach ($m[1] as $arg) {
      $this->assertDoesNotMatchRegularExpression('/chosen-container/', $arg);
    }
  }

}
