<?php

namespace Drupal\ys_layouts\Controller;

use Drupal\Core\Ajax\AnnounceCommand;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\layout_builder\Controller\LayoutRebuildTrait;
use Drupal\layout_builder\LayoutTempstoreRepositoryInterface;
use Drupal\layout_builder\SectionStorageInterface;
use Drupal\ys_layouts\Service\BlockCloner;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Clones a single Layout Builder block in place.
 *
 * Modelled on core's MoveBlockController: the component is copied into the
 * layout tempstore and the Layout Builder element is re-rendered over AJAX, so
 * cloning is a single click with no dialog and no page reload. Nothing is
 * written to the database here — the editor still has to press "Save layout"
 * (or "Discard changes"), which is what creates the cloned block content and
 * its usage record.
 *
 * @see \Drupal\layout_builder\Controller\MoveBlockController
 * @see \Drupal\layout_builder\InlineBlockEntityOperations::handlePreSave()
 */
class CloneBlockController implements ContainerInjectionInterface {

  use LayoutRebuildTrait;
  use StringTranslationTrait;

  /**
   * The layout tempstore repository.
   *
   * @var \Drupal\layout_builder\LayoutTempstoreRepositoryInterface
   */
  protected $layoutTempstoreRepository;

  /**
   * The block cloner service.
   *
   * @var \Drupal\ys_layouts\Service\BlockCloner
   */
  protected $blockCloner;

  /**
   * Constructs a new CloneBlockController object.
   *
   * @param \Drupal\layout_builder\LayoutTempstoreRepositoryInterface $layout_tempstore_repository
   *   The layout tempstore repository.
   * @param \Drupal\ys_layouts\Service\BlockCloner $block_cloner
   *   The block cloner service.
   */
  public function __construct(
    LayoutTempstoreRepositoryInterface $layout_tempstore_repository,
    BlockCloner $block_cloner,
  ) {
    $this->layoutTempstoreRepository = $layout_tempstore_repository;
    $this->blockCloner = $block_cloner;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('layout_builder.tempstore_repository'),
      $container->get('ys_layouts.block_cloner')
    );
  }

  /**
   * Clones the given block directly after itself.
   *
   * @param \Drupal\layout_builder\SectionStorageInterface $section_storage
   *   The section storage.
   * @param int $delta
   *   The delta of the section holding the block.
   * @param string $region
   *   The region of the block. Unused: the region is read from the component
   *   itself so the copy always lands beside the original, but the parameter
   *   is part of the contextual link route and must be accepted.
   * @param string $uuid
   *   The UUID of the component being cloned.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   An AJAX response that re-renders the Layout Builder element.
   */
  public function build(
    SectionStorageInterface $section_storage,
    int $delta,
    string $region,
    string $uuid,
  ) {
    try {
      $section = $section_storage->getSection($delta);
      $component = $section->getComponent($uuid);
    }
    catch (\OutOfBoundsException | \InvalidArgumentException $e) {
      throw new NotFoundHttpException($e->getMessage());
    }

    // Defense in depth: the contextual link is hidden and the route is guarded
    // by an access check, but never trust either to be the only gate.
    if (!$this->blockCloner->isClonable($component)) {
      throw new AccessDeniedHttpException(
        'Only inline blocks can be cloned.'
      );
    }

    try {
      $clone = $this->blockCloner->cloneComponent($component);
    }
    catch (\InvalidArgumentException $e) {
      throw new BadRequestHttpException($e->getMessage());
    }

    // The clone carries the original's region, which insertAfterComponent()
    // requires in order to find the preceding component. Weights of the
    // following components are rebalanced by insertComponent().
    $section->insertAfterComponent($uuid, $clone);

    $this->layoutTempstoreRepository->set($section_storage);

    // Replacing the whole layout gives assistive technology no clue that
    // anything happened, so announce the new block the way core announces
    // drag-and-drop moves.
    $response = $this->rebuildLayout($section_storage);
    $response->addCommand(new AnnounceCommand(
      $this->t('Block cloned. The copy was added below the original.')
    ));

    return $response;
  }

}
