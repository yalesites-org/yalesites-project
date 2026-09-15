<?php

namespace Drupal\ys_layouts\Controller;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\layout_builder\Controller\LayoutRebuildTrait;
use Drupal\layout_builder\LayoutTempstoreRepositoryInterface;
use Drupal\layout_builder\SectionStorageInterface;
use Drupal\ys_layouts\Service\BlockCloner;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Clones an inline block in Layout Builder via the block contextual menu.
 *
 * Mirrors core's MoveBlockController: a plain AJAX controller that mutates the
 * layout tempstore and rebuilds the Layout Builder UI in place (issue #190).
 */
class CloneBlockController implements ContainerInjectionInterface {

  use LayoutRebuildTrait;
  use StringTranslationTrait;

  /**
   * Constructs a CloneBlockController.
   *
   * @param \Drupal\layout_builder\LayoutTempstoreRepositoryInterface $layoutTempstoreRepository
   *   The layout tempstore repository.
   * @param \Drupal\ys_layouts\Service\BlockCloner $blockCloner
   *   The block cloner service.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   */
  public function __construct(
    protected LayoutTempstoreRepositoryInterface $layoutTempstoreRepository,
    protected BlockCloner $blockCloner,
    protected MessengerInterface $messenger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('layout_builder.tempstore_repository'),
      $container->get('ys_layouts.block_cloner'),
      $container->get('messenger'),
    );
  }

  /**
   * Clones the given block and rebuilds the layout.
   *
   * @param \Drupal\layout_builder\SectionStorageInterface $section_storage
   *   The section storage.
   * @param int $delta
   *   The delta of the section containing the block.
   * @param string $region
   *   The region of the block (part of the contextual link route, unused here
   *   because the clone stays in the original block's region).
   * @param string $uuid
   *   The UUID of the block to clone.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   An AJAX response that rebuilds the Layout Builder UI.
   */
  public function build(SectionStorageInterface $section_storage, int $delta, string $region, string $uuid) {
    $section = $section_storage->getSection($delta);

    if ($this->blockCloner->cloneComponent($section, $uuid)) {
      $this->layoutTempstoreRepository->set($section_storage);
    }
    else {
      $this->messenger->addWarning($this->t('This block cannot be cloned. Reusable blocks are shared across placements, so they are excluded from cloning.'));
    }

    // The clone is triggered from a contextual link (not an off-canvas dialog),
    // so rebuild the layout in place without a dialog-close command.
    return $this->rebuildLayout($section_storage);
  }

}
