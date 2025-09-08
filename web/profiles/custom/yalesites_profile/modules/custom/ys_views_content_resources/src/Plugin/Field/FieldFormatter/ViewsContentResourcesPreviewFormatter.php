<?php

namespace Drupal\ys_views_content_resources\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\ys_views_content_resources\ViewsContentResourcesManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the 'views_content_resources_preview' formatter.
 *
 * @FieldFormatter(
 *   id = "views_content_resources_preview_formatter",
 *   label = @Translation("Views Content Resources Settings Overview"),
 *   field_types = {
 *     "views_content_resources_params"
 *   }
 * )
 */
class ViewsContentResourcesPreviewFormatter extends FormatterBase implements ContainerFactoryPluginInterface {

  /**
   * The views basic manager service.
   *
   * @var \Drupal\ys_views_basic\ViewsContentResourcesManager
   */
  protected $viewsContentResourcesManager;

  /**
   * Constructs an views basic default formatter object.
   *
   * @param string $plugin_id
   *   The plugin_id for the formatter.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   The definition of the field to which the formatter is associated.
   * @param array $settings
   *   The formatter settings.
   * @param string $label
   *   The formatter label display setting.
   * @param string $view_mode
   *   The view mode.
   * @param array $third_party_settings
   *   Any third party settings.
   * @param \Drupal\ys_views_basic\Plugin\ViewsContentResourcesManager $viewsContentResourcesManager
   *   The views basic manager service.
   */
  public function __construct(
    string $plugin_id,
    $plugin_definition,
    FieldDefinitionInterface $field_definition,
    array $settings,
    string $label,
    string $view_mode,
    array $third_party_settings,
    ViewsContentResourcesManager $viewsContentResourcesManager,
  ) {
    parent::__construct(
      $plugin_id,
      $plugin_definition,
      $field_definition,
      $settings,
      $label,
      $view_mode,
      $third_party_settings,
    );
    $this->viewsContentResourcesManager = $viewsContentResourcesManager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition,
  ) {
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['field_definition'],
      $configuration['settings'],
      $configuration['label'],
      $configuration['view_mode'],
      $configuration['third_party_settings'],
      $container->get('ys_views_content_resources.views_content_resources_manager'),
    );
  }

  /**
   * Define how the field type is showed.
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {

    $elements = [];
    foreach ($items as $delta => $item) {
      $paramsForRender = [
        'types' => [],
        'view_mode' => '',
        'tags' => [],
        'display' => [],
        'limit' => '',
        'sort_by' => '',
        'pager' => '',
      ];
      $paramsDecoded = json_decode($item->params, TRUE);

      // Gets the entity labels and view modes.
      foreach ($paramsDecoded['filters']['types'] as $type) {
        $entityLabel = $this->viewsContentResourcesManager->getLabel($type, 'entity');
        array_push($paramsForRender['types'], $entityLabel);
        $viewModeLabel = $this->viewsContentResourcesManager->getLabel($type, 'view_modes', $paramsDecoded['view_mode']);
        $sortByLabel = $this->viewsContentResourcesManager->getLabel($type, 'sort_by', $paramsDecoded['sort_by']);
        $paramsForRender['view_mode'] = $viewModeLabel;
        $paramsForRender['sort_by'] = $sortByLabel;
      }

      // Gets the tag labels.
      if (!empty($paramsDecoded['filters']['tags'][0])) {
        foreach ($paramsDecoded['filters']['tags'] as $tag) {
          $tagLabel = $this->viewsContentResourcesManager->getTagLabel($tag);
          array_push($paramsForRender['tags'], $tagLabel);
        }
      }

      // Gets the display.
      $paramsForRender['display'] = $paramsDecoded['display'];

      // Gets the limit.
      $paramsForRender['limit'] = $paramsDecoded['limit'];

      // Gets the number of results for the view.
      $paramsForRender['count'] = $this->viewsContentResourcesManager->getView('count', $item->params);

      $elements[$delta] = [
        '#theme' => 'views_basic_formatter_preview',
        '#params' => $paramsForRender,
      ];
    }

    return $elements;
  }

}
