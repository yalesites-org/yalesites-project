<?php

namespace Drupal\Tests\ys_views_content_resources\Unit;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Entity\EntityDisplayRepository;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_views_content_resources\ViewsContentResourcesManager;

/**
 * Unit tests for the resource view search and category-filter logic.
 *
 * Covers the two YISP-127 changes: searching affiliated and non-affiliated
 * author names, and dropping excluded terms from the category exposed filter.
 *
 * @coversDefaultClass \Drupal\ys_views_content_resources\ViewsContentResourcesManager
 *
 * @group yalesites
 */
class ViewsContentResourcesManagerTest extends UnitTestCase {

  /**
   * The manager under test.
   *
   * @var \Drupal\ys_views_content_resources\ViewsContentResourcesManager
   */
  protected $manager;

  /**
   * {@inheritdoc}
   */
  public function setUp(): void {
    parent::setUp();

    // The constructor only needs a storage handle for taxonomy terms; the
    // methods under test are pure and do not touch the injected services.
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')
      ->willReturn($this->createMock(EntityStorageInterface::class));

    $this->manager = new ViewsContentResourcesManager(
      $entityTypeManager,
      $this->createMock(EntityDisplayRepository::class),
      $this->createMock(RouteMatchInterface::class),
      $this->createMock(CacheTagsInvalidatorInterface::class),
    );
  }

  /**
   * Author search must reach both the Profile title and the double_field names.
   *
   * @covers ::authorSearchHandlerDefinitions
   */
  public function testAuthorSearchHandlerDefinitions(): void {
    $definitions = $this->manager->authorSearchHandlerDefinitions();

    // Affiliated authors: a relationship to the referenced Profile node.
    $this->assertArrayHasKey('field_authors', $definitions['relationships']);
    $relationship = $definitions['relationships']['field_authors'];
    $this->assertSame('node__field_authors', $relationship['table']);
    $this->assertSame('standard', $relationship['plugin_id']);

    // Three excluded fields feed the combine filter and never render.
    $expected_fields = [
      'author_profile_title',
      'nonaffiliated_author_first',
      'nonaffiliated_author_second',
    ];
    $this->assertSame($expected_fields, array_keys($definitions['fields']));
    foreach ($definitions['fields'] as $field) {
      $this->assertTrue($field['exclude'], 'Author search fields are excluded from display.');
    }

    // Affiliated name comes from the Profile node title via the relationship.
    $title = $definitions['fields']['author_profile_title'];
    $this->assertSame('node_field_data', $title['table']);
    $this->assertSame('title', $title['field']);
    $this->assertSame('field_authors', $title['relationship']);

    // Both double_field name columns are searched (first and last name).
    $this->assertSame(
      'field_nonaffiliated_authors_first',
      $definitions['fields']['nonaffiliated_author_first']['field']
    );
    $this->assertSame(
      'field_nonaffiliated_authors_second',
      $definitions['fields']['nonaffiliated_author_second']['field']
    );
  }

  /**
   * The 'authors' pseudo-field expands to the author combine fields.
   *
   * @covers ::expandSearchFields
   */
  public function testExpandSearchFieldsWithAuthors(): void {
    // Existing options (including the in-use Journal/Publication Name field)
    // must be preserved alongside the injected author fields.
    $result = $this->manager->expandSearchFields([
      'title',
      'field_journal_publication_name',
      'authors',
    ]);

    $this->assertTrue($result['authors'], 'Author handlers are flagged for injection.');
    $this->assertArrayNotHasKey('authors', $result['fields'], 'Pseudo-field is not passed to combine.');
    $this->assertSame(
      [
        'title' => 'title',
        'field_journal_publication_name' => 'field_journal_publication_name',
        'author_profile_title' => 'author_profile_title',
        'nonaffiliated_author_first' => 'nonaffiliated_author_first',
        'nonaffiliated_author_second' => 'nonaffiliated_author_second',
      ],
      $result['fields']
    );
  }

  /**
   * Without the 'authors' option, fields pass through and no handlers inject.
   *
   * @covers ::expandSearchFields
   */
  public function testExpandSearchFieldsWithoutAuthors(): void {
    $result = $this->manager->expandSearchFields(['title', 'field_teaser_text']);

    $this->assertFalse($result['authors']);
    $this->assertSame(
      ['title' => 'title', 'field_teaser_text' => 'field_teaser_text'],
      $result['fields']
    );
  }

  /**
   * Excluded terms drop out of the exposed category options.
   *
   * @covers ::reduceCategoryTermsForExposure
   */
  public function testReduceCategoryTermsForExposure(): void {
    $available = [241 => 241, 242 => 242, 243 => 243];

    // Excluding 242 leaves the other two, keyed by term id.
    $this->assertSame(
      [241 => 241, 243 => 243],
      $this->manager->reduceCategoryTermsForExposure($available, [242])
    );

    // No exclusions returns the full set unchanged.
    $this->assertSame(
      $available,
      $this->manager->reduceCategoryTermsForExposure($available, [])
    );
  }

}
