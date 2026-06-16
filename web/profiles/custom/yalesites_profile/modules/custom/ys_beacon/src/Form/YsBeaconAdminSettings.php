<?php

namespace Drupal\ys_beacon\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\ys_beacon\Config\YsBeaconConfigOverrides;
use Drupal\ys_beacon\Service\BeaconIndexManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Platform-facing administration form for Beacon.
 *
 * Holds the sensitive, platform-operator settings restricted by the "administer
 * ys beacon" permission: the Azure AI Search index connection and the retrieval
 * and response tuning (sources per answer, relevance threshold, streaming, and
 * the fallback system prompt).
 */
class YsBeaconAdminSettings extends ConfigFormBase {

  /**
   * Config name.
   *
   * @var string
   */
  const CONFIG_NAME = 'ys_beacon.settings';

  /**
   * The Beacon index manager.
   *
   * @var \Drupal\ys_beacon\Service\BeaconIndexManager
   */
  protected BeaconIndexManager $indexManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->indexManager = $container->get('ys_beacon.index_manager');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ys_beacon_admin_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [self::CONFIG_NAME];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config(self::CONFIG_NAME);

    $form['connection'] = [
      '#type' => 'details',
      '#title' => $this->t('Vector index connection'),
      '#open' => TRUE,
    ];
    $form['connection']['azure_index_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Azure AI Search index name'),
      '#description' => $this->t('The per-site index in Azure AI Search. If the index does not exist yet it is created automatically with the Beacon field schema on save. Leaving this empty keeps Beacon indexing disabled on this site; the index is then provisioned as %default when a site administrator first enables the chat widget.', [
        '%default' => $this->indexManager->getDefaultIndexName(),
      ]),
      '#default_value' => $config->get('azure_index_name'),
      '#placeholder' => $this->indexManager->getDefaultIndexName(),
    ];
    $form['connection']['azure_search_url_key'] = [
      '#type' => 'key_select',
      '#title' => $this->t('Azure AI Search endpoint URL key'),
      '#description' => $this->t('The key entity that holds the Azure AI Search endpoint URL. Leave empty to use the platform default key (%default).', [
        '%default' => YsBeaconConfigOverrides::DEFAULT_URL_KEY,
      ]),
      '#default_value' => $config->get('azure_search_url_key'),
    ];

    $form['retrieval'] = [
      '#type' => 'details',
      '#title' => $this->t('Retrieval and response'),
      '#open' => TRUE,
    ];
    $form['retrieval']['top_k'] = [
      '#type' => 'number',
      '#title' => $this->t('Sources per answer'),
      '#description' => $this->t('How many content chunks are retrieved as sources for each answer.'),
      '#default_value' => $config->get('top_k') ?: 5,
      '#min' => 1,
      '#max' => 20,
      '#required' => TRUE,
    ];
    $form['retrieval']['score_threshold'] = [
      '#type' => 'number',
      '#title' => $this->t('Minimum relevance score'),
      '#description' => $this->t('Chunks scoring below this value are dropped. Set to 0 to keep all retrieved chunks.'),
      '#default_value' => $config->get('score_threshold') ?? 0,
      '#min' => 0,
      '#max' => 1,
      '#step' => 0.01,
    ];
    $form['retrieval']['streaming'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Stream responses'),
      '#description' => $this->t('Send answers token by token. Disable if the hosting edge buffers streamed responses.'),
      '#default_value' => $config->get('streaming') ?? TRUE,
    ];
    $form['retrieval']['fallback_system_prompt'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Fallback system prompt'),
      '#description' => $this->t('Used when no site-specific system instructions are configured.'),
      '#default_value' => $config->get('fallback_system_prompt'),
      '#rows' => 4,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config(self::CONFIG_NAME);
    $previous_index_name = $config->get('azure_index_name');
    $new_index_name = $form_state->getValue('azure_index_name');

    // The index name is intentionally not saved here: provision() persists it
    // only after the Azure index has been verified or created, so a failed
    // provisioning never leaves the site pointing at a nonexistent index.
    $config
      ->set('azure_search_url_key', $form_state->getValue('azure_search_url_key'))
      ->set('top_k', (int) $form_state->getValue('top_k'))
      ->set('score_threshold', (float) $form_state->getValue('score_threshold'))
      ->set('streaming', (bool) $form_state->getValue('streaming'))
      ->set('fallback_system_prompt', $form_state->getValue('fallback_system_prompt'))
      ->save();

    if ($new_index_name !== $previous_index_name) {
      if ($new_index_name) {
        try {
          $this->indexManager->provision($new_index_name);
          $this->messenger()->addStatus($this->t('All existing content has been queued for indexing into the Beacon vector database.'));
        }
        catch (\RuntimeException $e) {
          $this->messenger()->addWarning($this->t('The index name was not saved: Azure AI Search could not be reached to verify or create the index. @message', ['@message' => $e->getMessage()]));
        }
      }
      else {
        // Explicitly clearing the index name disables Beacon indexing.
        $this->config(self::CONFIG_NAME)->set('azure_index_name', '')->save();
      }
    }

    parent::submitForm($form, $form_state);
  }

}
