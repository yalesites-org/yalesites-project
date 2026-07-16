<?php

namespace Drupal\Tests\ys_core\Unit;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\node\NodeTypeInterface;
use Drupal\taxonomy\VocabularyInterface;
use Drupal\ys_core\TaxonomyVocabularyManager;

/**
 * Tests TaxonomyVocabularyManager's grouping and content-type lookups.
 *
 * @coversDefaultClass \Drupal\ys_core\TaxonomyVocabularyManager
 *
 * @group ys_core
 * @group yalesites
 */
class TaxonomyVocabularyManagerTest extends UnitTestCase {

  /**
   * The entity type manager mock.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $entityTypeManager;

  /**
   * The entity field manager mock.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $entityFieldManager;

  /**
   * The manager under test.
   *
   * @var \Drupal\ys_core\TaxonomyVocabularyManager
   */
  protected $manager;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->entityFieldManager = $this->createMock(EntityFieldManagerInterface::class);
    $this->manager = new TaxonomyVocabularyManager($this->entityTypeManager, $this->entityFieldManager);
  }

  /**
   * Creates a mock vocabulary with the given machine name.
   */
  protected function mockVocabulary(string $id): VocabularyInterface {
    $vocabulary = $this->createMock(VocabularyInterface::class);
    $vocabulary->method('id')->willReturn($id);
    return $vocabulary;
  }

  /**
   * Creates a mock node type with the given machine name and label.
   */
  protected function mockNodeType(string $id, string $label): NodeTypeInterface {
    $nodeType = $this->createMock(NodeTypeInterface::class);
    $nodeType->method('id')->willReturn($id);
    $nodeType->method('label')->willReturn($label);
    return $nodeType;
  }

  /**
   * Creates a mock entity_reference field targeting taxonomy_term.
   */
  protected function mockEntityReferenceField(array $targetBundles): FieldDefinitionInterface {
    $field = $this->createMock(FieldDefinitionInterface::class);
    $field->method('getType')->willReturn('entity_reference');
    $field->method('getSetting')->willReturnMap([
      ['target_type', 'taxonomy_term'],
      ['handler_settings', ['target_bundles' => $targetBundles]],
    ]);
    return $field;
  }

  /**
   * Creates a mock dcn_field referencing the given vocabulary.
   */
  protected function mockDcnField(string $vocabularyId): FieldDefinitionInterface {
    $field = $this->createMock(FieldDefinitionInterface::class);
    $field->method('getType')->willReturn('dcn_field');
    $field->method('getSetting')->willReturnMap([
      ['dcn_type_vocabulary', $vocabularyId],
    ]);
    return $field;
  }

  /**
   * @covers ::getYaleSitesVocabularyIds
   */
  public function testGetYaleSitesVocabularyIds(): void {
    $this->assertSame([
      'event_category',
      'profile_affiliation',
      'affiliation',
      'audience',
      'custom_vocab',
      'post_category',
      'page_category',
      'resource_category',
      'tags',
      'academic_years',
      'geographic_areas',
      'areas_of_study',
      'discipline',
      'dcn_types',
    ], $this->manager->getYaleSitesVocabularyIds());
  }

  /**
   * @covers ::groupVocabularies
   */
  public function testGroupVocabulariesSplitsYaleSitesFromLocalist(): void {
    $tags = $this->mockVocabulary('tags');
    $eventCategory = $this->mockVocabulary('event_category');
    $localistOnly = $this->mockVocabulary('localist_event_types');

    $grouped = $this->manager->groupVocabularies([$tags, $eventCategory, $localistOnly]);

    $this->assertSame(['tags', 'event_category'], array_keys($grouped['yalesites']));
    $this->assertSame(['localist_event_types'], array_keys($grouped['localist']));
  }

  /**
   * @covers ::getAssociatedContentTypes
   */
  public function testGetAssociatedContentTypesMatchesEntityReferenceField(): void {
    $vocabulary = $this->mockVocabulary('event_category');
    $pageType = $this->mockNodeType('page', 'Basic Page');

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('loadMultiple')->willReturn(['page' => $pageType]);
    $this->entityTypeManager->method('getStorage')->with('node_type')->willReturn($storage);

    $this->entityFieldManager->method('getFieldDefinitions')
      ->with('node', 'page')
      ->willReturn([
        'field_category' => $this->mockEntityReferenceField(['event_category' => 'event_category']),
      ]);

    $this->assertSame(['Basic Page'], $this->manager->getAssociatedContentTypes($vocabulary));
  }

  /**
   * @covers ::getAssociatedContentTypes
   */
  public function testGetAssociatedContentTypesMatchesDcnField(): void {
    $vocabulary = $this->mockVocabulary('dcn_types');
    $eventType = $this->mockNodeType('event', 'Event');

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('loadMultiple')->willReturn(['event' => $eventType]);
    $this->entityTypeManager->method('getStorage')->with('node_type')->willReturn($storage);

    $this->entityFieldManager->method('getFieldDefinitions')
      ->with('node', 'event')
      ->willReturn([
        'field_dcn' => $this->mockDcnField('dcn_types'),
      ]);

    $this->assertSame(['Event'], $this->manager->getAssociatedContentTypes($vocabulary));
  }

  /**
   * @covers ::getAssociatedContentTypes
   */
  public function testGetAssociatedContentTypesReturnsEmptyWhenNoFieldMatches(): void {
    $vocabulary = $this->mockVocabulary('tags');
    $articleType = $this->mockNodeType('article', 'Article');

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('loadMultiple')->willReturn(['article' => $articleType]);
    $this->entityTypeManager->method('getStorage')->with('node_type')->willReturn($storage);

    $this->entityFieldManager->method('getFieldDefinitions')
      ->with('node', 'article')
      ->willReturn([
        'field_unrelated' => $this->mockEntityReferenceField(['other_vocab' => 'other_vocab']),
      ]);

    $this->assertSame([], $this->manager->getAssociatedContentTypes($vocabulary));
  }

}
