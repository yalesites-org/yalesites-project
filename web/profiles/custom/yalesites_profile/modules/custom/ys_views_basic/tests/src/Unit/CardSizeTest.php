<?php

namespace Drupal\Tests\ys_views_basic\Unit;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\views\ViewExecutable;
use Drupal\ys_views_basic\Plugin\Field\FieldWidget\PageViewWidget;
use Drupal\ys_views_basic\Plugin\Field\FieldWidget\ProfileViewWidget;
use Drupal\ys_views_basic\Plugin\Field\FieldWidget\ViewsBasicWidgetBase;
use Drupal\ys_views_basic\Plugin\views\style\ViewsBasicDynamicStyle;
use Drupal\ys_views_basic\ViewsBasicManager;

/**
 * Tests the shared "Card size" control (#1648).
 *
 * The 4-up grid used to be a property of the profile-only directory design
 * option. It is generalised here into a dial on the shared card grid, so it is
 * asserted against more than one content type: it belongs to the base widget,
 * not to profiles.
 *
 * The dial is a size rather than a column count because the column count is not
 * ours to promise — the grid is sized by the layout region the block sits in —
 * so these tests assert sizes and the conversion from the numeric values the
 * dial briefly used.
 *
 * @coversDefaultClass \Drupal\ys_views_basic\Plugin\Field\FieldWidget\ViewsBasicWidgetBase
 *
 * @group yalesites
 */
class CardSizeTest extends UnitTestCase {

  /**
   * Builds a widget of the given class bound to the given bundle.
   */
  private function widget(string $class, string $bundle): ViewsBasicWidgetBase {
    $vocabulary = $this->createMock('Drupal\taxonomy\VocabularyInterface');
    $vocabulary->method('label')->willReturn('Custom Vocab');
    $vocab_storage = $this->createMock(EntityStorageInterface::class);
    $vocab_storage->method('load')->willReturn($vocabulary);
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')->willReturn($vocab_storage);

    $field_definition = $this->createMock(FieldDefinitionInterface::class);
    $field_definition->method('getTargetBundle')->willReturn($bundle);

    $widget = new $class(
      'widget',
      [],
      $field_definition,
      [],
      [],
      $this->createMock(ViewsBasicManager::class),
      $entity_type_manager,
      $this->getConfigFactoryStub(['ys_core.site' => ['font_pairing' => 'yalenew']]),
    );
    $widget->setStringTranslation($this->getStringTranslationStub());
    return $widget;
  }

  /**
   * Invokes a protected method on the widget.
   */
  private function invoke(object $object, string $method, array $args = []) {
    $ref = new \ReflectionMethod($object, $method);
    $ref->setAccessible(TRUE);
    return $ref->invokeArgs($object, $args);
  }

  /**
   * Builds the display controls for a bundle and returns the options group.
   */
  private function displayControls(string $class, string $bundle): array {
    $item = (object) ['params' => NULL];
    $items = $this->createMock(FieldItemListInterface::class);
    $items->method('offsetGet')->willReturn($item);

    $form = [];
    $this->invoke(
      $this->widget($class, $bundle),
      'buildDisplayControls',
      [&$form, $items, 0, ['display_ajax' => ':input[name="display"]']]
    );
    return $form['group_user_selection']['options'] ?? [];
  }

  /**
   * The card grid offers a large/small size select, defaulting to large.
   *
   * @covers ::buildDisplayControls
   */
  public function testCardSizeOfferedOnCardGrid() {
    $element = $this->displayControls(PageViewWidget::class, 'page_card')['card_size'] ?? NULL;

    $this->assertIsArray($element, 'card_size is added for a card grid bundle.');
    $this->assertSame('select', $element['#type']);
    // Plain language consistent with the rest of the form (#1648).
    $this->assertSame('Card size', (string) $element['#title']);
    $this->assertSame(['large', 'small'], array_keys($element['#options']));
    $this->assertSame(
      ViewsBasicManager::CARD_SIZE_DEFAULT,
      $element['#default_value'],
      'Unset listings keep the large (3-up) grid they already had.'
    );
    // The label must not promise an exact number of columns: the region the
    // block sits in decides that, so a count would be wrong after a move.
    $this->assertStringNotContainsStringIgnoringCase('per row', (string) $element['#title']);
  }

  /**
   * The control belongs to the shared grid, not to one content type (#1648).
   *
   * @covers ::buildDisplayControls
   */
  public function testCardSizeSharedAcrossContentTypes() {
    foreach (['page_card', 'post_card', 'event_card'] as $bundle) {
      $this->assertArrayHasKey(
        'card_size',
        $this->displayControls(PageViewWidget::class, $bundle),
        "$bundle offers card size"
      );
    }
    $this->assertArrayHasKey(
      'card_size',
      $this->displayControls(ProfileViewWidget::class, 'profile_card'),
      'profile_card offers card size'
    );
  }

