<?php

namespace Drupal\Tests\ys_migrate\Unit;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\node\NodeInterface;
use Drupal\ys_migrate\Service\ProfileImportService;
use Drupal\ys_migrate\Service\TaxonomyResolverService;

/**
 * Unit tests for ProfileImportService.
 *
 * @coversDefaultClass \Drupal\ys_migrate\Service\ProfileImportService
 * @group ys_migrate
 * @group yalesites
 */
class ProfileImportServiceTest extends UnitTestCase {

  /**
   * The taxonomy resolver mock.
   *
   * @var \Drupal\ys_migrate\Service\TaxonomyResolverService|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $taxonomyResolver;

  /**
   * The node storage mock.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $nodeStorage;

  /**
   * The entity type manager mock.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $entityTypeManager;

  /**
   * The logger channel mock.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $loggerChannel;

  /**
   * The logger factory mock.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $loggerFactory;

  /**
   * The current user mock.
   *
   * @var \Drupal\Core\Session\AccountInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $currentUser;

  /**
   * The service under test.
   *
   * @var \Drupal\ys_migrate\Service\ProfileImportService
   */
  protected $profileImport;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->currentUser = $this->createMock(AccountInterface::class);
    $this->currentUser->method('id')->willReturn(42);

    $this->taxonomyResolver = $this->createMock(TaxonomyResolverService::class);

    $this->nodeStorage = $this->createMock(EntityStorageInterface::class);
    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->entityTypeManager->method('getStorage')->with('node')->willReturn($this->nodeStorage);

    $this->loggerChannel = $this->createMock(LoggerChannelInterface::class);
    $this->loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $this->loggerFactory->method('get')->with('ys_migrate')->willReturn($this->loggerChannel);

