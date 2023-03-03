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

      /*
       * Sets the arguments that will get passed to contextual filters as well
       * as to the custom sort plugin (ViewsBasicSort), custom style
       * plugin (ViewsBasicDynamicStyle), and custom pager
       * (ViewsBasicFullPager).
       *
       * This is coded this way to work with Ajax pagers specifically as
       * without arguments, the subsequent Ajax calls to load more data do not
       * know what sorting, filters, view modes, or number if items in the pager
       * to use.
       *
       * The order of these arguments is required as follows (and is
       * automatically taken care of from paragraph params data):
       * 1) Content type machine name (can combine content types with + or ,)
       * 2) Taxonomy term ID (can combine with + or ,)
       * 3) Sort field and direction (in format field_name:ASC)
       * 4) View mode machine name (i.e. teaser)
       * 5) Items per page (set to 0 for all items)
       */

      $filterType = implode('+', $paramsDecoded['filters']['types']);

      if (isset($paramsDecoded['filters']['tags'])) {
        $filterTags = implode('+', $paramsDecoded['filters']['tags']);
      }
      else {
        $filterTags = 'all';
      }

      $view->setArguments(
        [
          'type' => $filterType,
          'tags' => $filterTags,
          'sort' => $paramsDecoded['sort_by'],
          'view' => $paramsDecoded['view_mode'],
          'items' => $paramsDecoded['limit'],
        ]
      );

      $view->execute();

      // Unset the pager. Needs to be done after view->execute();
      if (!$paramsDecoded['pager']) {
        unset($view->pager);
      }

      $rendered = $view->preview();

      // End current view run.
      $running = FALSE;

      $elements[$delta] = [
        '#theme' => 'views_basic_formatter_default',
        '#view' => $rendered,
      ];
    }

    return $elements;
  }

}
