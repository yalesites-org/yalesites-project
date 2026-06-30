<?php

namespace Drupal\Tests\ys_beacon\Unit;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\metatag\MetatagManager;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_beacon\Service\BeaconIndexability;

/**
 * Tests the AI-indexing opt-out rule, including the media default.
 *
 * @group ys_beacon
 * @coversDefaultClass \Drupal\ys_beacon\Service\BeaconIndexability
 */
class BeaconIndexabilityTest extends UnitTestCase {

  /**
   * Media with no AI-indexing tag is excluded by default (opt-in).
   *
   * @covers ::isIndexingDisabled
   */
  public function testMediaIsExcludedByDefault(): void {
    $this->assertTrue($this->isDisabled('media', []));
    $this->assertTrue($this->isDisabled('media', ['ai_disable_indexing' => '']));
  }

  /**
   * Non-media content with no tag is included by default (opt-out).
   *
   * @covers ::isIndexingDisabled
   */
  public function testNodeIsIncludedByDefault(): void {
    $this->assertFalse($this->isDisabled('node', []));
  }

  /**
   * Media explicitly opted in (unchecked "Disable") is indexed.
   *
   * @covers ::isIndexingDisabled
   */
  public function testMediaOptedInIsIndexed(): void {
    $this->assertFalse($this->isDisabled('media', ['ai_disable_indexing' => 'enabled']));
  }

  /**
   * An explicit "disabled" tag excludes content of any entity type.
   *
   * @covers ::isIndexingDisabled
   */
  public function testExplicitDisabledExcludes(): void {
    $this->assertTrue($this->isDisabled('node', ['ai_disable_indexing' => 'disabled']));
    $this->assertTrue($this->isDisabled('media', ['ai_disable_indexing' => 'disabled']));
  }

  /**
   * Runs isIndexingDisabled for an entity type with the given resolved tags.
   *
   * @param string $entity_type_id
   *   The entity type id ('media', 'node', ...).
   * @param array $tags
   *   The tags returned by tagsFromEntityWithDefaults().
   *
   * @return bool
   *   The isIndexingDisabled() result.
   */
  private function isDisabled(string $entity_type_id, array $tags): bool {
    $entity = $this->createMock(ContentEntityInterface::class);
    $entity->method('getEntityTypeId')->willReturn($entity_type_id);

    $manager = $this->createMock(MetatagManager::class);
    $manager->method('tagsFromEntityWithDefaults')->willReturn($tags);
    // Token replacement is a passthrough for the resolved tag value.
    $manager->method('generateTokenValues')->willReturnArgument(0);

    return (new BeaconIndexability($manager))->isIndexingDisabled($entity);
  }

}
