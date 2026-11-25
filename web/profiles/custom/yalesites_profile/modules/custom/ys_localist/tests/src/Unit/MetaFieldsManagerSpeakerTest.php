<?php

namespace Drupal\Tests\ys_localist\Unit;

use Drupal\Core\Datetime\DateFormatter;
use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\node\NodeInterface;
use Drupal\ys_localist\LocalistManager;
use Drupal\ys_localist\MetaFieldsManager;

/**
 * Unit tests for the speaker name shown under an event title.
 *
 * @coversDefaultClass \Drupal\ys_localist\MetaFieldsManager
 *
 * @group yalesites
 * @group ys_localist
 */
class MetaFieldsManagerSpeakerTest extends UnitTestCase {

  /**
   * Calls getSpeakerLine() for an event with the given field values.
   */
  protected function speakerLine(string $speaker): ?string {
    $values = ['field_event_speaker' => $speaker];
    $node = $this->createMock(NodeInterface::class);
    $node->method('get')->willReturnCallback(function ($name) use ($values) {
      $list = $this->createMock(FieldItemListInterface::class);
      $list->method('getString')->willReturn($values[$name]);
      return $list;
    });
    $manager = new MetaFieldsManager(
      $this->createMock(DateFormatter::class),
      $this->createMock(EntityTypeManager::class),
      $this->createMock(LocalistManager::class),
    );
    return $manager->getSpeakerLine($node);
  }

  /**
   * @covers ::getSpeakerLine
   */
  public function testSpeakerIsTrimmed(): void {
    $this->assertSame('Ada Lovelace', $this->speakerLine('Ada Lovelace'));
    $this->assertSame('Ada Lovelace', $this->speakerLine(' Ada Lovelace '));
  }

  /**
   * @covers ::getSpeakerLine
   */
  public function testNoSpeakerShowsNothing(): void {
    $this->assertNull($this->speakerLine(''));
    $this->assertNull($this->speakerLine('   '));
  }

}
