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
  public function __construct(
    ConfigFactoryInterface $config_factory,
  ) {
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
    );
  }

  /**
   * Dynamic search page to show CAS titles or not.
   *
   * @param Symfony\Component\HttpFoundation\Request $request
   *   The Drupal request.
   */
  public function searchPage(Request $request) {
    // Only show CAS titles if it is enabled.
    $config = $this->configFactory->get('ys_core.header_settings');
    $view_name = $config->get('search.enable_cas_search') && $config->get('search.enable_search_form') ? 'search_cas' : 'search';

    $view = Views::getView($view_name);
    if ($view) {
      $view->setDisplay('default');

      // Retrieve the 'keywords' parameter from the request, if it exists.
      $keywords = $request->query->get('keywords');
      if ($keywords) {
        // Pass keywords to contextual filter.
        $view->setArguments([$keywords]);
      }

      // Execute the view and render it.
      $view->preExecute();
      $view->execute();

      return [
        '#title' => $this->getTitle($request, TRUE),
        'view' => $view->render(),
        '#cache' => [
          'contexts' => ['url.query_args:keywords'],
        ],
      ];
    }

    return [
      '#markup' => $this->t('No search view available.'),
    ];
  }

  /**
   * Title callback to set <title> tag and H1 page title.
   *
   * @param Symfony\Component\HttpFoundation\Request $request
   *   The Drupal request.
   * @param bool $withMarkup
   *   If set, outputs the title with formatting for use with H1 tag.
   *
   * @return Drupal\Core\StringTranslation\TranslatableMarkup
   *   Drupal's translatable markup for the title.
   */
  public function getTitle(Request $request, $withMarkup = FALSE) {
    $keywords = $request->query->get('keywords');

    if (!$keywords) {
      return $this->t('Search');
    }

    if ($withMarkup) {
      $keywords = Markup::create('<em>' . $this->t('@keywords', ['@keywords' => $keywords]) . '</em>');
    }
    $title = $this->t('Search results: @keywords', ['@keywords' => $keywords]);

    return $title;
  }

}
