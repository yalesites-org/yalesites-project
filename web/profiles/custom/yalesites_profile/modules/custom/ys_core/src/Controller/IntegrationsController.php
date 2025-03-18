<?php

namespace Drupal\ys_core\Controller;

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

        $configUrl = $plugin->configUrl();
        $configUrlAccess = $configUrl->access($this->currentUser);
        $syncUrl = $plugin->syncUrl();
        $syncUrlAccess = $syncUrl->access($this->currentUser);

        $output['#content'][$id]['#actions']['configure'] = $this->buildActionItem(
          'Configure',
          $configUrl,
          $configUrlAccess,
          ['button', 'button--primary']
        );

        if ($plugin->isTurnedOn()) {
          $output['#content'][$id]['#actions']['sync'] = $this->buildActionItem(
          'Sync now',
          $syncUrl,
          $syncUrlAccess,
          ['button', 'button--primary']
          );
        }
        else {
          $output['#content'][$id]['#actions']['not_configured'] = [
            '#markup' => '<p>' . $this->t('This integration is not configured.') . '</p>',
          ];
        }
      }
    }

    return $output;

  }

  /**
   * Builds a single action item.
   *
   * @param string $title
   *   The title of the action.
   * @param \Drupal\Core\Url $url
   *   The URL for the action.
   * @param bool $access
   *   Access status for the action.
   * @param array $classes
   *   CSS classes for the action.
   *
   * @return array
   *   Render array for a single action.
   */
  protected function buildActionItem(string $title, $url, bool $access, array $classes): array {
    return [
      '#type' => 'link',
      '#title' => $title,
      '#url' => $url,
      '#access' => $access,
      '#options' => [
        'attributes' => [
          'class' => $classes,
        ],
      ],
    ];
  }

}
