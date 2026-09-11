<?php

namespace Drupal\ys_layouts\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\ys_layouts\Service\OrphanedInlineBlockCleanerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Confirms deletion of every orphaned Layout Builder inline block.
 *
 * The equivalent of `drush ys-layouts:orphaned-blocks --delete`, reachable from
 * the report screen so a site does not need terminal access to be swept.
 *
 * It confirms "delete all orphans" rather than a selection on purpose:
 * OrphanedInlineBlockCleaner::deleteOrphans() accepts no IDs and re-derives the
 * list itself, which is what makes deleting a block that is still on a page
 * structurally impossible rather than merely guarded against. Offering
 * checkboxes here would hand that guarantee back to the caller.
 *
 * @see \Drupal\ys_layouts\Controller\OrphanedInlineBlockReportController
 */
class OrphanedInlineBlockDeleteForm extends ConfirmFormBase {

  /**
   * Constructs an OrphanedInlineBlockDeleteForm.
   *
   * @param \Drupal\ys_layouts\Service\OrphanedInlineBlockCleanerInterface $cleaner
   *   The orphaned inline block cleaner.
   */
  public function __construct(
    protected OrphanedInlineBlockCleanerInterface $cleaner,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('ys_layouts.orphaned_inline_block_cleaner'));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ys_layouts_orphaned_inline_block_delete';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): TranslatableMarkup {
    // No count in the question: the list is re-derived when you confirm, so any
    // number shown here could already be out of date by then.
    return $this->t('Delete every orphaned inline block on this site?');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): TranslatableMarkup {
    return $this->t("Only blocks that no page layout references are deleted, and the list is worked out again at the moment you confirm — so a block that has since been put back on a page is left alone. Blocks held only by an older revision of a page are never deleted. Reusable blocks from the block library are never touched. This cannot be undone.");
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText(): TranslatableMarkup {
    return $this->t('Delete orphaned blocks');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return Url::fromRoute('ys_layouts.orphaned_blocks_report');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $deleted = $this->cleaner->deleteOrphans();

    // Deleting nothing is a normal outcome — someone may have swept already, or
    // the last orphan may have been re-referenced — so it still gets reported
    // rather than looking like the action did not run.
    $this->messenger()->addStatus($deleted
      ? $this->formatPlural($deleted, 'Deleted 1 orphaned inline block.', 'Deleted @count orphaned inline blocks.')
      : $this->t('No orphaned inline blocks were found, so nothing was deleted.'));

    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