  /**
   * Design options that are not a card grid do not offer the control.
   *
   * List, condensed and the profile directory each have their own layout, so
   * offering a card-grid dial there would be clutter that does nothing.
   *
   * @covers ::buildDisplayControls
   */
  public function testCardSizeHiddenForNonGridDesignOptions() {
    foreach (['page_list_item', 'page_condensed'] as $bundle) {
      $this->assertArrayNotHasKey(
        'card_size',
        $this->displayControls(PageViewWidget::class, $bundle),
        "$bundle does not offer card size"
      );
    }
    $this->assertArrayNotHasKey(
      'card_size',
      $this->displayControls(ProfileViewWidget::class, 'profile_directory'),
      'the directory design option keeps its own grid'
    );
  }

  /**
   * Builds a style plugin whose view has the given id and arguments.
   */
  private function stylePlugin(string $view_id, array $args): ViewsBasicDynamicStyle {
    $plugin = (new \ReflectionClass(ViewsBasicDynamicStyle::class))
      ->newInstanceWithoutConstructor();
    // The plugin only reads the view's id and its arguments, so a mock keeps
    // this a true unit test with no container or database.
    $view = $this->createMock(ViewExecutable::class);
    $view->method('id')->willReturn($view_id);
    $view->args = $args;
    $plugin->view = $view;

    return $plugin;
  }

  /**
   * The style plugin reads the dial off the scaffold view's arguments.
   *
   * @covers \Drupal\ys_views_basic\Plugin\views\style\ViewsBasicDynamicStyle::cardSize
   */
  public function testStylePluginReadsCardSize() {
    $args = array_fill(0, 8, '');
    $args[8] = json_encode(['card_size' => 'small']);

    $this->assertSame('small', $this->invoke(
      $this->stylePlugin('views_basic_scaffold', $args),
      'cardSize'
    ));
  }

  /**
   * An argument set built before the rename still resolves to its size (#1648).
   *
   * A rendered listing is not re-saved by the deploy hook until the hook runs,
   * and a cached argument set can outlive the deploy, so the reader accepts the
   * numeric value the dial briefly used rather than silently reverting the
   * author's 4-up grid to 3-up.
   *
   * @covers \Drupal\ys_views_basic\Plugin\views\style\ViewsBasicDynamicStyle::cardSize
   */
  public function testStylePluginConvertsTheSupersededCount() {
    $args = array_fill(0, 8, '');
    $args[8] = json_encode(['cards_per_row' => 4]);

    $this->assertSame('small', $this->invoke(
      $this->stylePlugin('views_basic_scaffold', $args),
      'cardSize'
    ));
  }

  /**
   * Another view sharing this style plugin falls back, never misreads (#1648).
   *
   * The content_resources view builds a shorter argument list of its own,
   * where index 8 is pin_settings. Decoding that as field display options
   * would be reading a different argument entirely, so the plugin must not try.
   *
   * @covers \Drupal\ys_views_basic\Plugin\views\style\ViewsBasicDynamicStyle::cardSize
   */
  public function testStylePluginIgnoresForeignViews() {
    $resource_args = array_fill(0, 8, '');
    // Index 8 is pin_settings for this view. The fixture carries a card_size
    // key it would never really have, precisely so this asserts the view-id
    // guard rather than passing by luck: without the guard the plugin would
    // decode this argument and answer "small".
    $resource_args[8] = json_encode(['card_size' => 'small', 'pinned_to_top' => TRUE]);

    $this->assertSame('large', $this->invoke(
      $this->stylePlugin('content_resources', $resource_args),
      'cardSize'
    ));

    // A scaffold view with no arguments at all still renders, at large.
    $this->assertSame('large', $this->invoke(
      $this->stylePlugin('views_basic_scaffold', []),
      'cardSize'
    ));
  }

  /**
   * Card sizes are normalised so only a value the SCSS has a rule for is used.
   *
   * @covers \Drupal\ys_views_basic\ViewsBasicManager::normalizeCardSize
   *
   * @dataProvider providerNormalizeCardSize
   */
  public function testNormalizeCardSize($stored, string $expected) {
    $this->assertSame($expected, ViewsBasicManager::normalizeCardSize($stored));
  }

  /**
   * Data provider for ::testNormalizeCardSize().
   */
  public static function providerNormalizeCardSize(): array {
    return [
      'a size passes through' => ['small', 'small'],
      'the default passes through' => ['large', 'large'],
      'the superseded 3-up count is large' => [3, 'large'],
      'the superseded 4-up count is small' => [4, 'small'],
      'a numeric string is read as a count' => ['4', 'small'],
      'a count with no grid rule falls back' => [7, 'large'],
      'an unknown size falls back' => ['enormous', 'large'],
      'an absent value falls back' => [NULL, 'large'],
      'a non-scalar falls back' => [['small'], 'large'],
    ];
  }

  /**
   * The capability is declared per bundle rather than inferred at runtime.
   *
   * Mirrors supports_thumbnail: the listing definition is the single source of
   * truth for what a bundle can do (ADR DR-2).
   *
   * @covers \Drupal\ys_views_basic\ViewsBasicManager::bundleSupportsCardSize
   */
  public function testCapabilityIsDeclarative() {
    $this->assertTrue(ViewsBasicManager::bundleSupportsCardSize('post_card'));
    $this->assertTrue(ViewsBasicManager::bundleSupportsCardSize('profile_card'));
    $this->assertFalse(ViewsBasicManager::bundleSupportsCardSize('post_condensed'));
    $this->assertFalse(ViewsBasicManager::bundleSupportsCardSize('profile_directory'));
  }

}
