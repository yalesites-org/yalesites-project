<?php

namespace Drupal\ys_embed\Controller;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\OpenModalDialogCommand;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Render\RendererInterface;
use Drupal\ys_embed\Plugin\EmbedSourceManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for embed sources instruction modal dialog.
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
   * The EmbedInstructionsController constructor.
   *
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer.
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
   * Callback for opening the modal form.
   */
  public function openModal() {
    $response = new AjaxResponse();
    $response->addCommand(
      new OpenModalDialogCommand(
        'Adding Embeded Media',
        $this->content(),
        ['width' => '800']
      )
    );
    return $response;
  }

  /**
   * Get the contents of the embed insutrctions page.
   *
   * @return \Drupal\Component\Render\MarkupInterface
   *   The rendered HTML.
   */
  public function content() {
    $sources = [];
    foreach ($this->embedManager->getSources() as $id => $source) {
      $sources[$id]['name'] = $source['label'];
      $sources[$id]['instructions'] = $source['class']::getInstructions();
      $sources[$id]['example'] = $source['class']::getExample();
    }
    $content = [
      '#theme' => 'embed_instructions',
      '#sources' => $sources,
    ];
    return $this->renderer->render($content);
  }

}
