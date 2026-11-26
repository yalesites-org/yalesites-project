<?php

namespace Drupal\ys_node_access\Plugin\Action;

use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ys_node_access\CasProtectionBatch;

/**
 * Stops requiring CAS login to view the selected content.
 *
 * No warning, matching the node form, which only warns on enabling.
 *
 * @see \Drupal\ys_node_access\Controller\CasUnprotectController
 */
#[Action(
  id: 'ys_node_access_cas_unprotect',
  label: new TranslatableMarkup('Remove CAS protection'),
  confirm_form_route_name: 'ys_node_access.cas_unprotect',
  type: 'node',
)]
class CasUnprotect extends CasProtectionActionBase {

  /**
   * {@inheritdoc}
   */
  protected const TEMPSTORE_KEY = CasProtectionBatch::UNPROTECT;

}
