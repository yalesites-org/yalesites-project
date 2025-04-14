<?php

namespace Drupal\ys_integrations\Controller;

use Drupal\system\Controller\SystemController;
use Psr\Container\ContainerInterface;
use Drupal\system\SystemManager;
use Drupal\Core\Theme\ThemeAccessCheck;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Menu\MenuLinkTreeInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ThemeExtensionList;

/**
 * Controller routines for system integrations routes.
 */
class YsIntegrationsController extends SystemController {

  /**
   * The container.
   *
   * @var \Symfony\Component\DependencyInjection\ContainerInterface
   */
  protected $container;

  /**
   * The integration plugin manager.
   *
   * @var \Drupal\ys_integrations\IntegrationPluginManager
   */
  protected $integrationPluginManager;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  use \Drupal\Core\StringTranslation\StringTranslationTrait;

  /**
   * Constructs a new SystemController.
   *
   * @param \Drupal\system\SystemManager $systemManager
   *   System manager service.
   * @param \Drupal\Core\Theme\ThemeAccessCheck $theme_access
   *   The theme access checker service.
   * @param \Drupal\Core\Form\FormBuilderInterface $form_builder
   *   The form builder.
   * @param \Drupal\Core\Menu\MenuLinkTreeInterface $menu_link_tree
   *   The menu link tree service.
   * @param \Drupal\Core\Extension\ModuleExtensionList $module_extension_list
   *   The module extension list.
   * @param \Drupal\Core\Extension\ThemeExtensionList $theme_extension_list
   *   The theme extension list.
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The container.
   */
  public function __construct(SystemManager $systemManager, ThemeAccessCheck $theme_access, FormBuilderInterface $form_builder, MenuLinkTreeInterface $menu_link_tree, ModuleExtensionList $module_extension_list, ThemeExtensionList $theme_extension_list, ContainerInterface $container) {
    parent::__construct($systemManager, $theme_access, $form_builder, $menu_link_tree, $module_extension_list, $theme_extension_list);
    $this->container = $container;
    $this->currentUser = $container->get('current_user');
    $this->integrationPluginManager = $container->get('ys_integrations.integration_plugin_manager');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('system.manager'),
      $container->get('access_check.theme'),
      $container->get('form_builder'),
      $container->get('menu.link_tree'),
      $container->get('extension.list.module'),
      $container->get('extension.list.theme'),
      $container,
    );
  }

  /**
   * {@inheritdoc}
   */
  public function systemAdminMenuBlockPage(): array {
    $output = [
      '#content' => [],
      '#theme' => 'ys_integrations_block',
    ];

    // Get the ys_integrations.integtration_settings.
    $integrationsConfig = $this->config('ys_integrations.integration_settings');

    $integrations = $integrationsConfig->getRawData();
    foreach ($integrations as $id => $integration) {
      if ($integration) {
        $plugin = $this->integrationPluginManager->createInstance($id);
        $output['#content'][$id] = $plugin->build();
      }
    }

    return $output;

  }

}
