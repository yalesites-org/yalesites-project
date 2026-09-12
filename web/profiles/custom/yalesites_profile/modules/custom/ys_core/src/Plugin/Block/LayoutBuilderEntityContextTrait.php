<?php

namespace Drupal\ys_core\Plugin\Block;

use Drupal\Core\Form\FormStateInterface;
use Drupal\node\NodeInterface;

/**
 * Lets a block prefer the entity Layout Builder is rendering over the route.
 *
 * A node is rendered on routes other than its canonical one -- and sometimes
 * on a route that names a different node, or no node at all. Search API
 * renders the node in the request that saves it, so whatever a block resolves
 * there is indexed as part of the page's content. That makes the route the
 * wrong source on /node/{node}/layout, on a revision route, on
 * /node/add/{type} (the first save of every new page) and on a bulk operation
 * from /admin/content. Layout Builder hands the rendered entity to the block
 * as a context, which is true regardless of the route, so it is the first
 * choice; each block keeps its own route/request lookup as a fallback tier.
 *
 * The context slot is named "layout_builder.entity" rather than given a short
 * local name such as "entity", and that name is load-bearing.
 * \Drupal\Core\Plugin\Context\ContextHandler::applyContextMapping() resolves a
 * slot against the plugin's stored context_mapping and falls back to the
 * slot's OWN name when the mapping has no entry for it, so for an unmapped
 * slot the name decides which of Layout Builder's contexts it picks up.
 *
 * Layout Builder offers the rendered entity under different keys depending on
 * the path. LayoutBuilderEntityViewDisplay supplies both "entity" and
 * "layout_builder.entity" while rendering, but in the Layout Builder preview
 * OverridesSectionStorage::getContextsDuringPreview() copies "entity" to
 * "layout_builder.entity" and then unsets "entity", and
 * DefaultsSectionStorage::getContextsDuringPreview() only ever sets
 * "layout_builder.entity". That key is therefore the only one present on every
 * path, so an unmapped slot named after it resolves everywhere.
 *
 * This is what avoids a data update, which is the reason this approach was
 * previously passed over. Core's own field blocks use a slot named "entity"
 * plus a stored context_mapping of {entity: layout_builder.entity}, written at
 * placement time by LayoutBuilderEntityViewDisplay::setComponent() precisely
 * because a bare "entity" slot would not resolve during preview. Every page
 * that already carries one of these blocks stored an EMPTY context_mapping, so
 * taking that route would mean writing the mapping into the
 * layout_builder__layout field of every node with an overridden layout on
 * every site, and any node the update missed would silently keep the bug.
 *
 * An annotation cannot be inherited from a trait, so each using plugin must
 * still declare the slot itself:
 *
 * @code
 * context_definitions = {
 *   "layout_builder.entity" = @ContextDefinition("entity",
 *     label = @Translation("Entity being viewed"),
 *     required = FALSE
 *   )
 * }
 * @endcode
 */
trait LayoutBuilderEntityContextTrait {

  /**
   * Name of the context slot holding the entity Layout Builder is rendering.
   *
   * Matches the context ID Layout Builder publishes, so that an empty stored
   * context_mapping still resolves. Keep in sync with the plugin annotations,
   * which cannot reference this constant.
   */
  const ENTITY_CONTEXT = 'layout_builder.entity';

  /**
   * Gets the node Layout Builder is rendering, if it handed one over.
   *
   * The context is not guaranteed to hold a node: Layout Builder hands over
   * whatever entity the display belongs to, and the defaults layout screen
   * passes a generated sample entity of the display's own type. The slot is
   * also declared as a generic entity, so this guard is what keeps a non-node
   * out of the caller's node handling.
   *
   * @return \Drupal\node\NodeInterface|null
   *   The node being rendered, or NULL when Layout Builder offered no node.
   */
  protected function getRenderedEntityNode(): ?NodeInterface {
    // Read through getContexts() rather than getContextValue() so a plugin
    // definition cached before this slot existed cannot throw during the
    // window between a code deploy and the cache rebuild that follows it.
    $context = $this->getContexts()[self::ENTITY_CONTEXT] ?? NULL;
    $entity = $context ? $context->getContextValue() : NULL;

    return $entity instanceof NodeInterface ? $entity : NULL;
  }

  /**
   * Gets the node Layout Builder is rendering, but only if it is real content.
   *
   * Use this instead of getRenderedEntityNode() in any block that reads more
   * than the node's label. Layout Builder's Defaults layout screen
   * (/admin/structure/types/manage/{type}/display/default/layout) supplies a
   * GENERATED SAMPLE entity: LayoutBuilderSampleEntityGenerator::get() builds
   * it with createWithSampleValues() and stashes it in a tempstore, and never
   * saves it. It therefore has no ID, so $node->toUrl() throws "cannot have a
   * URI as it does not have an ID", and its reference fields can hold items
   * whose target is NULL because the selection handler found nothing
   * referenceable.
   *
   * Before these blocks declared a context, that screen carried no node on the
   * route and they rendered nothing on it. Excluding the sample entity keeps
   * that exactly as it was rather than sending field-heavy blocks down a path
   * they were never written for.
   *
   * An unsaved node is also what node preview hands over for a page that has
   * never been saved; that likewise falls back, matching today's behaviour.
   * Preview of an EXISTING node still resolves, because it has an ID.
   *
   * @return \Drupal\node\NodeInterface|null
   *   The saved node being rendered, or NULL when Layout Builder offered no
   *   node or offered one that has never been saved.
   */
  protected function getRenderedEntitySavedNode(): ?NodeInterface {
    $node = $this->getRenderedEntityNode();

    return $node && !$node->isNew() ? $node : NULL;
  }

  /**
   * {@inheritdoc}
   *
   * Removes the context-assignment select that BlockBase adds for any plugin
   * declaring a context. Editors open these forms routinely, and the sections
   * holding these blocks are not all locked against block update, so the
   * select would be visible to them -- listing raw context IDs on a form the
   * platform deliberately keeps plain. There is also nothing to choose: these
   * blocks always want the entity Layout Builder is rendering, and picking one
   * of the route-derived entity contexts instead would store a context_mapping
   * that reinstates the very bug this resolves, on that page only and
   * invisibly. Leaving the mapping empty also keeps this change revertible: a
   * stored mapping naming a slot a reverted plugin no longer declares makes
   * applyContextMapping() throw.
   *
   * ConfigureBlockFormBase::submitForm() reads context_mapping with a default
   * of [], so removing the element stores the empty mapping unchanged.
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);
    unset($form['context_mapping']);

    return $form;
  }

}
