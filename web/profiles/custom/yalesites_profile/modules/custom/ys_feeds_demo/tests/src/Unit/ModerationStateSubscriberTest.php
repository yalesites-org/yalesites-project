<?php

namespace Drupal\Tests\ys_feeds_demo\Unit;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\content_moderation\ModerationInformationInterface;
use Drupal\feeds\Event\EntityEvent;
use Drupal\feeds\FeedInterface;
use Drupal\ys_feeds_demo\EventSubscriber\ModerationStateSubscriber;

/**
 * Unit tests for ModerationStateSubscriber.
 *
 * The rule worth protecting here is the negative one: an entity that already
 * exists must be left alone. A sync updates the fields it was told about and
 * forms no opinion about whether an editor should have unpublished something.
 *
 * @coversDefaultClass \Drupal\ys_feeds_demo\EventSubscriber\ModerationStateSubscriber
 * @group ys_feeds_demo
 * @group yalesites
 */
class ModerationStateSubscriberTest extends UnitTestCase {

  /**
   * The moderation information mock.
   *
   * @var \Drupal\content_moderation\ModerationInformationInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $moderationInformation;

  /**
   * The subscriber under test.
   *
   * @var \Drupal\ys_feeds_demo\EventSubscriber\ModerationStateSubscriber
   */
  protected $subscriber;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->moderationInformation = $this->createMock(ModerationInformationInterface::class);
    $this->subscriber = new ModerationStateSubscriber($this->moderationInformation);
  }

  /**
   * Builds a presave event for a given entity and feed type.
   *
   * @param \PHPUnit\Framework\MockObject\MockObject $entity
   *   The entity being imported.
   * @param string $feed_bundle
   *   The feed type id the import is running under.
   *
   * @return \Drupal\feeds\Event\EntityEvent|\PHPUnit\Framework\MockObject\MockObject
   *   The event.
   */
  protected function event($entity, string $feed_bundle) {
    $feed = $this->createMock(FeedInterface::class);
    $feed->method('bundle')->willReturn($feed_bundle);

    $event = $this->createMock(EntityEvent::class);
    $event->method('getEntity')->willReturn($entity);
    $event->method('getFeed')->willReturn($feed);

    return $event;
  }

  /**
   * A newly imported moderated node is given an explicit state.
   *
   * @covers ::onPresave
   */
  public function testNewModeratedEntityIsPublished(): void {
    $entity = $this->createMock(ContentEntityInterface::class);
    $entity->method('isNew')->willReturn(TRUE);
    $this->moderationInformation->method('isModeratedEntity')->willReturn(TRUE);

    $entity->expects($this->once())
      ->method('set')
      ->with('moderation_state', 'published');

    $this->subscriber->onPresave($this->event($entity, 'demo_staff_roster'));
  }

  /**
   * An existing node keeps whatever state its editor left it in.
   *
   * @covers ::onPresave
   */
  public function testExistingEntityIsLeftAlone(): void {
    $entity = $this->createMock(ContentEntityInterface::class);
    $entity->method('isNew')->willReturn(FALSE);
    $this->moderationInformation->method('isModeratedEntity')->willReturn(TRUE);

    $entity->expects($this->never())->method('set');

    $this->subscriber->onPresave($this->event($entity, 'demo_staff_roster'));
  }

  /**
   * Content outside any workflow is not touched.
   *
   * @covers ::onPresave
   */
  public function testUnmoderatedEntityIsLeftAlone(): void {
    $entity = $this->createMock(ContentEntityInterface::class);
    $entity->method('isNew')->willReturn(TRUE);
    $this->moderationInformation->method('isModeratedEntity')->willReturn(FALSE);

    $entity->expects($this->never())->method('set');

    $this->subscriber->onPresave($this->event($entity, 'demo_staff_roster'));
  }

  /**
   * Enabling the demo must not change how anyone else's feeds behave.
   *
   * @covers ::onPresave
   */
  public function testOtherFeedTypesAreLeftAlone(): void {
    $entity = $this->createMock(ContentEntityInterface::class);
    $entity->method('isNew')->willReturn(TRUE);
    $this->moderationInformation->method('isModeratedEntity')->willReturn(TRUE);

    $entity->expects($this->never())->method('set');

    $this->subscriber->onPresave($this->event($entity, 'some_other_feed'));
  }

  /**
   * The subscriber listens on the presave event.
   *
   * @covers ::getSubscribedEvents
   */
  public function testSubscribesToPresave(): void {
    $events = ModerationStateSubscriber::getSubscribedEvents();
    $this->assertArrayHasKey('feeds.process_entity_presave', $events);
  }

}
