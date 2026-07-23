<?php

declare(strict_types=1);

namespace Drupal\ys_layouts\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\layout_builder\Form\LayoutRebuildConfirmFormBase;
use Drupal\layout_builder\LayoutTempstoreRepositoryInterface;
use Drupal\layout_builder\SectionStorageInterface;
use Drupal\ys_layouts\ReusableBlockDetacher;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Confirms detaching a reusable block into an independent inline block.
 *
 * @internal
 *   Form classes are internal.
 */
class DetachReusableBlockForm extends LayoutRebuildConfirmFormBase {

  /**
   * The uuid of the block being detached.
   *
   * @var string
   */
  protected $uuid;

  public function __construct(
    LayoutTempstoreRepositoryInterface $layout_tempstore_repository,
    protected ReusableBlockDetacher $detacher,
  ) {
    parent::__construct($layout_tempstore_repository);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('layout_builder.tempstore_repository'),
      $container->get('ys_layouts.reusable_block_detacher'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ys_layouts_detach_reusable_block';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    $label = $this->sectionStorage
      ->getSection($this->delta)
      ->getComponent($this->uuid)
      ->getPlugin()
      ->label();
    return $this->t('Make the %label block non-reusable on this page?', ['%label' => $label]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('This placement becomes an independent copy you can edit here without affecting this block anywhere else it is used. If this block is placed on other pages, those placements are untouched and stay reusable - you can detach them separately if needed. This cannot be undone.');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Make non-reusable');
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?SectionStorageInterface $section_storage = NULL, $delta = NULL, $region = NULL, $uuid = NULL) {
    // $region is part of the route path (matching core's layout_builder_block
    // contextual-link group) but is not needed to detach; only the uuid is.
    $this->uuid = $uuid;
    return parent::buildForm($form, $form_state, $section_storage, $delta);
  }

  /**
   * {@inheritdoc}
   */
  protected function handleSectionStorage(SectionStorageInterface $section_storage, FormStateInterface $form_state) {
    $component = $section_storage->getSection($this->delta)->getComponent($this->uuid);
    $this->detacher->detach($component);
  }

}
