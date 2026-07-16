<?php

namespace Drupal\Tests\ys_views_content_resources\Kernel\Plugin\Field\FieldFormatter;

use Drupal\KernelTests\KernelTestBase;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\ys_views_content_resources\ViewsContentResourcesManager;

/**
 * Kernel tests for the ViewsContentResourcesPreviewFormatter.
 *
 * Uses a real entity_test field item list but swaps the manager service for
 * a mock, since exercising a real "content_resources" Views count query is
 * out of scope -- see the module's test log.
 *
 * @coversDefaultClass \Drupal\ys_views_content_resources\Plugin\Field\FieldFormatter\ViewsContentResourcesPreviewFormatter
 * @group ys_views_content_resources
 * @group yalesites
 */
class ViewsContentResourcesPreviewFormatterTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'views',
    'ys_views_basic',
    'path_alias',
    'ys_views_content_resources',
    'entity_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('entity_test');

    FieldStorageConfig::create([
      'field_name' => 'field_vcr_params',
      'entity_type' => 'entity_test',
      'type' => 'views_content_resources_params',
    ])->save();

    FieldConfig::create([
      'field_name' => 'field_vcr_params',
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'label' => 'Resource view params',
    ])->save();
  }

  /**
   * Builds the formatter through the container, with the given manager mock.
   *
   * @param \Drupal\ys_views_content_resources\ViewsContentResourcesManager $manager
   *   The (mocked) manager service to inject.
   * @param string $params
   *   The raw stored params string.
   *
   * @return array
   *   The render array returned by viewElements().
   */
  protected function renderPreview($manager, string $params): array {
    $this->container->set('ys_views_content_resources.views_content_resources_manager', $manager);

    $entity = \Drupal::entityTypeManager()->getStorage('entity_test')->create([
      'field_vcr_params' => ['params' => $params],
    ]);

    $formatter = \Drupal::service('plugin.manager.field.formatter')->createInstance(
      'views_content_resources_preview_formatter',
      [
        'field_definition' => $entity->get('field_vcr_params')->getFieldDefinition(),
        'settings' => [],
        'label' => 'above',
        'view_mode' => 'default',
        'third_party_settings' => [],
      ]
    );

    return $formatter->viewElements($entity->get('field_vcr_params'), 'en');
  }

  /**
   * ViewElements() builds a preview summary from the manager's labels/count.
   *
   * @covers ::viewElements
   */
  public function testViewElementsBuildsPreviewSummary() {
    $manager = $this->createMock(ViewsContentResourcesManager::class);
    $manager->method('getLabel')->willReturn('Resources');
    $manager->method('getView')->with('count', $this->anything())->willReturn(7);

    $params = json_encode([
      'filters' => ['types' => ['resource']],
      'view_mode' => 'card',
      'sort_by' => 'field_publish_date:DESC',
      'display' => 'limit',
      'limit' => 5,
    ]);

    $elements = $this->renderPreview($manager, $params);

    $this->assertSame('views_basic_formatter_preview', $elements[0]['#theme']);
    $preview = $elements[0]['#params'];
    $this->assertSame(['Resources'], $preview['types']);
    $this->assertSame('Resources', $preview['view_mode']);
    $this->assertSame('Resources', $preview['sort_by']);
    $this->assertSame('limit', $preview['display']);
    $this->assertSame(5, $preview['limit']);
    $this->assertSame(7, $preview['count']);
    $this->assertSame([], $preview['tags']);
  }

  /**
   * ViewElements() resolves each tag ID to a label via getTagLabel().
   *
   * @covers ::viewElements
   */
  public function testViewElementsResolvesTagLabels() {
    $manager = $this->createMock(ViewsContentResourcesManager::class);
    $manager->method('getLabel')->willReturn('Resources');
    $manager->method('getView')->willReturn(0);
    $manager->method('getTagLabel')->willReturnMap([
      [5, 'Music'],
      [9, 'Lectures'],
    ]);

    $params = json_encode([
      'filters' => [
        'types' => ['resource'],
        'tags' => [5, 9],
      ],
      'view_mode' => 'card',
      'sort_by' => 'field_publish_date:DESC',
      'display' => 'all',
      'limit' => 10,
    ]);

    $elements = $this->renderPreview($manager, $params);

    $this->assertSame(['Music', 'Lectures'], $elements[0]['#params']['tags']);
  }

}
