<?php

namespace Drupal\ys_core\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\taxonomy\Entity\Term;
use Drupal\ys_core\DashboardAnnouncements;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configures the editorial dashboard, including the announcements feed.
 *
 * @package Drupal\ys_core\Form
 */
class DashboardSettingsForm extends ConfigFormBase {

  /**
   * The key/value expirable factory.
   *
   * @var \Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface
   */
  protected KeyValueExpirableFactoryInterface $keyValueExpirable;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Constructs the object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   * @param \Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface $key_value_expirable
   *   The key/value expirable factory.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  final public function __construct(
    ConfigFactoryInterface $config_factory,
    KeyValueExpirableFactoryInterface $key_value_expirable,
    EntityTypeManagerInterface $entity_type_manager,
  ) {
    parent::__construct($config_factory);
    $this->keyValueExpirable = $key_value_expirable;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('keyvalue.expirable'),
      $container->get('entity_type.manager')
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
      '#description' => $this->t('Display platform announcements on the dashboard, pulled from a JSON feed published on yalesites.yale.edu.'),
    ];

    $form['announcements']['announcements_feed_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Feed URL'),
      '#default_value' => $config->get('announcements_feed_url'),
      '#description' => $this->t('The full URL of a JSON Feed (version 1.1). Leave blank to hide the announcements section.'),
      '#maxlength' => 2048,
    ];

    $form['announcements']['announcements_limit'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum announcements'),
      '#default_value' => $config->get('announcements_limit') ?? 5,
      '#min' => 1,
      '#max' => 25,
      '#description' => $this->t('How many announcements to show, most recent first.'),
    ];

    $form['announcements']['announcements_max_age'] = [
      '#type' => 'number',
      '#title' => $this->t('Cache lifetime (seconds)'),
      '#default_value' => $config->get('announcements_max_age') ?? 3600,
      '#min' => 60,
      '#description' => $this->t('How long to cache the feed before fetching it again. Defaults to 3600 (1 hour). The feed is fetched once per cache window for the whole site, regardless of how many editors view the dashboard.'),
    ];

    $form['source'] = [
      '#type' => 'details',
      '#title' => $this->t('Announcements source'),
      '#open' => FALSE,
      '#description' => $this->t('Most sites leave this off and only <em>consume</em> the feed above. The platform site (yalesites.yale.edu) turns this on to <em>publish</em> the feed at <code>/api/dashboard-announcements</code> from its tagged posts.'),
    ];

    $form['source']['announcements_source_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Publish an announcements feed from this site'),
      '#default_value' => $config->get('announcements_source_enabled'),
      '#description' => $this->t('When enabled, published posts tagged with the term below are exposed as a JSON feed at <code>/api/dashboard-announcements</code>.'),
    ];

    $form['source']['announcements_source_term'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Announcement tag'),
      '#default_value' => $config->get('announcements_source_term') ?: 'Dashboard Announcement',
      '#description' => $this->t('The name of the tag (in the Tags vocabulary) used to mark posts as announcements.'),
      '#states' => [
        'visible' => [
          ':input[name="announcements_source_enabled"]' => ['checked' => TRUE],
        ],
      ],
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $source_enabled = (bool) $form_state->getValue('announcements_source_enabled');
    $source_term = trim($form_state->getValue('announcements_source_term'));

    $this->config('ys_core.dashboard_settings')
      ->set('announcements_feed_url', trim($form_state->getValue('announcements_feed_url')))
      ->set('announcements_limit', (int) $form_state->getValue('announcements_limit'))
      ->set('announcements_max_age', (int) $form_state->getValue('announcements_max_age'))
      ->set('announcements_source_enabled', $source_enabled)
      ->set('announcements_source_term', $source_term)
      ->save();

    // When this site is publishing the feed, make sure the tag the editors will
    // use actually exists in the Tags vocabulary. Otherwise the endpoint would
    // silently return zero items until someone added it by hand.
    if ($source_enabled && $source_term !== '') {
      $this->ensureAnnouncementTerm($source_term);
    }

    // Drop the cached feed so the new settings take effect immediately.
    $this->keyValueExpirable->get(DashboardAnnouncements::STORE_COLLECTION)
      ->delete(DashboardAnnouncements::STORE_KEY);

    parent::submitForm($form, $form_state);
  }

  /**
   * Ensures a term with the given name exists in the Tags vocabulary.
   */
  protected function ensureAnnouncementTerm(string $name): void {
    $vocab_storage = $this->entityTypeManager->getStorage('taxonomy_vocabulary');
    if (!$vocab_storage->load('tags')) {
      $this->messenger()->addWarning($this->t('The "Tags" vocabulary does not exist on this site, so the announcement tag could not be created automatically.'));
      return;
    }

    $term_storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $existing = $term_storage->loadByProperties(['vid' => 'tags', 'name' => $name]);
    if ($existing) {
      return;
    }

    $term = Term::create(['vid' => 'tags', 'name' => $name]);
    $term->save();
    $this->messenger()->addStatus($this->t('Created the %name tag in the Tags vocabulary. Apply it to posts you want to surface on editorial dashboards.', ['%name' => $name]));
  }

}