    $this->profileImport = new ProfileImportService(
      $this->currentUser,
      $this->taxonomyResolver,
      $this->entityTypeManager,
      $this->loggerFactory
    );
    $this->profileImport->setStringTranslation($this->getStringTranslationStub());
  }

  /**
   * Builds a query mock that returns the given nids from execute().
   */
  protected function mockQuery(array $nids): QueryInterface {
    $query = $this->createMock(QueryInterface::class);
    $query->method('condition')->willReturnSelf();
    $query->method('range')->willReturnSelf();
    $query->method('accessCheck')->willReturnSelf();
    $query->method('execute')->willReturn($nids);
    return $query;
  }

  /**
   * A full CSV row is trimmed and reshaped into the expected profile data.
   *
   * @covers ::prepareProfileData
   */
  public function testPrepareProfileDataWithFullRow() {
    $this->taxonomyResolver->method('parseCommaSeparatedValues')
      ->willReturnMap([
        ['Faculty, Staff', ['Faculty', 'Staff']],
        ['Alumni', ['Alumni']],
        ['tag1, tag2', ['tag1', 'tag2']],
        ['Custom A', ['Custom A']],
      ]);

    $row = [
      'display name' => '  Jane Doe  ',
      'first name' => ' Jane ',
      'last name' => ' Doe ',
      'honorific prefix' => 'Dr.',
      'pronouns' => 'she/her',
      'position' => 'Professor',
      'subtitle' => 'Chair',
      'department' => 'History',
      'email' => ' jane@example.com ',
      'telephone' => '203-555-0100',
      'address' => '1 Hillhouse Ave',
      'teaser title' => 'Jane Doe',
      'teaser text' => 'Short bio.',
      'affiliation' => 'Faculty, Staff',
      'audience' => 'Alumni',
      'tags' => 'tag1, tag2',
      'custom vocabulary' => 'Custom A',
    ];

    $result = $this->profileImport->prepareProfileData($row);

    $this->assertSame('Jane Doe', $result['display_name']);
    $this->assertSame('Jane', $result['first_name']);
    $this->assertSame('Doe', $result['last_name']);
    $this->assertSame('jane@example.com', $result['email']);
    $this->assertSame(['Faculty', 'Staff'], $result['affiliation']);
    $this->assertSame(['Alumni'], $result['audience']);
    $this->assertSame(['tag1', 'tag2'], $result['tags']);
    $this->assertSame(['Custom A'], $result['custom_vocab']);
  }

  /**
   * A row missing optional keys falls back to empty strings, not errors.
   *
   * @covers ::prepareProfileData
   */
  public function testPrepareProfileDataWithMissingOptionalKeys() {
    $this->taxonomyResolver->method('parseCommaSeparatedValues')->with('')->willReturn([]);

    $result = $this->profileImport->prepareProfileData(['display name' => 'Jane Doe']);

    $this->assertSame('Jane Doe', $result['display_name']);
    $this->assertSame('', $result['first_name']);
    $this->assertSame('', $result['email']);
    $this->assertSame([], $result['affiliation']);
  }

  /**
   * FindExistingProfile() returns the matching node when one exists.
   *
   * @covers ::findExistingProfile
   */
  public function testFindExistingProfileReturnsMatch() {
    $node = $this->createMock(NodeInterface::class);
    $this->nodeStorage->method('getQuery')->willReturn($this->mockQuery([7 => '7']));
    $this->nodeStorage->expects($this->once())->method('load')->with('7')->willReturn($node);

    $result = $this->profileImport->findExistingProfile('jane@example.com');

    $this->assertSame($node, $result);
  }

  /**
   * FindExistingProfile() returns NULL when no profile matches.
   *
   * @covers ::findExistingProfile
   */
  public function testFindExistingProfileReturnsNullWhenNotFound() {
    $this->nodeStorage->method('getQuery')->willReturn($this->mockQuery([]));
    $this->nodeStorage->expects($this->never())->method('load');

    $result = $this->profileImport->findExistingProfile('nobody@example.com');

    $this->assertNull($result);
  }

  /**
   * CreateProfileNode() builds the node with resolved taxonomy term ids.
   *
   * @covers ::createProfileNode
   */
  public function testCreateProfileNodeSuccess() {
    $node = $this->createMock(NodeInterface::class);
    $node->expects($this->once())->method('save');

    $this->taxonomyResolver->method('resolveTerms')
      ->willReturnMap([
        [['Faculty'], 'affiliation', [11]],
        [['Alumni'], 'audience', [12]],
        [['tag1'], 'tags', [13]],
        [['Custom A'], 'custom_vocab', [14]],
      ]);

    $this->nodeStorage->expects($this->once())
      ->method('create')
      ->with($this->callback(function (array $values) {
        return $values['type'] === 'profile'
          && $values['title'] === 'Jane Doe'
          && $values['field_email'] === 'jane@example.com'
          && $values['field_affiliation'] === [11]
          && $values['field_audience'] === [12]
          && $values['field_tags'] === [13]
          && $values['field_custom_vocab'] === [14]
          && $values['uid'] === 42
          && $values['status'] === 1;
      }))
      ->willReturn($node);

    $data = [
      'display_name' => 'Jane Doe',
      'first_name' => 'Jane',
      'last_name' => 'Doe',
      'honorific_prefix' => '',
      'pronouns' => '',
      'position' => '',
      'subtitle' => '',
      'department' => '',
      'email' => 'jane@example.com',
      'telephone' => '',
      'address' => '',
      'teaser_title' => '',
      'teaser_text' => '',
      'affiliation' => ['Faculty'],
      'audience' => ['Alumni'],
      'tags' => ['tag1'],
      'custom_vocab' => ['Custom A'],
    ];

    $result = $this->profileImport->createProfileNode($data);

    $this->assertSame($node, $result);
  }

  /**
   * CreateProfileNode() logs and re-throws when save() fails.
   *
   * The exception is re-thrown (after logging) rather than swallowed into a
   * NULL return, so processImport() can surface the real failure reason.
   *
   * @covers ::createProfileNode
   */
  public function testCreateProfileNodeSaveFailsLogsAndRethrows() {
    $node = $this->createMock(NodeInterface::class);
    $node->method('save')->willThrowException(new \Exception('Database error'));
    $this->nodeStorage->method('create')->willReturn($node);
    $this->taxonomyResolver->method('resolveTerms')->willReturn([]);

    $this->loggerChannel->expects($this->once())
      ->method('error')
      ->with('Failed to create profile node: @error', ['@error' => 'Database error']);

    $data = array_fill_keys([
      'display_name', 'first_name', 'last_name', 'honorific_prefix', 'pronouns',
      'position', 'subtitle', 'department', 'email', 'telephone', 'address',
      'teaser_title', 'teaser_text',
    ], '') + [
      'affiliation' => [],
      'audience' => [],
      'tags' => [],
      'custom_vocab' => [],
    ];

    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('Database error');
    $this->profileImport->createProfileNode($data);
  }

  /**
   * Builds a partial mock isolating processImport()/previewImport().
   *
   * This isolates their own orchestration logic from prepareProfileData(),
   * findExistingProfile(), and createProfileNode() (each covered directly
   * above).
   */
  protected function partialProfileImport(array $onlyMethods): ProfileImportService {
    $service = $this->getMockBuilder(ProfileImportService::class)
      ->setConstructorArgs([$this->currentUser, $this->taxonomyResolver, $this->entityTypeManager, $this->loggerFactory])
      ->onlyMethods($onlyMethods)
      ->getMock();
    $service->setStringTranslation($this->getStringTranslationStub());
    return $service;
  }

  /**
   * ProcessImport() creates new profiles and skips existing ones.
   *
   * @covers ::processImport
   */
  public function testProcessImportCreatesAndSkipsDuplicates() {
    $service = $this->partialProfileImport(['prepareProfileData', 'findExistingProfile', 'createProfileNode']);
    $service->method('prepareProfileData')->willReturnOnConsecutiveCalls(
      ['display_name' => 'Existing', 'email' => 'existing@example.com'],
      ['display_name' => 'New', 'email' => 'new@example.com'],
    );
    $service->method('findExistingProfile')->willReturnMap([
      ['existing@example.com', $this->createMock(NodeInterface::class)],
      ['new@example.com', NULL],
    ]);
    $service->expects($this->once())->method('createProfileNode')->willReturn($this->createMock(NodeInterface::class));

    $result = $service->processImport([['row' => 1], ['row' => 2]], TRUE);

    $this->assertSame(1, $result['created']);
    $this->assertSame(1, $result['skipped']);
    $this->assertSame([], $result['errors']);
  }

  /**
   * ProcessImport() with skip_duplicates disabled never checks for existing.
   *
   * @covers ::processImport
   */
  public function testProcessImportWithSkipDuplicatesDisabled() {
    $service = $this->partialProfileImport(['prepareProfileData', 'findExistingProfile', 'createProfileNode']);
    $service->method('prepareProfileData')->willReturn(['display_name' => 'Someone', 'email' => 'someone@example.com']);
    $service->expects($this->never())->method('findExistingProfile');
    $service->expects($this->once())->method('createProfileNode')->willReturn($this->createMock(NodeInterface::class));

    $result = $service->processImport([['row' => 1]], FALSE);

    $this->assertSame(1, $result['created']);
    $this->assertSame(0, $result['skipped']);
  }

  /**
   * A failed node creation is reported with its real reason, not dropped.
   *
   * When createProfileNode() throws, processImport() records the row in the
   * returned "errors" list with the actual failure message instead of a
   * generic "could not create profile" line.
   *
   * @covers ::processImport
   */
  public function testProcessImportShouldReportFailedNodeCreation() {
    $service = $this->partialProfileImport(['prepareProfileData', 'findExistingProfile', 'createProfileNode']);
    $service->method('prepareProfileData')->willReturn(['display_name' => 'Someone', 'email' => '']);
    $service->method('createProfileNode')->willThrowException(new \Exception('Field constraint violated'));

    $result = $service->processImport([['_row_number' => 2]], TRUE);

    $this->assertSame(0, $result['created']);
    $this->assertCount(1, $result['errors']);
    // The row is reported with its true CSV line and the real failure reason.
    $this->assertStringContainsString('Row 2', (string) $result['errors'][0]);
    $this->assertStringContainsString('Field constraint violated', (string) $result['errors'][0]);
  }

  /**
   * ProcessImport() reports the true CSV row threaded by the validator.
   *
   * The row number comes from the '_row_number' carried on each row (the true
   * CSV line), not the array offset, so blank rows earlier in the file do not
   * skew the reported line.
   *
   * @covers ::processImport
   */
  public function testProcessImportUsesThreadedRowNumber() {
    $service = $this->partialProfileImport(['prepareProfileData', 'createProfileNode']);
    $service->method('prepareProfileData')->willReturn(['display_name' => 'Someone', 'email' => '']);
    $service->method('createProfileNode')->willThrowException(new \Exception('Save failed'));

    // A single data row carrying its true CSV line (7), e.g. after blank rows.
    $result = $service->processImport([['_row_number' => 7]], TRUE);

    $this->assertCount(1, $result['errors']);
    $this->assertStringContainsString('Row 7', (string) $result['errors'][0]);
  }

  /**
   * ProcessImport() catches a per-row exception and records it with its row.
   *
   * @covers ::processImport
   */
  public function testProcessImportCatchesRowException() {
    $service = $this->partialProfileImport(['prepareProfileData', 'createProfileNode']);
    $service->method('prepareProfileData')->willThrowException(new \Exception('Boom'));

    $result = $service->processImport([['row' => 1]], TRUE);

    $this->assertSame(0, $result['created']);
    $this->assertCount(1, $result['errors']);
    $this->assertStringContainsString('Row 2: Boom', (string) $result['errors'][0]);
  }

  /**
   * PreviewImport() separates duplicates from valid profiles and counts all.
   *
   * @covers ::previewImport
   */
  public function testPreviewImportSeparatesDuplicates() {
    $service = $this->partialProfileImport(['prepareProfileData', 'findExistingProfile']);
    $service->method('prepareProfileData')->willReturnOnConsecutiveCalls(
      ['display_name' => 'Existing', 'email' => 'existing@example.com'],
      ['display_name' => 'New', 'email' => 'new@example.com'],
    );
    $service->method('findExistingProfile')->willReturnMap([
      ['existing@example.com', $this->createMock(NodeInterface::class)],
      ['new@example.com', NULL],
    ]);

    $result = $service->previewImport([['row' => 1], ['row' => 2]], TRUE);

    $this->assertSame(['Existing'], $result['duplicates']);
    $this->assertCount(1, $result['valid_profiles']);
    $this->assertSame('New', $result['valid_profiles'][0]['display_name']);
    $this->assertSame(2, $result['total']);
  }

  /**
   * PreviewImport() treats every row as valid when skip_duplicates is off.
   *
   * @covers ::previewImport
   */
  public function testPreviewImportWithSkipDuplicatesDisabled() {
    $service = $this->partialProfileImport(['prepareProfileData', 'findExistingProfile']);
    $service->method('prepareProfileData')->willReturn(['display_name' => 'Someone', 'email' => 'someone@example.com']);
    $service->expects($this->never())->method('findExistingProfile');

    $result = $service->previewImport([['row' => 1]], FALSE);

    $this->assertSame([], $result['duplicates']);
    $this->assertCount(1, $result['valid_profiles']);
    $this->assertSame(1, $result['total']);
  }

}
