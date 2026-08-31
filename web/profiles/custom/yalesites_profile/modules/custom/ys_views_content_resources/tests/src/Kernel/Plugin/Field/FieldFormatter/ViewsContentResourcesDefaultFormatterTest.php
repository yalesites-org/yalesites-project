<?php

namespace Drupal\Tests\ys_views_content_resources\Kernel\Plugin\Field\FieldFormatter;

use Drupal\KernelTests\KernelTestBase;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\ys_views_content_resources\ViewsContentResourcesManager;

/**
 * Kernel tests for the ViewsContentResourcesDefaultFormatter.
 *
 * Uses a real entity_test field item list (so the formatter's real
 * viewElements() argument type is satisfied) but swaps the manager service
 * for a mock, since exercising a real "content_resources" Views display is
 * out of scope -- see the module's test log.
 *
 * @coversDefaultClass \Drupal\ys_views_content_resources\Plugin\Field\FieldFormatter\ViewsContentResourcesDefaultFormatter
 * @group ys_views_content_resources
 * @group yalesites
 */
class ViewsContentResourcesDefaultFormatterTest extends KernelTestBase {

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
   * ViewElements() themes the manager's rendered view for each delta.
   *
   * @covers ::viewElements
   */
  public function testViewElementsBuildsRenderArrayFromManagerView() {
    $exposedWidgets = ['#markup' => 'exposed filters go here'];
    $viewObject = (object) ['exposed_widgets' => $exposedWidgets];
    $renderedView = ['#view' => $viewObject, '#rows' => []];

    $manager = $this->createMock(ViewsContentResourcesManager::class);
    $manager->expects($this->once())
      ->method('getView')
      ->with('rendered', '{"view_mode":"card"}')
      ->willReturn($renderedView);
    $this->container->set('ys_views_content_resources.views_content_resources_manager', $manager);

    $entity = \Drupal::entityTypeManager()->getStorage('entity_test')->create([
      'field_vcr_params' => ['params' => '{"view_mode":"card"}'],
    ]);

    $formatter = \Drupal::service('plugin.manager.field.formatter')->createInstance(
      'views_content_resources_default_formatter',
      [
        'field_definition' => $entity->get('field_vcr_params')->getFieldDefinition(),
        'settings' => [],
        'label' => 'above',
        'view_mode' => 'default',
        'third_party_settings' => [],
      ]
    );

    $elements = $formatter->viewElements($entity->get('field_vcr_params'), 'en');

    $this->assertSame('views_basic_formatter_default', $elements[0]['#theme']);
    $this->assertSame($renderedView, $elements[0]['#view']);
    $this->assertSame($exposedWidgets, $elements[0]['#exposed']);
  }

}
