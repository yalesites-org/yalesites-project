<?php

namespace Drupal\ys_core\EventSubscriber;

use Drupal\layout_builder\Event\SectionComponentBuildRenderArrayEvent;
use Drupal\layout_builder\LayoutBuilderEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Prevents ExtraFieldBlock placeholder text from rendering on published pages.
 *
 * Layout Builder's ExtraFieldBlock always sets placeholder markup in its
 * build() method that should be replaced by hook_entity_view_alter(). In edge
 * cases (stale cache, render pipeline issues), this replacement can fail,
 * causing "Placeholder for the ..." text to be visible to site visitors.
 *
 * This subscriber acts as a safety net: outside of Layout Builder preview
 * mode, it removes the placeholder markup for targeted extra fields so it
 * can never leak to end users.
 *
 * @see \Drupal\layout_builder\Plugin\Block\ExtraFieldBlock::build()
 * @see \Drupal\layout_builder\EventSubscriber\BlockComponentRenderArray::onBuildRender()
 */
class ExtraFieldPlaceholderSubscriber implements EventSubscriberInterface {

  /**
   * Extra field names whose placeholder markup should be suppressed.
   *
   * - Empty array []: disabled, no placeholders are suppressed.
   * - ['all']: ALL ExtraFieldBlock placeholders are suppressed outside preview.
   * - ['content_moderation_control', ...]: only listed fields are suppressed.
   *
   * @var string[]
   */
  protected const TARGETED_FIELDS = [
    'content_moderation_control',
  ];

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Run at priority 50, after BlockComponentRenderArray at priority 100.
    $events[LayoutBuilderEvents::SECTION_COMPONENT_BUILD_RENDER_ARRAY] = ['onBuildRender', 50];
    return $events;
  }

  /**
   * Removes placeholder markup from extra field blocks outside preview mode.
   *
   * @param \Drupal\layout_builder\Event\SectionComponentBuildRenderArrayEvent $event
   *   The section component render event.
   */
  public function onBuildRender(SectionComponentBuildRenderArrayEvent $event) {
    if ($event->inPreview()) {
      return;
    }

    $build = $event->getBuild();

    if (!isset($build['content']['#extra_field_placeholder_field_name'])) {
      return;
    }

    $field_name = $build['content']['#extra_field_placeholder_field_name'];

    if (in_array('all', static::TARGETED_FIELDS, TRUE) || in_array($field_name, static::TARGETED_FIELDS, TRUE)) {
      unset($build['content']['#markup']);
      $event->setBuild($build);
    }
  }

}
