<?php

namespace Drupal\ys_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Render\RendererInterface;
use Drupal\ys_core\FactsAndFiguresIconManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Controller for Facts and Figures icon preview functionality.
 */
class FactsIconPreviewController extends ControllerBase {

  /**
   * The renderer service.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * The Facts and Figures icon manager.
   *
   * @var \Drupal\ys_core\FactsAndFiguresIconManager
   */
  protected $iconManager;

  /**
   * The logger channel factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * Constructs a new FactsIconPreviewController object.
   *
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer service.
   * @param \Drupal\ys_core\FactsAndFiguresIconManager $icon_manager
   *   The Facts and Figures icon manager.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger channel factory.
   */
  public function __construct(RendererInterface $renderer, FactsAndFiguresIconManager $icon_manager, LoggerChannelFactoryInterface $logger_factory) {
    $this->renderer = $renderer;
    $this->iconManager = $icon_manager;
    $this->loggerFactory = $logger_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('renderer'),
      $container->get('ys_core.facts_and_figures_icon_manager'),
      $container->get('logger.factory')
    );
  }

  /**
   * Returns rendered icon HTML for preview.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with rendered icon HTML.
   */
  public function renderIcon(Request $request) {
    $icon_name = $request->query->get('icon');

    // Validate that the icon exists in our allowed list.
    if (!$icon_name || !$this->iconManager->isValidIcon($icon_name)) {
      return new JsonResponse([
        'error' => 'Invalid icon name',
        'html' => '',
      ], 400);
    }

    // Handle the "none" case.
    if ($icon_name === '_none') {
      return new JsonResponse([
        'html' => '<div class="no-icon-selected">No icon selected</div>',
      ]);
    }

    // Create render array using the same _yds-icon.twig template as frontend.
    $render_array = [
      '#type' => 'inline_template',
      '#template' => '{% include "@atoms/images/icons/_yds-icon.twig" with {
        icon__name: icon_name,
        icon__base_class: "facts-and-figures-icon",
        icon__blockname: "facts-and-figures",
        icon__decorative: true,
        directory: "themes/contrib/atomic",
      } %}',
      '#context' => [
        'icon_name' => $icon_name,
      ],
    ];

    try {
      $icon_html = $this->renderer->renderPlain($render_array);

      return new JsonResponse([
        'html' => $icon_html,
        'icon_name' => $icon_name,
      ]);
    }
    catch (\Exception $e) {
      // Fallback if twig rendering fails.
      $this->loggerFactory->get('ys_core')->error('Icon rendering failed: @error', ['@error' => $e->getMessage()]);

      return new JsonResponse([
        'html' => '<div class="icon-fallback">[Icon: ' . $icon_name . ']</div>',
        'icon_name' => $icon_name,
        'error' => 'Rendering failed, showing fallback',
      ]);
    }
  }

}
