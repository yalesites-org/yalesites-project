<?php

namespace Drupal\Tests\ys_layouts\Unit;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\layout_builder\SectionStorageInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_layouts\CloneContextualLinks;
use Drupal\ys_layouts\Service\BlockCloner;

/**
 * Tests which Layout Builder blocks are offered the Clone contextual link.
 *
 * @coversDefaultClass \Drupal\ys_layouts\CloneContextualLinks
 *
 * @group yalesites
 * @group ys_layouts
 */
class CloneContextualLinksTest extends UnitTestCase {

  /**
   * The mocked block cloner service.
   *
   * @var \Drupal\ys_layouts\Service\BlockCloner|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $cloner;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->cloner = $this->createMock(BlockCloner::class);
    $container = new ContainerBuilder();
    $container->set('ys_layouts.block_cloner', $this->cloner);
    \Drupal::setContainer($container);

    // The hook under test lives in the procedural module file. It only calls
    // static methods, so loading the file is enough.
    require_once __DIR__ . '/../../../ys_layouts.module';
  }

  /**
   * Builds a Layout Builder element with one region and two blocks.
   *
   * @param bool $region_is_open
   *   Whether the region still offers its "Add block" link. A lock removes it.
   *
   * @return array
   *   The fake Layout Builder render element.
   */
  protected function buildElement(bool $region_is_open = TRUE): array {
    $inline = new SectionComponent('inline-uuid', 'content', [
      'id' => 'inline_block:text',
    ]);
    $reusable = new SectionComponent('reusable-uuid', 'content', [
      'id' => 'block_content:some-uuid',
    ]);
    $section = new Section('layout_onecol', [], [$inline, $reusable]);

    $storage = $this->createMock(SectionStorageInterface::class);
    $storage->method('getSection')->willReturn($section);

    $region = [
      'inline-uuid' => [
        '#contextual_links' => [
          'layout_builder_block' => [
            'route_parameters' => ['delta' => 0, 'uuid' => 'inline-uuid'],
            'metadata' => ['operations' => 'move:update:remove'],
          ],
        ],
      ],
      'reusable-uuid' => [
        '#contextual_links' => [
          'layout_builder_block' => [
            'route_parameters' => ['delta' => 0, 'uuid' => 'reusable-uuid'],
            'metadata' => ['operations' => 'move:update:remove'],
          ],
        ],
      ],
    ];
    if ($region_is_open) {
      $region = ['layout_builder_add_block' => ['link' => []]] + $region;
    }

    return [
      '#section_storage' => $storage,
      'layout_builder' => [
        0 => ['link' => ['#url' => 'add-section']],
        1 => [
          'configure' => ['#url' => 'configure-section'],
          'layout-builder__section' => [
            '#layout' => 'layout_onecol',
            'content' => $region,
          ],
        ],
      ],
    ];
  }

  /**
   * Reads the clone metadata stamped on a block.
   *
   * @param array $element
   *   The processed element.
   * @param string $uuid
   *   The component UUID to read.
   *
   * @return array
   *   The block's contextual-links metadata.
   */
  protected function metadata(array $element, string $uuid): array {
    return $element['layout_builder'][1]['layout-builder__section']['content'][$uuid]['#contextual_links']['layout_builder_block']['metadata'];
  }

  /**
   * Only clonable blocks are marked during pre-render.
   *
   * @covers ::preRender
   */
  public function testOnlyClonableBlocksAreMarked(): void {
    $this->cloner->method('isClonable')
      ->willReturnCallback(function (SectionComponent $component) {
        return $component->getUuid() === 'inline-uuid';
      });

    $element = CloneContextualLinks::preRender($this->buildElement());

    $inline = $this->metadata($element, 'inline-uuid');
    $reusable = $this->metadata($element, 'reusable-uuid');

    $this->assertSame('1', $inline[CloneContextualLinks::METADATA_KEY]);
    $this->assertArrayNotHasKey(
      CloneContextualLinks::METADATA_KEY,
      $reusable
    );

    // Core's own metadata is left intact so the other links keep working.
    $this->assertSame('move:update:remove', $inline['operations']);
  }

