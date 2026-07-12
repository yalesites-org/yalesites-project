<?php

namespace Drupal\Tests\ys_ai_system_instructions\Kernel;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\ys_ai_system_instructions\Service\SystemInstructionsStorageService;
use Drupal\ys_ai_system_instructions\Service\TextFormatDetectionService;

/**
 * Kernel tests for SystemInstructionsStorageService against a real database.
 *
 * The module's own table is created directly from its hook_schema()
 * definition rather than via installSchema(), which would require enabling
 * ys_ai_system_instructions' full dependency chain (ys_ai, ai_engine, key,
 * ys_integrations) -- unrelated to exercising this storage layer.
 *
 * @coversDefaultClass \Drupal\ys_ai_system_instructions\Service\SystemInstructionsStorageService
 * @group ys_ai_system_instructions
 * @group ys_ai
 * @group yalesites
 */
class SystemInstructionsStorageServiceTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system'];

  /**
   * The storage service under test.
   *
   * @var \Drupal\ys_ai_system_instructions\Service\SystemInstructionsStorageService
   */
  protected $storage;

  /**
   * The mocked current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $currentUser;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    require_once dirname(__DIR__, 3) . '/ys_ai_system_instructions.install';
    $schema_definition = ys_ai_system_instructions_schema();
    $this->container->get('database')->schema()->createTable(
      SystemInstructionsStorageService::TABLE_NAME,
      $schema_definition[SystemInstructionsStorageService::TABLE_NAME]
    );

    $this->currentUser = $this->createMock(AccountProxyInterface::class);
    $this->currentUser->method('id')->willReturn(1);

    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(1000);

    $this->storage = new SystemInstructionsStorageService(
      $this->container->get('database'),
      $this->currentUser,
      $time,
      new TextFormatDetectionService()
    );
  }

  /**
   * Tests getActiveInstructions() with an empty table.
   *
   * @covers ::getActiveInstructions
   */
  public function testGetActiveInstructionsReturnsNullWhenEmpty(): void {
    $this->assertNull($this->storage->getActiveInstructions());
  }

  /**
   * Tests createVersion() creates version 1, active, under the current user.
   *
   * @covers ::createVersion
   * @covers ::getActiveInstructions
   */
  public function testCreateVersionCreatesFirstActiveVersion(): void {
    $version = $this->storage->createVersion('Be helpful.', 'Initial version');

    $this->assertSame(1, $version);

    $active = $this->storage->getActiveInstructions();
    $this->assertSame('Be helpful.', $active['instructions']);
    $this->assertSame('1', $active['version']);
    $this->assertSame('1', $active['is_active']);
    $this->assertSame('1', $active['created_by']);
    $this->assertSame('Initial version', $active['notes']);
  }

  /**
   * Tests createVersion() honors an explicit created_by, e.g. API sync.
   *
   * @covers ::createVersion
   */
  public function testCreateVersionHonorsExplicitCreatedBy(): void {
    $this->storage->createVersion('Synced instructions', 'Synced from API', 1);

    $active = $this->storage->getActiveInstructions();
    $this->assertSame('1', $active['created_by']);
  }

  /**
   * Tests createVersion() deactivates the previous version.
   *
   * @covers ::createVersion
   * @covers ::getVersion
   */
  public function testCreateVersionDeactivatesPreviousVersion(): void {
    $this->storage->createVersion('First version');
    $second = $this->storage->createVersion('Second version');

    $this->assertSame(2, $second);

    $first_record = $this->storage->getVersion(1);
    $this->assertSame('0', $first_record['is_active']);

    $active = $this->storage->getActiveInstructions();
    $this->assertSame('2', $active['version']);
    $this->assertSame('Second version', $active['instructions']);
  }

  /**
   * Tests getVersion() for a version that does not exist.
   *
   * @covers ::getVersion
   */
  public function testGetVersionReturnsNullForMissingVersion(): void {
    $this->assertNull($this->storage->getVersion(99));
  }

  /**
   * Tests getAllVersions() returns every version ordered by version desc.
   *
   * @covers ::getAllVersions
   */
  public function testGetAllVersionsOrdersByVersionDescending(): void {
    $this->storage->createVersion('First');
    $this->storage->createVersion('Second');
    $this->storage->createVersion('Third');

    $versions = $this->storage->getAllVersions();

    $this->assertCount(3, $versions);
    $this->assertSame(['3', '2', '1'], array_column($versions, 'version'));
  }

  /**
   * Tests setActiveVersion() reactivates an older version.
   *
   * @covers ::setActiveVersion
   * @covers ::getVersion
   */
  public function testSetActiveVersionReactivatesOlderVersion(): void {
    $this->storage->createVersion('First');
    $this->storage->createVersion('Second');

    $result = $this->storage->setActiveVersion(1);

    $this->assertTrue($result);
    $this->assertSame('1', $this->storage->getVersion(1)['is_active']);
    $this->assertSame('0', $this->storage->getVersion(2)['is_active']);
    $this->assertSame('1', $this->storage->getActiveInstructions()['version']);
  }

  /**
   * Tests setActiveVersion() for a version that does not exist.
   *
   * @covers ::setActiveVersion
   */
  public function testSetActiveVersionReturnsFalseForMissingVersion(): void {
    $this->assertFalse($this->storage->setActiveVersion(99));
  }

  /**
   * Tests areInstructionsDifferent() with no active instructions.
   *
   * @covers ::areInstructionsDifferent
   */
  public function testAreInstructionsDifferentIsTrueWhenNoActiveVersion(): void {
    $this->assertTrue($this->storage->areInstructionsDifferent('Anything'));
  }

  /**
   * Tests areInstructionsDifferent() against a matching active version.
   *
   * @covers ::areInstructionsDifferent
   */
  public function testAreInstructionsDifferentIsFalseWhenUnchanged(): void {
    $this->storage->createVersion('Consistent instructions.');

    $this->assertFalse($this->storage->areInstructionsDifferent('Consistent instructions.'));
  }

  /**
   * Tests areInstructionsDifferent() against a differing active version.
   *
   * @covers ::areInstructionsDifferent
   */
  public function testAreInstructionsDifferentIsTrueWhenChanged(): void {
    $this->storage->createVersion('Original instructions.');

    $this->assertTrue($this->storage->areInstructionsDifferent('Completely different instructions.'));
  }

  /**
   * Tests getVersionCount() reflects the number of stored versions.
   *
   * @covers ::getVersionCount
   */
  public function testGetVersionCountReflectsStoredVersions(): void {
    $this->assertSame(0, $this->storage->getVersionCount());

    $this->storage->createVersion('First');
    $this->storage->createVersion('Second');

    $this->assertSame(2, $this->storage->getVersionCount());
  }

  /**
   * Tests deleteVersion() refuses to delete the active version.
   *
   * @covers ::deleteVersion
   */
  public function testDeleteVersionRefusesActiveVersion(): void {
    $this->storage->createVersion('First');

    $this->assertFalse($this->storage->deleteVersion(1));
    $this->assertNotNull($this->storage->getVersion(1));
  }

  /**
   * Tests deleteVersion() removes an inactive version.
   *
   * @covers ::deleteVersion
   */
  public function testDeleteVersionRemovesInactiveVersion(): void {
    $this->storage->createVersion('First');
    $this->storage->createVersion('Second');

    $result = $this->storage->deleteVersion(1);

    $this->assertTrue($result);
    $this->assertNull($this->storage->getVersion(1));
  }

  /**
   * Tests deleteVersion() for a version that does not exist.
   *
   * @covers ::deleteVersion
   */
  public function testDeleteVersionReturnsFalseForMissingVersion(): void {
    $this->assertFalse($this->storage->deleteVersion(99));
  }

}
