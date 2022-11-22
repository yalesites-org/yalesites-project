<?php

namespace Drupal\ys_embed\Controller;

use Drupal\Component\Utility\Html;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\OpenDialogCommand;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Render\RendererInterface;
use Drupal\ys_embed\Plugin\EmbedSourceManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for embed sources instruction dialog.
 */
class EmbedInstructionsController extends ControllerBase {

  /**
   * The embed source plugin manager service.
   *
   * @var \Drupal\ys_embed\Plugin\EmbedSourceManager
   */
  protected $embedManager;

  /**
   * The Drupal renderer service.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * Constructs the controller object.
   *
   * @param \Drupal\ys_embed\Plugin\EmbedSourceManager $embed_manager
   *   The embed source plugin manager service.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The Drupal renderer service.
   */
  public function __construct(EmbedSourceManager $embed_manager, RendererInterface $renderer) {
    $this->embedManager = $embed_manager;
    $this->renderer = $renderer;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('plugin.manager.embed_source'),
      $container->get('renderer')
    );
  }

  /**
   * Callback for opening the dialog form.
   */
  public function openDialog() {
    $response = new AjaxResponse();
    $response->addCommand(
      new OpenDialogCommand(
        '#ajax-embed-instructions',
        'Adding Embeded Media',
        $this->content(),
        [
          'width' => '75%',
          'autoResize' => TRUE,
        ],
      )
    );
    return $response;
  }

  /**
   * Get the contents of the embed instructions page.
   *
   * @return \Drupal\Component\Render\MarkupInterface
   *   The rendered HTML.
   */
  public function content() {
    $sources = [];
    foreach ($this->embedManager->getSources() as $id => $source) {
      $sources[$id]['name'] = $source['label'];
      $sources[$id]['instructions'] = $source['class']::getInstructions();
      $sources[$id]['example'] = [
        '#open' => FALSE,
        '#type' => 'details',
        '#title' => 'Example',
      ];
      $sources[$id]['example']['code'] = [
        '#markup' => Html::escape($source['class']::getExample()),
      ];
    }
    $content = [
      '#theme' => 'embed_instructions',
      '#sources' => $sources,
    ];
    return $this->renderer->render($content);
  }

}
