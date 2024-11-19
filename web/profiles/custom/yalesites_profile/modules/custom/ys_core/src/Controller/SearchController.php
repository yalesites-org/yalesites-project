<?php

namespace Drupal\ys_core\Controller;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Render\Markup;
use Drupal\views\Views;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for the search page.
 */
class SearchController extends ControllerBase {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constructs a SearchController object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory service.
   */
  public function __construct(ConfigFactoryInterface $config_factory) {
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory')
    );
  }

  /**
   * Dynamic search page to show CAS titles or not.
   */
  public function searchPage(Request $request) {
    // Check your configuration value to decide which view to render.
    $config = $this->configFactory->get('ys_core.header_settings');
    $view_name = $config->get('search.enable_cas_search') ? 'search_cas' : 'search';

    $view = Views::getView($view_name);
    if ($view) {
      // Set the display (e.g., 'default' display or a custom display).
      $view->setDisplay('default');

      // Retrieve the 'keywords' parameter from the request, if it exists.
      $keywords = $request->query->get('keywords');
      if ($keywords) {
        // If your view has a contextual filter for keywords, pass it here.
        $view->setArguments([$keywords]);
        $keywords_markup = Markup::create('<em>' . $this->t('@keywords', ['@keywords' => $keywords]) . '</em>');
        $title = $this->t('Search results: @keywords', ['@keywords' => $keywords_markup]);
      }
      else {
        $title = $this->t('Search');
      }

      // Execute the view and render it.
      $view->preExecute();
      $view->execute();

      return [
        '#title' => $title,
        'view' => $view->render(),
        '#cache' => [
          'contexts' => ['url.query_args:keywords'],
        ],
      ];
    }

    return [
      '#markup' => $this->t('No view available.'),
    ];
  }

}
