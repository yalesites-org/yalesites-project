<?php

namespace Drupal\ys_core\Controller;

use Drupal\system\Controller\SystemController;
use Drupal\Core\Url;
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
class IntegrationsController extends SystemController {

  /**
   * The container.
   *
   * @var \Symfony\Component\DependencyInjection\ContainerInterface
   */
  protected $container;

  /**
   * The integration plugin manager.
   *
   * @var \Drupal\ys_core\IntegrationPluginManager
   */
  protected $integrationPluginManager;

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
    $this->integrationPluginManager = $container->get('ys_core.integration_plugin_manager');
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

    // Get the ys_core.integtration_settings.
    $integrationsConfig = $this->config('ys_core.integration_settings');

    $integrations = $integrationsConfig->getRawData();
    foreach ($integrations as $id => $integration) {
      if ($integration) {
        $plugin = $this->integrationPluginManager->createInstance($id);
        $definitions = $this->integrationPluginManager->getDefinitions();

        // Convert the label from translatable markup to a string.
        $output['#content'][$id]['title'] = $definitions[$id]['label'];
        $output['#content'][$id]['description'] = $definitions[$id]['description'];

        $output['#content'][$id]['#actions']['configure'] = [
          '#type' => 'link',
          '#title' => $this->t('Configure'),
          '#url' => $plugin->configUrl(),
          '#options' => [
            'attributes' => [
              'class' => ['button', 'button--primary'],
            ],
          ],
        ];

        if ($plugin->isTurnedOn()) {
          $output['#content'][$id]['#actions']['sync'] = [
            '#type' => 'link',
            '#title' => $this->t('Sync now'),
            '#url' => Url::fromRoute($id . '.run_migrations'),
            '#options' => [
              'attributes' => [
                'class' => ['button', 'button--primary'],
              ],
            ],
          ];
        }
      }
    }

    return $output;

  }

}
