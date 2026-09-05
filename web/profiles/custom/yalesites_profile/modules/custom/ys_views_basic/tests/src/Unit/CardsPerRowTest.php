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
 * Tests the shared "Cards per row" control (#1648).
 *
 * The 4-up grid used to be a property of the profile-only directory design
 * option. It is generalised here into a dial on the shared card grid, so it is
 * asserted against more than one content type: it belongs to the base widget,
 * not to profiles.
 *
 * @coversDefaultClass \Drupal\ys_views_basic\Plugin\Field\FieldWidget\ViewsBasicWidgetBase
 *
 * @group yalesites
 */
class CardsPerRowTest extends UnitTestCase {

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
   * The card grid offers a 3-or-4 cards-per-row select, defaulting to 3.
   *
   * @covers ::buildDisplayControls
   */
  public function testCardsPerRowOfferedOnCardGrid() {
    $element = $this->displayControls(PageViewWidget::class, 'page_card')['cards_per_row'] ?? NULL;

    $this->assertIsArray($element, 'cards_per_row is added for a card grid bundle.');
    $this->assertSame('select', $element['#type']);
    // Plain language consistent with the rest of the form (#1648).
    $this->assertSame('Cards per row', (string) $element['#title']);
    $this->assertSame([3, 4], array_keys($element['#options']));
    $this->assertSame(3, $element['#default_value'], 'Unset listings keep the current 3-up grid.');
  }

  /**
   * The control belongs to the shared grid, not to one content type (#1648).
   *
   * @covers ::buildDisplayControls
   */
  public function testCardsPerRowSharedAcrossContentTypes() {
    foreach (['page_card', 'post_card', 'event_card'] as $bundle) {
      $this->assertArrayHasKey(
        'cards_per_row',
        $this->displayControls(PageViewWidget::class, $bundle),
        "$bundle offers cards per row"
      );
    }
    $this->assertArrayHasKey(
      'cards_per_row',
      $this->displayControls(ProfileViewWidget::class, 'profile_card'),
      'profile_card offers cards per row'
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
  public function testCardsPerRowHiddenForNonGridDesignOptions() {
    foreach (['page_list_item', 'page_condensed'] as $bundle) {
      $this->assertArrayNotHasKey(
        'cards_per_row',
        $this->displayControls(PageViewWidget::class, $bundle),
        "$bundle does not offer cards per row"
      );
    }
    $this->assertArrayNotHasKey(
      'cards_per_row',
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
   * @covers \Drupal\ys_views_basic\Plugin\views\style\ViewsBasicDynamicStyle::cardsPerRow
   */
  public function testStylePluginReadsCardsPerRow() {
    $args = array_fill(0, 8, '');
    $args[8] = json_encode(['cards_per_row' => 4]);

    $this->assertSame(4, $this->invoke(
      $this->stylePlugin('views_basic_scaffold', $args),
      'cardsPerRow'
    ));
  }

  /**
   * Another view sharing this style plugin falls back, never misreads (#1648).
   *
   * The content_resources view builds a shorter argument list of its own,
   * where index 8 is pin_settings. Decoding that as field display options
   * would be reading a different argument entirely, so the plugin must not try.
   *
   * @covers \Drupal\ys_views_basic\Plugin\views\style\ViewsBasicDynamicStyle::cardsPerRow
   */
  public function testStylePluginIgnoresForeignViews() {
    $resource_args = array_fill(0, 8, '');
    // Index 8 is pin_settings for this view. The fixture carries a
    // cards_per_row key it would never really have, precisely so this asserts
    // the view-id guard rather than passing by luck: without the guard the
    // plugin would decode this argument and answer 4.
    $resource_args[8] = json_encode(['cards_per_row' => 4, 'pinned_to_top' => TRUE]);

    $this->assertSame(3, $this->invoke(
      $this->stylePlugin('content_resources', $resource_args),
      'cardsPerRow'
    ));

    // A scaffold view with no arguments at all still renders, at 3-up.
    $this->assertSame(3, $this->invoke(
      $this->stylePlugin('views_basic_scaffold', []),
      'cardsPerRow'
    ));
  }

  /**
   * The capability is declared per bundle rather than inferred at runtime.
   *
   * Mirrors supports_thumbnail: the listing definition is the single source of
   * truth for what a bundle can do (ADR DR-2).
   *
   * @covers \Drupal\ys_views_basic\ViewsBasicManager::bundleSupportsCardsPerRow
   */
  public function testCapabilityIsDeclarative() {
    $this->assertTrue(ViewsBasicManager::bundleSupportsCardsPerRow('post_card'));
    $this->assertTrue(ViewsBasicManager::bundleSupportsCardsPerRow('profile_card'));
    $this->assertFalse(ViewsBasicManager::bundleSupportsCardsPerRow('post_condensed'));
    $this->assertFalse(ViewsBasicManager::bundleSupportsCardsPerRow('profile_directory'));
  }

}