  /**
   * A region that no longer accepts new blocks is not offered cloning.
   *
   * Layout Builder Lock removes the "Add block" link from locked regions;
   * cloning must not become a way around that.
   *
   * @covers ::preRender
   */
  public function testLockedRegionIsNotMarked(): void {
    $this->cloner->method('isClonable')->willReturn(TRUE);

    $element = CloneContextualLinks::preRender(
      $this->buildElement(FALSE)
    );

    $this->assertArrayNotHasKey(
      CloneContextualLinks::METADATA_KEY,
      $this->metadata($element, 'inline-uuid')
    );
  }

  /**
   * An element without section storage is returned untouched.
   *
   * @covers ::preRender
   */
  public function testElementWithoutSectionStorageIsUntouched(): void {
    $this->cloner->expects($this->never())->method('isClonable');
    $element = ['layout_builder' => []];

    $this->assertSame($element, CloneContextualLinks::preRender($element));
  }

  /**
   * Builds the $items array core hands to the alter hook.
   *
   * \Drupal\Core\Menu\ContextualLinkManager::getContextualLinksArrayByGroup()
   * keys the items by contextual-link PLUGIN id — not by group name — and
   * copies the group's metadata into every one of them.
   *
   * @param array $metadata
   *   The contextual-links metadata of the block being rendered.
   *
   * @return array
   *   The items for the layout_builder_block group.
   */
  protected function buildItems(array $metadata): array {
    $items = [];
    $plugins = [
      'layout_builder_block_update',
      'layout_builder_block_move',
      'layout_builder_block_remove',
      CloneContextualLinks::PLUGIN_ID,
    ];
    foreach ($plugins as $plugin_id) {
      $items[$plugin_id] = [
        'route_name' => 'route.' . $plugin_id,
        'route_parameters' => ['delta' => 0, 'uuid' => 'inline-uuid'],
        'title' => $plugin_id,
        'weight' => 0,
        'localized_options' => [],
        'metadata' => $metadata,
      ];
    }
    return $items;
  }

  /**
   * The hook keeps the Clone link for a block marked during pre-render.
   *
   * Guards the wiring between the two halves of the feature: the pre-render
   * stamps the metadata and the hook has to find it under the plugin id.
   */
  public function testHookKeepsTheLinkForClonableBlock(): void {
    $this->cloner->method('isClonable')->willReturn(TRUE);
    $element = CloneContextualLinks::preRender($this->buildElement());
    $items = $this->buildItems($this->metadata($element, 'inline-uuid'));

    $links = [
      'layout-builder-block-update' => ['title' => 'Configure'],
      CloneContextualLinks::LINK_KEY => ['title' => 'Clone block'],
    ];
    $links_element = ['#links' => $links];
    ys_layouts_contextual_links_view_alter($links_element, $items);

    $this->assertSame($links, $links_element['#links']);
  }

  /**
   * The hook removes the Clone link from a block that was not marked.
   */
  public function testHookRemovesTheLinkForNonClonableBlock(): void {
    $this->cloner->method('isClonable')->willReturn(FALSE);
    $element = CloneContextualLinks::preRender($this->buildElement());
    $items = $this->buildItems($this->metadata($element, 'reusable-uuid'));

    $links_element = [
      '#links' => [
        'layout-builder-block-update' => ['title' => 'Configure'],
        CloneContextualLinks::LINK_KEY => ['title' => 'Clone block'],
      ],
    ];
    ys_layouts_contextual_links_view_alter($links_element, $items);

    $this->assertSame(
      ['layout-builder-block-update'],
      array_keys($links_element['#links'])
    );
  }

  /**
   * The Clone link is kept only for blocks marked during pre-render.
   *
   * @covers ::shouldKeepLink
   */
  public function testShouldKeepLink(): void {
    $this->assertTrue(CloneContextualLinks::shouldKeepLink([
      'metadata' => [CloneContextualLinks::METADATA_KEY => '1'],
    ]));
    $this->assertFalse(CloneContextualLinks::shouldKeepLink([
      'metadata' => ['operations' => 'move:update:remove'],
    ]));
    $this->assertFalse(CloneContextualLinks::shouldKeepLink([]));
  }

  /**
   * The pre-render callback is declared as a trusted callback.
   *
   * @covers ::trustedCallbacks
   */
  public function testPreRenderIsTrusted(): void {
    $this->assertContains(
      'preRender',
      CloneContextualLinks::trustedCallbacks()
    );
  }

}
