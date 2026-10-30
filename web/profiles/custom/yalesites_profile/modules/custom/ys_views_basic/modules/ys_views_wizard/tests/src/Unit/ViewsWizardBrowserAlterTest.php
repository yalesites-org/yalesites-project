<?php

namespace Drupal\Tests\ys_views_wizard\Unit;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;
use Drupal\Tests\UnitTestCase;
use Drupal\layout_builder\SectionStorageInterface;
use Drupal\layout_builder_browser\Entity\LayoutBuilderBrowserBlockCategory;

/**
 * Tests where ys_views_wizard_layout_builder_browser_alter() puts the entry.
 *
 * Editors know the entry as "Views" under Dynamic Content, so the wizard must
 * join that existing category instead of opening one of its own.
 *
 * @group yalesites
 */
class ViewsWizardBrowserAlterTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    require_once __DIR__ . '/../../../ys_views_wizard.module';

    $container = new ContainerBuilder();
    $container->set('config.factory', $this->getConfigFactoryStub([
      'layout_builder_browser.settings' => ['use_modal' => FALSE],
    ]));
    \Drupal::setContainer($container);
  }

  /**
   * The listing tiles collapse into one "Views" entry in Dynamic Content.
   */
  public function testEntryJoinsDynamicContent(): void {
    $build = [
      'block_categories' => [
        'dynamic_content' => [
          '#type' => 'details',
          'links' => [$this->tile('ys_taxonomy_display_block', 'Taxonomy Display')],
        ],
        'post_listings' => [
          '#type' => 'details',
          'links' => [
            $this->tile('inline_block:post_card', 'Post Card'),
            $this->tile('inline_block:post_list_item', 'Post List Item'),
          ],
        ],
      ],
    ];

    ys_views_wizard_layout_builder_browser_alter($build, $this->contexts());
    $categories = $build['block_categories'];

    $this->assertSame(['dynamic_content'], array_keys($categories));
    $labels = array_map(
      fn($item) => $item['link']['#title']['label']['#markup'],
      $categories['dynamic_content']['links']
    );
    $this->assertSame(['Taxonomy Display', 'Views'], $labels);
    $this->assertSame(
      'ys_views_wizard.choose',
      $categories['dynamic_content']['links'][1]['link']['#url']->getRouteName()
    );
    // The entry keeps production's Views icon rather than inheriting the
    // first listing tile's placeholder.
    $this->assertSame(
      '/profiles/custom/yalesites_profile/modules/custom/ys_core/images/preview-icons/view.svg',
      $categories['dynamic_content']['links'][1]['link']['#title']['image']['#uri']
    );
    $this->assertSame('image', array_key_first($categories['dynamic_content']['links'][1]['link']['#title']));
  }

  /**
   * Dynamic Content is rebuilt where the first listing tile was.
   *
   * The browser drops Dynamic Content when nothing else in it is placeable in
   * the region, so the hook has to bring it back for the entry.
   */
  public function testDynamicContentIsRebuiltWhenMissing(): void {
    $blockcat = $this->createMock(LayoutBuilderBrowserBlockCategory::class);
    $blockcat->method('status')->willReturn(TRUE);
    $blockcat->method('getOpened')->willReturn(TRUE);
    $blockcat->method('label')->willReturn('Dynamic Content');
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->with('dynamic_content')->willReturn($blockcat);
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')->willReturn($storage);
    \Drupal::getContainer()->set('entity_type.manager', $entity_type_manager);

    $build = [
      'block_categories' => [
        'text' => ['links' => [$this->tile('inline_block:text', 'Text')]],
        'post_listings' => ['links' => [$this->tile('inline_block:post_card', 'Post Card')]],
        'media' => ['links' => [$this->tile('inline_block:image', 'Image')]],
      ],
    ];

    ys_views_wizard_layout_builder_browser_alter($build, $this->contexts());
    $categories = $build['block_categories'];

    $this->assertSame(['text', 'dynamic_content', 'media'], array_keys($categories));
    $this->assertSame('Dynamic Content', $categories['dynamic_content']['#title']);
    $this->assertSame('Views', $categories['dynamic_content']['links'][0]['link']['#title']['label']['#markup']);
  }

  /**
   * Builds a picker tile shaped like BrowserController::browse() output.
   */
  protected function tile(string $plugin_id, string $label): array {
    return [
      'link' => [
        '#type' => 'link',
        '#url' => Url::fromRoute('layout_builder.add_block', ['plugin_id' => $plugin_id]),
        '#title' => ['label' => ['#markup' => $label]],
        '#attributes' => ['class' => ['use-ajax']],
      ],
    ];
  }

  /**
   * Returns the contexts the block browser passes to the alter hook.
   */
  protected function contexts(): array {
    $section_storage = $this->createMock(SectionStorageInterface::class);
    $section_storage->method('getStorageType')->willReturn('overrides');
    $section_storage->method('getStorageId')->willReturn('node.1');
    return [
      'section_storage' => $section_storage,
      'delta' => 0,
      'region' => 'content',
    ];
  }

}
