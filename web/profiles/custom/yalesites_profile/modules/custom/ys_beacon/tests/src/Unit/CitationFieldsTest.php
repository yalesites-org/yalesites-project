<?php

namespace Drupal\Tests\ys_beacon\Unit;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\TypedData\ComplexDataInterface;
use Drupal\search_api\Item\FieldInterface;
use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api\Utility\FieldsHelperInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_beacon\Plugin\search_api\processor\CitationFields;
use Drupal\ys_beacon\Service\EntityCitationResolver;

/**
 * Tests the Beacon citation-fields search processor.
 *
 * @group ys_beacon
 * @coversDefaultClass \Drupal\ys_beacon\Plugin\search_api\processor\CitationFields
 */
class CitationFieldsTest extends UnitTestCase {

  /**
   * Writes the resolved title and URL onto the item's citation fields.
   *
   * @covers ::addFieldValues
   */
  public function testWritesTitleAndUrl(): void {
    $entity = $this->createMock(ContentEntityInterface::class);

    $title_field = $this->createMock(FieldInterface::class);
    $title_field->expects($this->once())->method('addValue')->with('Housing Guide');
    $url_field = $this->createMock(FieldInterface::class);
    $url_field->expects($this->once())->method('addValue')->with('https://owner.example.edu/housing');

    $resolver = $this->createMock(EntityCitationResolver::class);
    $resolver->method('title')->with($entity)->willReturn('Housing Guide');
    $resolver->method('url')->with($entity)->willReturn('https://owner.example.edu/housing');

    $item = $this->makeItem($entity);
    $this->makeProcessor($resolver, $title_field, $url_field)->addFieldValues($item);
  }

  /**
   * When no URL can be derived, only the title is written.
   *
   * @covers ::addFieldValues
   */
  public function testSkipsUrlWhenNull(): void {
    $entity = $this->createMock(ContentEntityInterface::class);

    $title_field = $this->createMock(FieldInterface::class);
    $title_field->expects($this->once())->method('addValue')->with('Housing Guide');
    $url_field = $this->createMock(FieldInterface::class);
    $url_field->expects($this->never())->method('addValue');

    $resolver = $this->createMock(EntityCitationResolver::class);
    $resolver->method('title')->with($entity)->willReturn('Housing Guide');
    $resolver->method('url')->with($entity)->willReturn(NULL);

    $item = $this->makeItem($entity);
    $this->makeProcessor($resolver, $title_field, $url_field)->addFieldValues($item);
  }

  /**
   * A non-content object is ignored without touching the fields.
   *
   * @covers ::addFieldValues
   */
  public function testIgnoresNonContentEntity(): void {
    $original = $this->createMock(ComplexDataInterface::class);
    $original->method('getValue')->willReturn(NULL);

    $item = $this->createMock(ItemInterface::class);
    $item->method('getOriginalObject')->willReturn($original);
    $item->expects($this->never())->method('getFields');

    $resolver = $this->createMock(EntityCitationResolver::class);
    $resolver->expects($this->never())->method('title');

    $this->makeProcessor($resolver, $this->createMock(FieldInterface::class), $this->createMock(FieldInterface::class))
      ->addFieldValues($item);
  }

  /**
   * Builds a result item whose original object resolves to the given entity.
   */
  private function makeItem(ContentEntityInterface $entity): ItemInterface {
    $original = $this->createMock(ComplexDataInterface::class);
    $original->method('getValue')->willReturn($entity);

    $item = $this->createMock(ItemInterface::class);
    $item->method('getOriginalObject')->willReturn($original);
    $item->method('getFields')->with(FALSE)->willReturn([]);
    return $item;
  }

  /**
   * Builds the processor with the resolver and a fields helper routing paths.
   */
  private function makeProcessor(EntityCitationResolver $resolver, FieldInterface $title_field, FieldInterface $url_field): CitationFields {
    $fields_helper = $this->createMock(FieldsHelperInterface::class);
    $fields_helper->method('filterForPropertyPath')->willReturnCallback(
      fn (array $fields, $datasource_id, string $property_path): array => match ($property_path) {
        'ys_beacon_citation_title' => [$title_field],
        'ys_beacon_citation_url' => [$url_field],
        default => [],
      },
    );

    $processor = new CitationFields([], 'ys_beacon_citation_fields', []);
    $processor->setFieldsHelper($fields_helper);
    $resolver_property = new \ReflectionProperty($processor, 'citationResolver');
    $resolver_property->setAccessible(TRUE);
    $resolver_property->setValue($processor, $resolver);
    return $processor;
  }

}
