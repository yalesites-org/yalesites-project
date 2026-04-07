<?php

namespace Drupal\Tests\ys_core\Unit\EventSubscriber;

use Drupal\layout_builder\Event\SectionComponentBuildRenderArrayEvent;
use Drupal\layout_builder\LayoutBuilderEvents;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_core\EventSubscriber\ExtraFieldPlaceholderSubscriber;

/**
 * Unit tests for ExtraFieldPlaceholderSubscriber.
 *
 * @covers \Drupal\ys_core\EventSubscriber\ExtraFieldPlaceholderSubscriber
 * @group ys_core
 */
class ExtraFieldPlaceholderSubscriberTest extends UnitTestCase {

  /**
   * Creates a mock event with the given build and preview state.
   */
  protected function createEvent(array $build, bool $in_preview): SectionComponentBuildRenderArrayEvent {
    $event = $this->createMock(SectionComponentBuildRenderArrayEvent::class);
    $event->method('inPreview')->willReturn($in_preview);
    $event->method('getBuild')->willReturn($build);
    return $event;
  }

  /**
   * Tests that the subscriber registers on the correct event at priority 50.
   */
  public function testGetSubscribedEvents(): void {
    $events = ExtraFieldPlaceholderSubscriber::getSubscribedEvents();
    $this->assertArrayHasKey(LayoutBuilderEvents::SECTION_COMPONENT_BUILD_RENDER_ARRAY, $events);
    $this->assertEquals(['onBuildRender', 50], $events[LayoutBuilderEvents::SECTION_COMPONENT_BUILD_RENDER_ARRAY]);
  }

  /**
   * Tests placeholder markup is removed for a targeted field outside preview.
   */
  public function testNonPreviewRemovesMarkupForTargetedField(): void {
    $build = [
      'content' => [
        '#extra_field_placeholder_field_name' => 'content_moderation_control',
        '#markup' => 'Placeholder for the "Moderation control" field',
      ],
    ];
    $event = $this->createEvent($build, FALSE);
    $event->expects($this->once())
      ->method('setBuild')
      ->with($this->callback(function (array $actual_build): bool {
        return !isset($actual_build['content']['#markup']);
      }));

    $subscriber = new ExtraFieldPlaceholderSubscriber();
    $subscriber->onBuildRender($event);
  }

  /**
   * Tests that placeholder markup is preserved in preview mode.
   */
  public function testPreviewModeKeepsMarkup(): void {
    $build = [
      'content' => [
        '#extra_field_placeholder_field_name' => 'content_moderation_control',
        '#markup' => 'Placeholder for the "Moderation control" field',
      ],
    ];
    $event = $this->createEvent($build, TRUE);
    $event->expects($this->never())->method('setBuild');

    $subscriber = new ExtraFieldPlaceholderSubscriber();
    $subscriber->onBuildRender($event);
  }

  /**
   * Tests that a field not in TARGETED_FIELDS is left untouched.
   */
  public function testNonTargetedFieldIsUntouched(): void {
    $build = [
      'content' => [
        '#extra_field_placeholder_field_name' => 'links',
        '#markup' => 'Placeholder for the "Links" field',
      ],
    ];
    $event = $this->createEvent($build, FALSE);
    $event->expects($this->never())->method('setBuild');

    $subscriber = new ExtraFieldPlaceholderSubscriber();
    $subscriber->onBuildRender($event);
  }

  /**
   * Tests that a build without #extra_field_placeholder_field_name is ignored.
   */
  public function testBuildWithoutPlaceholderKeyIsIgnored(): void {
    $build = [
      'content' => [
        '#markup' => 'Some regular content',
      ],
    ];
    $event = $this->createEvent($build, FALSE);
    $event->expects($this->never())->method('setBuild');

    $subscriber = new ExtraFieldPlaceholderSubscriber();
    $subscriber->onBuildRender($event);
  }

  /**
   * Tests that an empty build is ignored.
   */
  public function testEmptyBuildIsIgnored(): void {
    $event = $this->createEvent([], FALSE);
    $event->expects($this->never())->method('setBuild');

    $subscriber = new ExtraFieldPlaceholderSubscriber();
    $subscriber->onBuildRender($event);
  }

  /**
   * Tests that 'all' wildcard suppresses any extra field placeholder.
   */
  public function testAllWildcardSuppressesAnyField(): void {
    $build = [
      'content' => [
        '#extra_field_placeholder_field_name' => 'links',
        '#markup' => 'Placeholder for the "Links" field',
      ],
    ];
    $event = $this->createEvent($build, FALSE);
    $event->expects($this->once())
      ->method('setBuild')
      ->with($this->callback(function (array $actual_build): bool {
        return !isset($actual_build['content']['#markup']);
      }));

    $subscriber = new AllFieldsExtraFieldPlaceholderSubscriber();
    $subscriber->onBuildRender($event);
  }

  /**
   * Tests that an empty TARGETED_FIELDS disables all suppression.
   */
  public function testEmptyTargetedFieldsDisablesSuppression(): void {
    $build = [
      'content' => [
        '#extra_field_placeholder_field_name' => 'content_moderation_control',
        '#markup' => 'Placeholder for the "Moderation control" field',
      ],
    ];
    $event = $this->createEvent($build, FALSE);
    $event->expects($this->never())->method('setBuild');

    $subscriber = new DisabledExtraFieldPlaceholderSubscriber();
    $subscriber->onBuildRender($event);
  }

}

/**
 * Test variant with 'all' wildcard to cover the catch-all behavior.
 */
class AllFieldsExtraFieldPlaceholderSubscriber extends ExtraFieldPlaceholderSubscriber {

  protected const TARGETED_FIELDS = ['all'];

}

/**
 * Test variant with empty TARGETED_FIELDS to verify disabled behavior.
 */
class DisabledExtraFieldPlaceholderSubscriber extends ExtraFieldPlaceholderSubscriber {

  protected const TARGETED_FIELDS = [];

}
