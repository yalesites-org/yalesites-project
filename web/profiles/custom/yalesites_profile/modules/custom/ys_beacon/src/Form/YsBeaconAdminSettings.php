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
 * Holds the sensitive, platform-operator settings restricted to user 1 only
 * (see \Drupal\ys_beacon\Access\BeaconAdminAccessCheck): the Azure AI Search
 * index connection and the retrieval and response tuning (sources per answer,
 * relevance threshold, streaming, and the fallback system prompt).
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
    $form['connection']['read_only'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Read-only (borrow another site’s index)'),
      '#description' => $this->t('When enabled, this site queries the index above but never writes to it: no indexing, re-indexing, clearing, or delete-time removal. Use this when the index name points at a collection owned by another site, so that site’s data is never modified. Leave off for a site that owns and indexes its own content.'),
      '#default_value' => (bool) $config->get('read_only'),
    ];
    $form['connection']['query_entire_index'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Answer from every site in a shared index'),
      '#description' => $this->t("Query all content in the assigned index, not just this site's. Use only when the index is a shared collection and its content is public. Leave off for normal per-site isolation."),
      '#default_value' => (bool) $config->get('query_entire_index'),
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

    $form['metadata'] = [
      '#type' => 'details',
      '#title' => $this->t('Content metadata'),
      '#open' => TRUE,
    ];
    $chat_enabled = (bool) $config->get('enable_chat');
    $form['metadata']['enable_metadata_fields'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show AI metadata fields on content forms'),
      '#description' => $this->t('Exposes the AI Description, AI Tags, and disable-indexing fields on node and media forms. This is forced on, and cannot be turned off, while the chat widget is enabled.'),
      // Forced on while chat is enabled, so show it checked and disabled rather
      // than letting an admin uncheck a box whose value is then overridden.
      '#default_value' => $chat_enabled ? TRUE : ($config->get('enable_metadata_fields') ?? TRUE),
      '#disabled' => $chat_enabled,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config(self::CONFIG_NAME);
    $previous_index_name = $config->get('azure_index_name');
    $previous_read_only = (bool) $config->get('read_only');
    $new_index_name = $form_state->getValue('azure_index_name');
    $read_only = (bool) $form_state->getValue('read_only');

    // The index name and read-only flag are intentionally not saved here:
    // propagateConnection() below persists them onto both the Beacon settings
    // and the real Search API config (server database name + index read-only
    // flag), so they never drift and the admin UI stays truthful.
    // The chatbot requires the AI metadata fields, so an explicit "off" here is
    // overridden whenever chat is enabled.
    $enable_metadata_fields = (bool) $form_state->getValue('enable_metadata_fields')
      || (bool) $config->get('enable_chat');
    $config
      ->set('azure_search_url_key', $form_state->getValue('azure_search_url_key'))
      ->set('top_k', (int) $form_state->getValue('top_k'))
      ->set('score_threshold', (float) $form_state->getValue('score_threshold'))
      ->set('streaming', (bool) $form_state->getValue('streaming'))
      ->set('fallback_system_prompt', $form_state->getValue('fallback_system_prompt'))
      ->set('enable_metadata_fields', $enable_metadata_fields)
      ->set('query_entire_index', (bool) $form_state->getValue('query_entire_index'))
      ->save();

    // Provision when a writable site points at a NEW index, or switches an
    // existing index from read-only (borrowed) back to writable: both need the
    // Azure index verified/created and the Search API tracker (re)built so the
    // site's content is queued. provision() persists the connection only after
    // the Azure index is confirmed, so a failed provisioning never leaves the
    // site pointing at a nonexistent index. Read-only borrows never provision.
    if ($new_index_name && !$read_only
      && ($new_index_name !== $previous_index_name || $previous_read_only)) {
      try {
        $this->indexManager->provision($new_index_name);
        $this->messenger()->addStatus($this->t('All existing content has been queued for indexing into the Beacon vector database.'));
      }
      catch (\RuntimeException $e) {
        $this->messenger()->addWarning($this->t('The index name was not saved: Azure AI Search could not be reached to verify or create the index. @message', ['@message' => $e->getMessage()]));
      }
    }
    else {
      // Everything else - borrowing another site's collection read-only,
      // clearing the index name, or a save that only toggles read-only on the
      // current index - writes the connection straight into the real config
      // without provisioning. A read-only site must never create, write to, or
      // queue content into the collection, so provision() (which would create a
      // missing index and rebuild the tracker) is skipped.
      $this->indexManager->propagateConnection($new_index_name, $read_only);
      if ($new_index_name && $read_only && $new_index_name !== $previous_index_name) {
        $this->messenger()->addStatus($this->t('This site now reads from the shared, read-only index %name. Content indexing is managed by the owning site.', ['%name' => $new_index_name]));
      }
    }

    parent::submitForm($form, $form_state);
  }

}
