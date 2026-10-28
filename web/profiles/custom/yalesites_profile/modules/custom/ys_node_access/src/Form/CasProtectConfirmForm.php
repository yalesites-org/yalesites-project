<?php

namespace Drupal\ys_node_access\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\Url;
use Drupal\ys_node_access\CasProtectionBatch;
use Drupal\ys_node_access\CasProtectionWarning;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Shows the CAS warning for a bulk selection, and protects it on confirmation.
 *
 * The CAS protect bulk action only records what was selected; nothing is saved
 * until the editor confirms here. Cancelling leaves every item as it was.
 *
 * This is a page rather than the node form's JavaScript modal on purpose. The
 * modal cannot be the gate here: it is optional (the node form still saves
 * without JavaScript) and it is bound to the CAS checkbox, which the bulk form
 * does not have. A server-rendered confirmation cannot be skipped, lists
 * exactly which items will change, and is a plain page of native controls
 * whose title is announced on load.
 *
 * @see \Drupal\ys_node_access\Plugin\Action\CasProtect
 */
class CasProtectConfirmForm extends ConfirmFormBase {

  /**
   * The IDs of the nodes waiting to be protected.
   *
   * @var int[]
   */
  protected array $nids = [];

  /**
   * Constructs a CasProtectConfirmForm.
   *
   * @param \Drupal\Core\TempStore\PrivateTempStoreFactory $tempStoreFactory
   *   The private tempstore factory.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    protected PrivateTempStoreFactory $tempStoreFactory,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('tempstore.private'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ys_node_access_cas_protect_confirm';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): TranslatableMarkup {
    return $this->formatPlural(count($this->nids), 'Enable CAS protection on 1 item?', 'Enable CAS protection on @count items?');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): string {
    // The warning is the description; buildForm() renders it above the list.
    return '';
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText(): TranslatableMarkup {
    return CasProtectionWarning::confirmLabel();
  }

  /**
   * {@inheritdoc}
   *
   * Only the fallback: the bulk form sends the editor here with a destination
   * back to the Manage view they came from, and the cancel link and the final
   * redirect both honour it.
   */
  public function getCancelUrl(): Url {
    return Url::fromRoute('system.admin_content');
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $this->nids = $this->tempStoreFactory->get(CasProtectionBatch::TEMPSTORE)->get(CasProtectionBatch::PROTECT) ?? [];
    // Nothing waiting: the selection was already confirmed, or this page was
    // reached directly.
    if (!$this->nids) {
      return new RedirectResponse($this->getCancelUrl()->setAbsolute()->toString());
    }

    $form['warning'] = CasProtectionWarning::build();

    $items = [];
    foreach ($this->entityTypeManager->getStorage('node')->loadMultiple($this->nids) as $node) {
      $items[] = $node->label();
    }
    $form['items'] = [
      '#theme' => 'item_list',
      '#title' => $this->t('CAS protection will be enabled on:'),
      '#items' => $items,
    ];

    $form = parent::buildForm($form, $form_state);
    $form['#attached']['library'][] = 'ys_node_access/cas_protection_confirm';
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // Cleared first, so a second submit or a page refresh cannot run the same
    // selection twice.
    $this->tempStoreFactory->get(CasProtectionBatch::TEMPSTORE)->delete(CasProtectionBatch::PROTECT);
    batch_set(CasProtectionBatch::build($this->nids, TRUE));
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
