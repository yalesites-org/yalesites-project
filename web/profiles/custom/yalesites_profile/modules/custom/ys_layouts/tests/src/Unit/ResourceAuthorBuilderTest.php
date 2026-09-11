<?php

namespace Drupal\Tests\ys_layouts\Unit;

use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\Field\FieldItemList;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Url;
use Drupal\Tests\UnitTestCase;
use Drupal\node\NodeInterface;
use Drupal\ys_layouts\Service\ResourceAuthorBuilder;

/**
 * Tests the ResourceAuthorBuilder service.
 *
 * @coversDefaultClass \Drupal\ys_layouts\Service\ResourceAuthorBuilder
 *
 * @group yalesites
 * @group ys_layouts
 */
class ResourceAuthorBuilderTest extends UnitTestCase {

  /**
   * The ResourceAuthorBuilder service under test.
   *
   * @var \Drupal\ys_layouts\Service\ResourceAuthorBuilder
   */
  protected $builder;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->builder = new ResourceAuthorBuilder(new \Collator('en_US'));
  }

  /**
   * Builds a mock profile node referenced by field_authors.
   *
   * @param int $id
   *   The profile node ID.
   * @param string $displayName
   *   The profile's title (auto-generated Display Name).
   * @param string $first
   *   The first name field value.
   * @param string $last
   *   The last name field value.
   * @param string $url
   *   The profile's canonical URL string.
   * @param bool $accessible
   *   Whether ->access('view') should return TRUE.
   *
   * @return \Drupal\node\NodeInterface|\PHPUnit\Framework\MockObject\MockObject
   *   The mock profile node.
   */
  protected function createProfile($id, $displayName, $first, $last, $url = '/profiles/' . 0, $accessible = TRUE) {
    $profile = $this->createMock(NodeInterface::class);
    $profile->method('access')->with('view')->willReturn($accessible);
    $profile->method('label')->willReturn($displayName);
    $profile->method('hasField')->willReturnMap([
      ['field_first_name', TRUE],
      ['field_last_name', TRUE],
    ]);

    $firstField = $this->createMock(FieldItemListInterface::class);
    $firstField->method('getString')->willReturn($first);
    $lastField = $this->createMock(FieldItemListInterface::class);
    $lastField->method('getString')->willReturn($last);
    $profile->method('get')->willReturnMap([
      ['field_first_name', $firstField],
      ['field_last_name', $lastField],
    ]);

    $profileUrl = $this->createMock(Url::class);
    $profileUrl->method('toString')->willReturn($url);
    $profile->method('toUrl')->willReturn($profileUrl);
    $profile->method('getCacheTags')->willReturn(["node:$id"]);

    return $profile;
  }

  /**
   * A node with no author fields returns an empty list.
   *
   * @covers ::build
   */
  public function testBuildWithNoAuthorFieldsReturnsEmpty(): void {
    $node = $this->createMock(NodeInterface::class);
    $node->method('hasField')->willReturn(FALSE);

    $result = $this->builder->build($node);

    $this->assertSame([], $result);
  }

  /**
   * Affiliated authors render using the profile's Display Name and URL.
   *
   * @covers ::build
   */
  public function testBuildWithAffiliatedAuthorUsesProfileDisplayName(): void {
    $profile = $this->createProfile(1, 'Dr. Jane Smith', 'Jane', 'Smith', '/profiles/jane');

    $authorsField = $this->createMock(EntityReferenceFieldItemListInterface::class);
    $authorsField->method('isEmpty')->willReturn(FALSE);
    $authorsField->method('referencedEntities')->willReturn([$profile]);

    $node = $this->createMock(NodeInterface::class);
    $node->method('hasField')->willReturnMap([
      ['field_authors', TRUE],
      ['field_nonaffiliated_authors', FALSE],
    ]);
    $node->method('get')->with('field_authors')->willReturn($authorsField);

    $cacheTags = [];
    $result = $this->builder->build($node, $cacheTags);

    $this->assertSame([
      ['label' => 'Dr. Jane Smith', 'url' => '/profiles/jane'],
    ], $result);
    $this->assertSame(['node:1'], $cacheTags);
  }

  /**
   * Authors the current user cannot view are excluded from the list.
   *
   * @covers ::build
   */
  public function testBuildExcludesInaccessibleProfile(): void {
    $profile = $this->createProfile(2, 'Hidden Author', 'Hidden', 'Author', '/profiles/hidden', FALSE);

    $authorsField = $this->createMock(EntityReferenceFieldItemListInterface::class);
    $authorsField->method('isEmpty')->willReturn(FALSE);
    $authorsField->method('referencedEntities')->willReturn([$profile]);

    $node = $this->createMock(NodeInterface::class);
    $node->method('hasField')->willReturnMap([
      ['field_authors', TRUE],
      ['field_nonaffiliated_authors', FALSE],
    ]);
    $node->method('get')->willReturn($authorsField);

    $result = $this->builder->build($node);

    $this->assertSame([], $result);
  }

  /**
   * Non-affiliated Double Field rows render as "First Last" with no URL.
   *
   * @covers ::build
   */
  public function testBuildWithNonaffiliatedAuthorHasNoUrl(): void {
    $row = (object) ['first' => 'Sam', 'second' => 'Rivera'];
    $nonaffiliatedField = $this->createMock(FieldItemList::class);
    $nonaffiliatedField->method('isEmpty')->willReturn(FALSE);
    $nonaffiliatedField->method('getIterator')->willReturn(new \ArrayIterator([$row]));

    $node = $this->createMock(NodeInterface::class);
    $node->method('hasField')->willReturnMap([
      ['field_authors', FALSE],
      ['field_nonaffiliated_authors', TRUE],
    ]);
    $node->method('get')->with('field_nonaffiliated_authors')->willReturn($nonaffiliatedField);

    $result = $this->builder->build($node);

    $this->assertSame([
      ['label' => 'Sam Rivera', 'url' => NULL],
    ], $result);
  }

  /**
   * A non-affiliated row with both name columns blank is skipped.
   *
   * @covers ::build
   */
  public function testBuildSkipsBlankNonaffiliatedRow(): void {
    $row = (object) ['first' => '  ', 'second' => ''];
    $nonaffiliatedField = $this->createMock(FieldItemList::class);
    $nonaffiliatedField->method('isEmpty')->willReturn(FALSE);
    $nonaffiliatedField->method('getIterator')->willReturn(new \ArrayIterator([$row]));

    $node = $this->createMock(NodeInterface::class);
    $node->method('hasField')->willReturnMap([
      ['field_authors', FALSE],
      ['field_nonaffiliated_authors', TRUE],
    ]);
    $node->method('get')->willReturn($nonaffiliatedField);

    $result = $this->builder->build($node);

    $this->assertSame([], $result);
  }

  /**
   * Merged authors sort last-then-first, with particles preserved literally.
   *
   * Per project requirement, "van der Berg" sorts under V and "de la Cruz"
   * sorts under D rather than having the particle stripped.
   *
   * @covers ::build
   */
  public function testBuildSortsMergedAuthorsLastThenFirst(): void {
    $affiliated = $this->createProfile(3, 'Anna Adams', 'Anna', 'Adams', '/profiles/anna');

    $authorsField = $this->createMock(EntityReferenceFieldItemListInterface::class);
    $authorsField->method('isEmpty')->willReturn(FALSE);
    $authorsField->method('referencedEntities')->willReturn([$affiliated]);

    $vanDerBerg = (object) ['first' => 'Piet', 'second' => 'van der Berg'];
    $delaCruz = (object) ['first' => 'Maria', 'second' => 'de la Cruz'];
    $nonaffiliatedField = $this->createMock(FieldItemList::class);
    $nonaffiliatedField->method('isEmpty')->willReturn(FALSE);
    $nonaffiliatedField->method('getIterator')->willReturn(new \ArrayIterator([$vanDerBerg, $delaCruz]));

    $node = $this->createMock(NodeInterface::class);
    $node->method('hasField')->willReturn(TRUE);
    $node->method('get')->willReturnMap([
      ['field_authors', $authorsField],
      ['field_nonaffiliated_authors', $nonaffiliatedField],
    ]);

    $result = $this->builder->build($node);

    $this->assertSame([
      ['label' => 'Anna Adams', 'url' => '/profiles/anna'],
      ['label' => 'Maria de la Cruz', 'url' => NULL],
      ['label' => 'Piet van der Berg', 'url' => NULL],
    ], $result);
  }

}
