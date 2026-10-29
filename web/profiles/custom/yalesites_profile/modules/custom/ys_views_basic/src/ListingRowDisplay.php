<?php

namespace Drupal\ys_views_basic;

use Drupal\Core\Security\TrustedCallbackInterface;
use Drupal\node\NodeInterface;

/**
 * Hands each listing row its listing's display flags while it renders.
 *
 * Atomic's node templates read a listing's display flags off the node itself
 * (node.show_categories, node.card_heading_level, node.pin_label, ...). The
 * result nodes come from the entity static cache, so two listings showing the
 * same node share one object, and their rows only render once the whole page
 * renders, after every listing has run. Setting the flags on the node when
 * the listing runs would leave the last listing's flags on all of them
 * (yalesites-org/yalesites-project#1591).
 *
 * Instead each row's render array carries its own flags. A #pre_render
 * callback sets them on the node just before that row renders, and a
 * #post_render callback puts back whatever was there before, so the node
 * holds a listing's flags only while that listing's row is rendering.
 *
 * Why this holds: the Renderer runs a row's #pre_render callbacks, then its
 * #theme (the node template and its preprocess), then its #post_render
 * callbacks, all in one doRender() call, so nothing else renders in between
 * except what the row itself nests. A nested render of the same node saves
 * and restores the outer row's flags in turn, like a stack. A render cache
 * hit skips both callbacks and returns markup that was rendered with them;
 * the listing view modes are not render cached anyway (max-age 0, see
 * ys_views_basic_node_view()).
 */
final class ListingRowDisplay implements TrustedCallbackInterface {

  /**
   * The row render array key carrying the flags to set.
   */
  const FLAGS_KEY = '#ys_views_basic_row_display';

  /**
   * The row render array key carrying the values the flags replaced.
   */
  const PREVIOUS_KEY = '#ys_views_basic_row_display_previous';

  /**
   * Returns the per-row display flags a scaffold view's arguments describe.
   *
   * @param string $view_id
   *   The scaffold view id.
   * @param array $args
   *   The view arguments ViewsBasicManager::configureView() wrote.
   *
   * @return array
   *   Node property values keyed by property name, except pin_label, which
   *   depends on the row (see ::pinLabelFromArgs()).
   */
  public static function flagsFromArgs(string $view_id, array $args): array {
    $field = ViewsBasicManager::fieldDisplayOptionsFromArgs($args);
    $event = ViewsBasicManager::displayOptionsFromArgs($args, 'event_field_display_options', ['hide_add_to_calendar' => 0]);
    $post = ViewsBasicManager::displayOptionsFromArgs($args, 'post_field_display_options', ['show_eyebrow' => 0]);
    $profile = ViewsBasicManager::displayOptionsFromArgs($args, 'profile_field_display_options', [
      'show_department' => 0,
      'show_email' => 0,
      'show_phone' => 0,
      'show_pronouns' => 0,
    ]);

    $flags = [
      'show_categories' => $field['show_categories'],
      'show_tags' => $field['show_tags'],
      'show_thumbnail' => $field['show_thumbnail'],
      'hide_add_to_calendar' => $event['hide_add_to_calendar'],
      'show_eyebrow' => $post['show_eyebrow'],
      // Profile data pass-throughs (#1648).
      'show_department' => $profile['show_department'],
      'show_email' => $profile['show_email'],
      'show_phone' => $profile['show_phone'],
      'show_pronouns' => $profile['show_pronouns'],
      // Context-derived heading level for each result card: H3 when the block
      // renders its own heading (the cards nest under it), H2 when the cards
      // are the first heading on the page. The listing node templates read
      // node.card_heading_level, falling back to their own default for non
      // listing renders, which never set this property.
      'card_heading_level' => !empty($field['block_has_heading']) ? '3' : '2',
    ];

    // Atomic's resource templates read the category flag as show_category
    // and each resource detail under its own name (#1723).
    if ($view_id === 'views_basic_scaffold_resources') {
      // @todo Rename with ys_views_content_resources' removal in the follow-up
      //   to yalesites-org/YaleSites-Internal#1723, once Atomic reads
      //   show_categories.
      $flags['show_category'] = $field['show_categories'];
      $resource = ViewsBasicManager::displayOptionsFromArgs($args, 'resource_field_display_options', array_fill_keys(ViewsBasicManager::RESOURCE_FIELD_OPTIONS, 0));
      foreach (ViewsBasicManager::RESOURCE_FIELD_OPTIONS as $option) {
        $flags[$option] = $resource[$option];
      }
    }

    return $flags;
  }

