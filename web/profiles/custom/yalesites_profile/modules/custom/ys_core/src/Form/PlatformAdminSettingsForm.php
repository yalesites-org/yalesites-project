<?php

namespace Drupal\ys_core\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\ys_core\PlatformAdminSettingManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Platform-admin-only settings form.
 *
 * Discovers PlatformAdminSetting plugins and renders one section each, ordered
 * by the plugin attribute weight (label as tiebreaker). Each plugin owns its
 * own build, validate, and save logic; this form only orchestrates discovery,
 * ordering, and delegation, so new platform-admin-only settings are added by
 * contributing a plugin rather than editing this form.
 */
class PlatformAdminSettingsForm extends FormBase {

  /**
   * Constructs a PlatformAdminSettingsForm object.
   *
   * @param \Drupal\ys_core\PlatformAdminSettingManager $pluginManager
   *   The platform admin setting plugin manager.
   */
  public function __construct(
    protected PlatformAdminSettingManager $pluginManager,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('ys_core.platform_admin_setting_manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ys_core_platform_admin_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    foreach ($this->sortedDefinitions() as $id => $definition) {
      $instance = $this->pluginManager->createInstance($id);
      // Each section is namespaced (#tree) under the plugin id so plugins never
      // collide on element keys and can read their own values back by id.
      $form[$id] = [
        '#type' => 'details',
        '#title' => $definition['label'] ?? $id,
        '#open' => TRUE,
        '#tree' => TRUE,
      ] + $instance->buildSettings([], $form_state);
    }

    $form['actions'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Save configuration'),
        '#button_type' => 'primary',
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    foreach (array_keys($this->sortedDefinitions()) as $id) {
      $this->pluginManager->createInstance($id)->validateSettings($form, $form_state);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    foreach (array_keys($this->sortedDefinitions()) as $id) {
      $this->pluginManager->createInstance($id)->submitSettings($form, $form_state);
    }
    $this->messenger()->addStatus($this->t('The configuration options have been saved.'));
  }

  /**
   * Returns plugin definitions sorted by attribute weight, then label.
   *
   * @return array[]
   *   Plugin definitions keyed by plugin id.
   */
  protected function sortedDefinitions(): array {
    $definitions = $this->pluginManager->getDefinitions();
    uasort($definitions, function (array $a, array $b): int {
      return [$a['weight'] ?? 0, (string) ($a['label'] ?? '')]
        <=> [$b['weight'] ?? 0, (string) ($b['label'] ?? '')];
    });
    return $definitions;
  }

}
