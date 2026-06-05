<?php

namespace Drupal\ys_ai\Plugin\search_api\processor;

use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\node\NodeInterface;
use Drupal\search_api\Attribute\SearchApiProcessor;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Processor\ProcessorPluginBase;

/**
 * Excludes from the index any node an anonymous user cannot view.
 *
 * The Beacon (Azure AI Search) index powers a public chatbot. The stock
 * "Content access" processor enforces access with query-time node-grant
 * filters, but those filters cannot be satisfied on the Azure backend: the
 * hidden node_grants field is not stored as a filterable Azure attribute (the
 * ai_search backend reports support for no data types except "embeddings"), so
 * the grant filter matches nothing and anonymous users receive no results.
 *
 * Instead of filtering at query time, this processor enforces access at index
 * time: it removes from the items being indexed any node that an anonymous user
 * cannot view. Access is delegated to the node access system (the YaleSites
 * ys_node_access grants), so published state and CAS protection
 * (field_login_required) are honoured through their single source of truth.
 * Content excluded here is never sent to Azure and therefore can never be
 * returned to any user.
 *
 * It also enforces the per-node "exclude from AI" flag (field_ai_exclude):
 * flagged nodes are removed from the index regardless of access.
 */
#[SearchApiProcessor(
  id: 'ys_beacon_access_filter',
  label: new TranslatableMarkup('Beacon access filter'),
  description: new TranslatableMarkup('Excludes nodes anonymous users cannot view (unpublished, CAS-protected) and nodes flagged to be excluded from AI indexing. Enforced at index time so excluded content is never sent to the vector database.'),
  stages: [
    'alter_items' => 0,
  ],
)]
class BeaconAccessFilter extends ProcessorPluginBase {

  /**
   * {@inheritdoc}
   */
  public static function supportsIndex(IndexInterface $index) {
    foreach ($index->getDatasources() as $datasource) {
      if ($datasource->getEntityTypeId() === 'node') {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function alterIndexedItems(array &$items) {
    $anonymous = new AnonymousUserSession();

    /** @var \Drupal\search_api\Item\ItemInterface $item */
    foreach ($items as $item_id => $item) {
      $object = $item->getOriginalObject()->getValue();
      if (!$object instanceof NodeInterface) {
        continue;
      }

      // Delegate the access decision to the node access system so the
      // ys_node_access grants (published + CAS protection) remain the single
      // source of truth.
      if (!$object->access('view', $anonymous)) {
        unset($items[$item_id]);
        continue;
      }

      // Honour the per-node "exclude from AI indexing" flag.
      if ($object->hasField('field_ai_exclude') && (bool) $object->get('field_ai_exclude')->value) {
        unset($items[$item_id]);
      }
    }
  }

}
