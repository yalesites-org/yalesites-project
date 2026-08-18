<?php

namespace Drupal\ys_core\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\ys_core\DashboardAnnouncements;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configures the editorial dashboard's announcements feed for this site.
 *
 * Site admins own everything on this form. The publish side - whether this site
 * exposes its own feed at /api/dashboard-announcements, and under which tag -
 * is a platform decision and lives on the Platform Admin Settings page
 * (yalesites-org/YaleSites-Internal#1560), so this form no longer needs an
 * #access check or a matching guard in submitForm() to keep a site admin's save
 * from clobbering it.
 *
 * @see \Drupal\ys_core\Plugin\PlatformAdminSetting\AnnouncementsSourcePlatformAdminSetting
 *
 * @package Drupal\ys_core\Form
 */
class DashboardSettingsForm extends ConfigFormBase {

  /**
   * The dashboard announcements service.
   *
   * @var \Drupal\ys_core\DashboardAnnouncements
   */
  protected DashboardAnnouncements $announcements;

  /**
   * Constructs the object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   * @param \Drupal\ys_core\DashboardAnnouncements $announcements
   *   The dashboard announcements service.
   */
  final public function __construct(
    ConfigFactoryInterface $config_factory,
    DashboardAnnouncements $announcements,
  ) {
    parent::__construct($config_factory);
    $this->announcements = $announcements;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('ys_core.dashboard_announcements'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ys_core_dashboard_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['ys_core.dashboard_settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('ys_core.dashboard_settings');

    $form['announcements'] = [
      '#type' => 'details',
      '#title' => $this->t('Announcements'),
      '#open' => TRUE,
      '#description' => $this->t('Show platform announcements on the dashboard, pulled from the YaleSites platform feed.'),
    ];

    $form['announcements']['announcements_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show platform announcements'),
      '#default_value' => $config->get('announcements_enabled') ?? TRUE,
      '#description' => $this->t('When enabled, the editorial dashboard displays announcements published by the YaleSites platform team.'),
    ];

    $form['announcements']['announcements_limit'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum announcements'),
      '#default_value' => $config->get('announcements_limit') ?? 3,
      '#min' => 1,
      '#max' => 25,
      '#description' => $this->t('How many announcements to show, most recent first.'),
      '#states' => [
        'visible' => [
          ':input[name="announcements_enabled"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['announcements']['announcements_max_age'] = [
      '#type' => 'number',
      '#title' => $this->t('Cache lifetime (seconds)'),
      '#default_value' => $config->get('announcements_max_age') ?? 3600,
      '#min' => 60,
      '#description' => $this->t('How long to cache the feed before fetching it again. Defaults to 3600 (1 hour). The feed is fetched once per cache window for the whole site, regardless of how many editors view the dashboard.'),
      '#states' => [
        'visible' => [
          ':input[name="announcements_enabled"]' => ['checked' => TRUE],
        ],
      ],
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('ys_core.dashboard_settings')
      ->set('announcements_enabled', (bool) $form_state->getValue('announcements_enabled'))
      ->set('announcements_limit', (int) $form_state->getValue('announcements_limit'))
      ->set('announcements_max_age', (int) $form_state->getValue('announcements_max_age'))
      ->save();

    // Drop the cached feed so the new settings take effect immediately.
    $this->announcements->clearCache();

    parent::submitForm($form, $form_state);
  }

}
