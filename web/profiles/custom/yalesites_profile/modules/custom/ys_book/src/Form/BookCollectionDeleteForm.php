<?php

namespace Drupal\ys_book\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Confirmation form to delete an entire content collection.
 *
 * A content collection is a Drupal book. Deleting a collection dismantles the
 * book outline — removing the collection grouping and navigation — while
 * keeping every page as standalone content. No page node is deleted.
 */
class BookCollectionDeleteForm extends ConfirmFormBase {

  /**
   * The collection's top-level page.
   *
   * @var \Drupal\node\NodeInterface
   */
  protected NodeInterface $node;

  /**
   * Constructs a BookCollectionDeleteForm object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ys_book_collection_delete_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $node = NULL): array {
    // Only a collection's top-level page (bid == nid) can be deleted as a whole
    // collection. Child pages are removed individually via the Outline tab.
    if (!$node || empty($node->book['bid']) || $node->book['bid'] != $node->id()) {
      throw new NotFoundHttpException();
    }
    $this->node = $node;

    $form = parent::buildForm($form, $form_state);

    // List the pages that will be kept as standalone content, so the editor can
    // clearly see what the collection contains before confirming. The label is
    // a plain paragraph rather than the item list's #title, which would render
    // as an <h3> and skip a heading level after the confirm question's <h1>
    // (WCAG 2.1 AA heading order).
    $nids = _ys_book_get_all_book_nids((int) $node->id());
    $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($nids);
    $form['pages_label'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('These pages will be kept as standalone content:'),
      '#weight' => 9,
    ];
    $form['pages'] = [
      '#theme' => 'item_list',
      '#items' => array_values(array_map(fn(NodeInterface $page) => $page->label(), $nodes)),
      '#weight' => 10,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): TranslatableMarkup {
    return $this->t('Are you sure you want to delete the collection %title?', ['%title' => $this->node->label()]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): TranslatableMarkup {
    return $this->t('The collection grouping and navigation will be removed. Every page will be kept as standalone content. This action cannot be undone.');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText(): TranslatableMarkup {
    return $this->t('Delete collection');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return Url::fromRoute('book.admin');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $title = $this->node->label();
    _ys_book_dismantle_collection((int) $this->node->id());
    $this->messenger()->addStatus($this->t('The collection %title has been deleted. Its pages were kept as standalone content.', ['%title' => $title]));
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
