<?php

namespace Drupal\ys_layouts\Controller;

use Drupal\Core\Ajax\AjaxHelperTrait;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\layout_builder\Controller\LayoutRebuildTrait;
use Drupal\layout_builder\LayoutTempstoreRepositoryInterface;
use Drupal\layout_builder\SectionStorageInterface;
use Drupal\ys_layouts\Service\SectionCloner;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Clones a whole section in Layout Builder from the section toolbar.
 *
 * Mirrors CloneBlockController one level up: a plain AJAX controller that
 * mutates the layout tempstore and rebuilds the Layout Builder UI in place
 * (issue #1638). Writing through the tempstore is what lets an editor discard
 * the clone like any other unsaved layout change.
 *
 * @see \Drupal\ys_layouts\Service\SectionCloner
 * @see \Drupal\ys_layouts\SectionCloneLinkBuilder
 */
class CloneSectionController implements ContainerInjectionInterface {

  use AjaxHelperTrait;
  use LayoutRebuildTrait;
  use StringTranslationTrait;

  /**
   * Constructs a CloneSectionController.
   *
   * @param \Drupal\layout_builder\LayoutTempstoreRepositoryInterface $layoutTempstoreRepository
   *   The layout tempstore repository.
   * @param \Drupal\ys_layouts\Service\SectionCloner $sectionCloner
   *   The section cloner service.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   */
  public function __construct(
    protected LayoutTempstoreRepositoryInterface $layoutTempstoreRepository,
    protected SectionCloner $sectionCloner,
    protected MessengerInterface $messenger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('layout_builder.tempstore_repository'),
      $container->get('ys_layouts.section_cloner'),
      $container->get('messenger'),
    );
  }

  /**
   * Clones the given section and rebuilds the layout.
   *
   * @param \Drupal\layout_builder\SectionStorageInterface $section_storage
   *   The section storage.
   * @param int $delta
   *   The delta of the section to clone.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse|\Symfony\Component\HttpFoundation\RedirectResponse
   *   An AJAX response that rebuilds the Layout Builder UI, or a redirect back
   *   to it when the route was reached outside the AJAX pipeline.
   */
  public function build(SectionStorageInterface $section_storage, int $delta) {
    $original_count = count($section_storage->getSection($delta)->getComponents());
    $clone = $this->sectionCloner->cloneSection($section_storage, $delta);
    $this->layoutTempstoreRepository->set($section_storage);

    // A block is only ever left out when its content cannot be resolved, which
    // is a data anomaly rather than routine. Tell the editor rather than hand
    // back a quietly incomplete copy: the block is still in the original, so
    // the copy is recoverable, but only if they know to look.
    $dropped = $original_count - count($clone->getComponents());
    if ($dropped > 0) {
      $this->messenger->addWarning($this->formatPlural(
        $dropped,
        '1 block could not be copied into the cloned section and was left out.',
        '@count blocks could not be copied into the cloned section and were left out.'
      ));
    }

    // The clone is triggered from a toolbar link rather than an off-canvas
    // dialog, so rebuild the layout in place without a dialog-close command.
    // Reached directly in a browser there is no AJAX pipeline to render into,
    // so fall back to a redirect the way core's AddSectionController does.
    if ($this->isAjax()) {
      return $this->rebuildLayout($section_storage);
    }

    return new RedirectResponse($section_storage->getLayoutBuilderUrl()->setAbsolute()->toString());
  }

}