  /**
   * Returns the label a pinned (sticky) row shows in this listing.
   *
   * @param array $args
   *   The view arguments ViewsBasicManager::configureView() wrote.
   *
   * @return string
   *   The listing's pin label with all tags stripped, or '' when the listing
   *   does not pin.
   */
  public static function pinLabelFromArgs(array $args): string {
    $pin = ViewsBasicManager::displayOptionsFromArgs($args, 'pin_settings', [
      'pinned_to_top' => FALSE,
      'pin_label' => NULL,
    ]);
    if (empty($pin['pinned_to_top']) || !is_scalar($pin['pin_label'])) {
      return '';
    }
    // Editor text, and on the views/ajax path browser text, with two sinks
    // in the card templates: the visible overlay prints it with |raw, and the
    // card's aria-label concatenates it into an autoescaped attribute. So
    // strip tags (the |raw sink) but leave entities and ampersands alone:
    // encoding them (Html::escape(), or Xss::filter(), which turns a bare &
    // into &amp;) would reach screen readers as a literal "&amp;". Never
    // decode entities either: an encoded &lt;script&gt; must stay encoded
    // for the |raw sink.
    return strip_tags((string) $pin['pin_label']);
  }

  /**
   * Returns whether a result row's entity is a pinned (sticky) node.
   *
   * @param mixed $entity
   *   The row entity.
   *
   * @return bool
   *   TRUE for a sticky node.
   */
  public static function isSticky(mixed $entity): bool {
    return $entity instanceof NodeInterface && $entity->isSticky();
  }

  /**
   * Attaches display flags to a row's render array.
   *
   * @param array $build
   *   The row render array, an entity view build carrying '#node'.
   * @param array $flags
   *   Node property values keyed by property name. NULL leaves the property
   *   unset while the row renders.
   *
   * @return array
   *   The row render array.
   */
  public static function attach(array $build, array $flags): array {
    $build[self::FLAGS_KEY] = $flags;
    $build['#pre_render'] = $build['#pre_render'] ?? [];
    // Ahead of the entity view builder's own #pre_render, so the flags are
    // already in place for anything the node build runs, as they were when
    // they were set on the node before rendering began.
    array_unshift($build['#pre_render'], [self::class, 'preRender']);
    $build['#post_render'][] = [self::class, 'postRender'];
    return $build;
  }

  /**
   * Pre-render callback: sets the row's flags on its node.
   *
   * @param array $element
   *   The row render array.
   *
   * @return array
   *   The row render array, with the values the flags replaced.
   */
  public static function preRender(array $element): array {
    $node = $element['#node'] ?? NULL;
    if (!is_object($node) || !is_array($element[self::FLAGS_KEY] ?? NULL)) {
      return $element;
    }
    $element[self::PREVIOUS_KEY] = self::swap($node, $element[self::FLAGS_KEY]);
    return $element;
  }

  /**
   * Post-render callback: restores what the node held before the row.
   *
   * @param \Drupal\Component\Render\MarkupInterface|string $markup
   *   The rendered row.
   * @param array $element
   *   The row render array.
   *
   * @return \Drupal\Component\Render\MarkupInterface|string
   *   The rendered row, unchanged.
   */
  public static function postRender($markup, array $element) {
    $node = $element['#node'] ?? NULL;
    if (is_object($node) && is_array($element[self::PREVIOUS_KEY] ?? NULL)) {
      self::swap($node, $element[self::PREVIOUS_KEY]);
    }
    return $markup;
  }

  /**
   * Sets properties on an object, returning the values they replaced.
   *
   * @param object $object
   *   The node.
   * @param array $values
   *   Property values keyed by name; NULL unsets the property.
   *
   * @return array
   *   The previous values keyed by name, NULL for a property that was unset.
   */
  private static function swap(object $object, array $values): array {
    $previous = [];
    foreach ($values as $name => $value) {
      $previous[$name] = $object->{$name} ?? NULL;
      if ($value === NULL) {
        unset($object->{$name});
      }
      else {
        $object->{$name} = $value;
      }
    }
    return $previous;
  }

  /**
   * {@inheritdoc}
   */
  public static function trustedCallbacks() {
    return ['preRender', 'postRender'];
  }

}
