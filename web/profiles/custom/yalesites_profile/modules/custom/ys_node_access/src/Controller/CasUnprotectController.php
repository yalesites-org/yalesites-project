<?php

namespace Drupal\ys_node_access\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\Url;
use Drupal\ys_node_access\CasProtectionBatch;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Starts the batch that removes CAS protection from a bulk selection.
 *
 * The Remove CAS protection action redirects here once it has recorded the
 * selection. There is no confirmation step, matching the node form, which only
 * warns when protection is being enabled. The batch is still what does the
 * work, so a large selection is spread across requests rather than timing out.
 *
 * The route requires a CSRF token, which core adds to the URL the bulk form
 * redirects to, so this cannot be triggered by a link from another site.
 *
 * @see \Drupal\ys_node_access\Plugin\Action\CasUnprotect
 */
class CasUnprotectController extends ControllerBase {

  /**
   * Constructs a CasUnprotectController.
   *
   * @param \Drupal\Core\TempStore\PrivateTempStoreFactory $tempStoreFactory
   *   The private tempstore factory.
   */
  public function __construct(
    protected PrivateTempStoreFactory $tempStoreFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('tempstore.private'));
  }

  /**
   * Starts the removal batch for the current user's pending selection.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   A redirect to the batch, or back to content when nothing is waiting. The
   *   destination the bulk form passed along takes precedence over either.
   */
  public function start(): Response {
    $store = $this->tempStoreFactory->get(CasProtectionBatch::TEMPSTORE);
    $nids = $store->get(CasProtectionBatch::UNPROTECT) ?? [];
    $store->delete(CasProtectionBatch::UNPROTECT);

    if (!$nids) {
      return $this->redirect('system.admin_content');
    }

    batch_set(CasProtectionBatch::build($nids, FALSE));
    return batch_process(Url::fromRoute('system.admin_content'));
  }

}
