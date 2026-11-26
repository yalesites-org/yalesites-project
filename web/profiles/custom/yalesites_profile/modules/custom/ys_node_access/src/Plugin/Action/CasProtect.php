<?php

namespace Drupal\ys_node_access\Plugin\Action;

use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ys_node_access\CasProtectionBatch;

/**
 * Requires CAS login to view the selected content, after a warning.
 *
 * @see \Drupal\ys_node_access\Form\CasProtectConfirmForm
 */
#[Action(
  id: 'ys_node_access_cas_protect',
  label: new TranslatableMarkup('CAS protect'),
  confirm_form_route_name: 'ys_node_access.cas_protect_confirm',
  type: 'node',
)]
class CasProtect extends CasProtectionActionBase {

  /**
   * {@inheritdoc}
   */
  protected const TEMPSTORE_KEY = CasProtectionBatch::PROTECT;

}
