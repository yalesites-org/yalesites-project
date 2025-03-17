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
    $output = parent::systemAdminMenuBlockPage();

    if (isset($output['#content'])) {
      foreach ($output['#content'] as $key => $value) {
        $output['#content'][$key]['#actions']['configure'] = [
          '#type' => 'link',
          '#title' => $this->t('Configure'),
          '#url' => $output['#content'][$key]['url'],
          '#options' => [
            'attributes' => [
              'class' => ['button', 'button--primary'],
            ],
          ],
        ];

        $module_name = $this->getModuleNameFromRouteName($output['#content'][$key]['url']->getRouteName());
        if ($this->isTurnedOn($module_name)) {
          $output['#content'][$key]['#actions']['sync'] = [
            '#type' => 'link',
            '#title' => $this->t('Sync now'),
            '#url' => Url::fromRoute($module_name . '.run_migrations'),
            '#options' => [
              'attributes' => [
                'class' => ['button', 'button--primary'],
              ],
            ],
          ];
        }
      }
    }

    $output['#theme'] = 'ys_integrations_block';
    return $output;

  }

  /**
   * Get the class name for the integration display.
   *
   * @param string $module_name
   *   The name of the module.
   *
   * @return string
   *   The name of the class.
   */
  protected function getIntegrationDisplayClassName($module_name): string {
    $class_name = $this->removeYsPrefix($module_name);
    $class_name = ucwords($class_name);
    $class_name = str_replace('_', '', $class_name);
    return $class_name . 'IntegrationDisplay';
  }

  /**
   * Check if a module is turned on.
   *
   * @param string $module_name
   *   The name of the module.
   *
   * @return mixed
   *   Whether the module is turned on.
   */
  protected function isTurnedOn($module_name) {
    $class_name = $this->getIntegrationDisplayClassName($module_name);
    $class = 'Drupal\\' . $module_name . '\\' . $class_name;
    $instance = $class::create($this->container);
    return $instance->isTurnedOn();
  }

  /**
   * Get the module name from a route name.
   *
   * @param string $route_name
   *   The name of the route.
   *
   * @return string
   *   The name of the module.
   */
  protected function getModuleNameFromRouteName($route_name): string {
    $route_parts = explode('.', $route_name);
    return $route_parts[0];
  }

  /**
   * Remove the ys_ prefix from a string.
   *
   * @param string $name
   *   The name to remove the prefix from.
   *
   * @return string
   *   The name without the prefix.
   */
  protected function removeYsPrefix($name): string {
    return str_replace('ys_', '', $name);
  }

}
