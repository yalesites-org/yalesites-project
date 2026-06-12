<?php

namespace Drupal\ys_beacon\Plugin\Action;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Action\ActionBase;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Base action that sets a metatag value on an entity's metatag field.
 */
class MetatagValueSetAction extends ActionBase implements ContainerFactoryPluginInterface {

  /**
   * The permission name required to execute.
   *
   * @var string
   */
  protected static $manageAiPermissionName = 'manage ys beacon settings';

  /**
   * The name of the entity field to update.
   *
   * @var string
   */
  protected static $entityMetatagFieldName;

  /**
   * The name of the metatag field inside the entity field to update.
   *
   * @var string
   */
  protected static $metatagFieldName;

  /**
   * The value to set the metatag field to.
   *
   * @var string
   */
  protected static $actionValue;

  /**
   * The configuration factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constructs a MetatagValueSetAction object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The configuration factory.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, ConfigFactoryInterface $configFactory) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->configFactory = $configFactory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('config.factory')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function access($entity, ?AccountInterface $account = NULL, $return_as_object = FALSE): AccessResultInterface|bool {
    if (!$this->isServiceEnabled()) {
      return FALSE;
    }

    /** @var \Drupal\Core\Entity\ContentEntityInterface $entity */
    $access = $entity->access('update', $account, TRUE)
      ->andIf(AccessResult::allowedIfHasPermission($account, static::$manageAiPermissionName));
    return $return_as_object ? $access : $access->isAllowed();
  }

  /**
   * Checks if the Beacon chat service is enabled.
   *
   * @return bool
   *   TRUE if the chat service is enabled, FALSE otherwise.
   */
  protected function isServiceEnabled(): bool {
    return (bool) $this->configFactory
      ->get('ys_beacon.settings')
      ->get('enable_chat');
  }

  /**
   * {@inheritdoc}
   */
  public function execute(?ContentEntityInterface $entity = NULL): void {
    if (!$entity) {
      return;
    }

    if ($entity->hasField(static::$entityMetatagFieldName)) {
      $metaTagsArray = json_decode($entity->get(static::$entityMetatagFieldName)->value ?? "{}", TRUE);
      $metaTagsArray[static::$metatagFieldName] = static::$actionValue;
      $metaTagsJson = json_encode($metaTagsArray);
      $entity->get(static::$entityMetatagFieldName)->value = $metaTagsJson;
      $entity->save();
    }
  }

}
