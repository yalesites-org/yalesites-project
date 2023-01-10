<?php

namespace Drupal\ys_views_basic\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\ys_views_basic\ViewsBasicManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\views\Views;
use Drupal\Core\Render\Renderer;

/**
 * Plugin implementation of the 'views_basic_default' formatter.
 *
 * @FieldFormatter(
 *   id = "views_basic_default_formatter",
 *   label = @Translation("Views Basic View"),
 *   field_types = {
 *     "views_basic_params"
 *   }
 * )
 */
class ViewsBasicDefaultFormatter extends FormatterBase implements ContainerFactoryPluginInterface {

  /**
   * The views basic manager service.
   *
   * @var \Drupal\ys_views_basic\ViewsBasicManager
   */
  protected $viewsBasicManager;

  /**
   * The renderer service.
   *
   * @var \Drupal\Core\Render\Renderer
   */
  protected $rendererService;

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
   * @param \Drupal\ys_views_basic\Plugin\ViewsBasicManager $viewsBasicManager
   *   The views basic manager service.
   * @param \Drupal\Core\Render\Renderer $renderer_service
   *   Drupal Core renderer service.
   */
  public function __construct(
    string $plugin_id,
    $plugin_definition,
    FieldDefinitionInterface $field_definition,
    array $settings,
    string $label,
    string $view_mode,
    array $third_party_settings,
    ViewsBasicManager $viewsBasicManager,
    Renderer $renderer_service
  ) {
    parent::__construct(
      $plugin_id,
      $plugin_definition,
      $field_definition,
      $settings,
      $label,
      $view_mode,
      $third_party_settings,
      $this->rendererService = $renderer_service,
    );
    $this->viewsBasicManager = $viewsBasicManager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition
  ) {
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['field_definition'],
      $configuration['settings'],
      $configuration['label'],
      $configuration['view_mode'],
      $configuration['third_party_settings'],
      $container->get('ys_views_basic.views_basic_manager'),
      $container->get('renderer'),
    );
  }

  /**
   * Define how the field type is showed.
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {

    $elements = [];
    foreach ($items as $delta => $item) {

      // Prevents views recursion.
      static $running;
      if ($running) {
        return $elements;
      }
      $running = TRUE;

      // Set up the view and initial decoded parameters.
      $view = Views::getView('views_basic_scaffold');
      $view->setDisplay('block_1');
      $paramsDecoded = json_decode($item->getValue()['params'], TRUE);

      // Overrides filters using our custom views filter - ViewsBasicFilter.
      $filters = $view->display_handler->getOption('filters');
      $filters['views_basic_filter']['value'] = $paramsDecoded;
      $view->display_handler->overrideOption('filters', $filters);

      // Change view mode.
      $view->build();
      $view->rowPlugin->options['view_mode'] = $paramsDecoded['view_mode'];

      // Execute and render the view.
      $view->execute();
      $rendered = $view->render();
      $output = $this->rendererService->render($rendered);

      // End current view run.
      $running = FALSE;

      $elements[$delta] = [
        '#theme' => 'views_basic_formatter_default',
        '#view' => $output,
      ];
    }

    return $elements;
  }

}
