<?php

namespace Drupal\Tests\ys_servicenow\Unit\Plugin\migrate\process;

use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Layout\LayoutInterface;
use Drupal\Core\Layout\LayoutPluginManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\block_content\BlockContentInterface;
use Drupal\layout_builder\Section;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\Row;
use Drupal\ys_servicenow\Plugin\migrate\process\LayoutBuilderSections;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Unit tests for the LayoutBuilderSections migrate process plugin.
 *
 * @coversDefaultClass \Drupal\ys_servicenow\Plugin\migrate\process\LayoutBuilderSections
 *
 * @group yalesites
 * @group ys_servicenow
 */
class LayoutBuilderSectionsTest extends UnitTestCase {

  /**
   * Builds the plugin under test.
   */
  protected function createPlugin(): LayoutBuilderSections {
    return new LayoutBuilderSections([], 'layout_builder_sections', []);
  }

  /**
   * Mocks the layout plugin manager used by Section::getLayoutSettings().
   */
  protected function mockLayoutPluginManager(): LayoutPluginManagerInterface {
    $layout = $this->createMock(LayoutInterface::class);
    $layout->method('getConfiguration')->willReturn([]);

    $layout_plugin_manager = $this->createMock(LayoutPluginManagerInterface::class);
    $layout_plugin_manager->method('createInstance')->with('layout_onecol')->willReturn($layout);

    return $layout_plugin_manager;
  }

  /**
   * @covers ::transform
   */
  public function testTransformReturnsNullForNullValue() {
    $plugin = $this->createPlugin();

    $result = $plugin->transform(
      NULL,
      $this->createMock(MigrateExecutableInterface::class),
      $this->createMock(Row::class),
      'field_layout'
    );

    $this->assertNull($result);
  }

  /**
   * @covers ::transform
   */
  public function testTransformBuildsSectionFromMatchingBlock() {
    $block = $this->createMock(BlockContentInterface::class);
    $block->method('label')->willReturn('KB Article Block');
    $block->method('getRevisionId')->willReturn(42);

    $query = $this->createMock(QueryInterface::class);
    $query->method('condition')->with('info', 'KB Article Block')->willReturnSelf();
    $query->method('accessCheck')->with(FALSE)->willReturnSelf();
    $query->method('execute')->willReturn([7 => '7']);

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('getQuery')->willReturn($query);
    $storage->method('load')->with('7')->willReturn($block);

    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')->with('block_content')->willReturn($storage);

    $container = new ContainerBuilder();
    $container->set('uuid', $this->createMock(UuidInterface::class));
    $container->set('entity_type.manager', $entity_type_manager);
    // Section::toArray() calls getLayoutSettings(), which resolves the
    // layout plugin via this service to read back its configuration.
    $container->set('plugin.manager.core.layout', $this->mockLayoutPluginManager());
    \Drupal::setContainer($container);

    $plugin = $this->createPlugin();
    $result = $plugin->transform(
      'KB Article Block',
      $this->createMock(MigrateExecutableInterface::class),
      $this->createMock(Row::class),
      'field_layout'
    );

    $this->assertInstanceOf(Section::class, $result);

    $section_array = $result->toArray();
    $this->assertSame('layout_onecol', $section_array['layout_id']);

    $component = reset($section_array['components']);
    $this->assertSame('content', $component['region']);
    $this->assertSame('inline_block:text', $component['configuration']['id']);
    $this->assertSame('KB Article Block', $component['configuration']['label']);
    $this->assertSame('layout_builder', $component['configuration']['provider']);
    $this->assertFalse($component['configuration']['label_display']);
    $this->assertSame('full', $component['configuration']['view_mode']);
    $this->assertSame(42, $component['configuration']['block_revision_id']);
    $this->assertSame(serialize($block), $component['configuration']['block_serialized']);
  }

  /**
   * @covers ::transform
   */
  public function testTransformReturnsNullAndMessagesErrorWhenBlockNotFound() {
    $query = $this->createMock(QueryInterface::class);
    $query->method('condition')->willReturnSelf();
    $query->method('accessCheck')->willReturnSelf();
    $query->method('execute')->willReturn([]);

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('getQuery')->willReturn($query);

    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')->with('block_content')->willReturn($storage);

    $messenger = $this->createMock(MessengerInterface::class);
    $messenger->expects($this->once())
      ->method('addError')
      ->with('Could not load Missing Block ???');

    $container = new ContainerBuilder();
    $container->set('uuid', $this->createMock(UuidInterface::class));
    $container->set('entity_type.manager', $entity_type_manager);
    $container->set('messenger', $messenger);
    \Drupal::setContainer($container);

    $plugin = $this->createPlugin();
    $result = $plugin->transform(
      'Missing Block',
      $this->createMock(MigrateExecutableInterface::class),
      $this->createMock(Row::class),
      'field_layout'
    );

    $this->assertNull($result);
  }

  /**
   * @covers ::multiple
   */
  public function testMultipleReturnsFalse() {
    $plugin = $this->createPlugin();
    $this->assertFalse($plugin->multiple());
  }

}
