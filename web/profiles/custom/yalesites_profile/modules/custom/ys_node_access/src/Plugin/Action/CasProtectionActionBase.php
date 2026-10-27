<?php

namespace Drupal\ys_node_access\Plugin\Action;

use Drupal\Core\Action\Plugin\Action\EntityActionBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\ys_node_access\CasProtectionBatch;
use Drupal\ys_node_access\CasProtectionUpdater;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Base for the bulk actions that turn CAS protection on and off.
 *
 * Neither action saves anything itself. Core's node_bulk_form would run
 * execute() on every selected row in the request that submitted it, which has
 * no room for a confirmation step and would time out on a large selection.
 * Instead each action records the selection in the private tempstore, and the
 * bulk form follows the plugin's confirm_form_route_name to a route that runs
 * the change as a batch -- after the CAS warning, when protecting. This is the
 * pattern core's own delete action uses.
 *
 * @see \Drupal\Core\Action\Plugin\Action\DeleteAction
 * @see \Drupal\views\Plugin\views\field\BulkForm::viewsFormSubmit()
 * @see \Drupal\ys_node_access\CasProtectionBatch
 */
abstract class CasProtectionActionBase extends EntityActionBase {

  /**
   * The tempstore key this action records its selection under.
   *
   * One of the CasProtectionBatch::PROTECT or ::UNPROTECT constants. They are
   * kept apart so a selection made for one action can never be picked up and
   * run by the other.
   */
  protected const TEMPSTORE_KEY = '';

  /**
   * Constructs a CAS protection action.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin ID for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\TempStore\PrivateTempStoreFactory $tempStoreFactory
   *   The private tempstore factory.
   * @param \Drupal\ys_node_access\CasProtectionUpdater $updater
   *   The CAS protection updater, which owns the access rules.
   * @param \Drupal\Core\Session\AccountInterface $currentUser
   *   The current user.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    EntityTypeManagerInterface $entity_type_manager,
    protected PrivateTempStoreFactory $tempStoreFactory,
    protected CasProtectionUpdater $updater,
    protected AccountInterface $currentUser,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $entity_type_manager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('tempstore.private'),
      $container->get('ys_node_access.cas_protection_updater'),
      $container->get('current_user'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function executeMultiple(array $entities) {
    $nids = [];
    foreach ($entities as $entity) {
      $nids[$entity->id()] = (int) $entity->id();
    }
    $this->tempStoreFactory->get(CasProtectionBatch::TEMPSTORE)->set(static::TEMPSTORE_KEY, array_values($nids));
  }

  /**
   * {@inheritdoc}
   */
  public function execute($entity = NULL) {
    $this->executeMultiple([$entity]);
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, ?AccountInterface $account = NULL, $return_as_object = FALSE) {
    /** @var \Drupal\node\NodeInterface $object */
    $result = $this->updater->access($object, $account ?? $this->currentUser);
    return $return_as_object ? $result : $result->isAllowed();
  }

}
