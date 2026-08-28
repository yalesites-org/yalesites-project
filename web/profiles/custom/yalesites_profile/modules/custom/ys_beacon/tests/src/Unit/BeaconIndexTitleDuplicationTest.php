<?php

namespace Drupal\Tests\ys_beacon\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ai_search\Plugin\EmbeddingStrategy\EmbeddingBase;
use Drupal\search_api\Datasource\DatasourceInterface;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Item\FieldInterface;
use League\HTMLToMarkdown\HtmlConverter;

/**
 * Characterizes how the ys_beacon index's `title` field ends up duplicated.
 *
 * YaleSites-Internal#1617 reported the node title appearing twice in every
 * Beacon search chunk (once as a heading, once as a "Title: ..." footer
 * line). The issue's originally-proposed fix -- flip the `title` field's
 * `indexing_option` in ai_search.index.ys_beacon.yml from
 * `contextual_content` to `ignore` -- turns out not to do what it assumes:
 * `EmbeddingBase::groupFieldData()` only populates the heading-source
 * `$title` variable for fields whose `indexing_option` is `main_content` or
 * `contextual_content` (see the `$allowed_options` guard); `ignore` is not
 * in that list, so the field is skipped by the `continue` before the title
 * is ever captured. That removes the title from the chunk entirely -- not
 * "once", as intended, but zero times.
 *
 * This test exercises the real vendor logic (`groupFieldData()` +
 * `prepareChunkText()` from drupal/ai's ai_search submodule) against a
 * fixture built from the actual `ys_beacon` field configuration, so the
 * duplication -- and the proposed fix's actual effect -- are demonstrated
 * against real code rather than asserted from a reading of it.
 *
 * @group ys_beacon
 * @coversDefaultClass \Drupal\ai_search\Plugin\EmbeddingStrategy\EmbeddingBase
 */
class BeaconIndexTitleDuplicationTest extends UnitTestCase {

  private const TITLE = '1403 Charging of Administrative and Clerical Salaries and Certain Other General Administrative Expenses to Federal Funds';

  private const BODY = 'Under federal regulations and sponsor requirements, general administrative expenses include but are not limited to, administrative or clerical salaries.';

  /**
   * Today's config puts the title in a chunk twice.
   *
   * Once as the uppercased `# TITLE` heading, once again as the
   * `Title: ...` contextual-content footer line appended to every chunk.
   *
   * @covers ::groupFieldData
   * @covers ::prepareChunkText
   */
  public function testCurrentConfigDuplicatesTitleInEveryChunk(): void {
    $chunk = $this->buildChunk('contextual_content');

    $this->assertSame(
      2,
      $this->countTitleOccurrences($chunk),
      "Title should appear twice under today's config: once as the heading, once as the contextual-content footer line."
    );
    $this->assertStringContainsString('# ' . strtoupper(self::TITLE), $chunk);
    $this->assertStringContainsString('Title: ' . self::TITLE, $chunk);
  }

  /**
   * The issue's proposed fix does not yield "title once".
   *
   * `ignore` is not in `groupFieldData()`'s `$allowed_options`, so the field
   * is skipped before `$title` is ever assigned. The heading disappears too.
   *
   * @covers ::groupFieldData
   * @covers ::prepareChunkText
   */
  public function testIgnoreOptionRemovesTitleEntirelyInsteadOfDeduplicating(): void {
    $chunk = $this->buildChunk('ignore');

    $this->assertSame(
      0,
      $this->countTitleOccurrences($chunk),
      "The 'title: ignore' config removes the title from the chunk entirely -- it does not leave a single occurrence as the ticket's acceptance criteria assumed."
    );
    $this->assertStringNotContainsString('# ' . strtoupper(self::TITLE), $chunk);
  }

  /**
   * Builds one rendered chunk of the ys_beacon index's actual field layout.
   *
   * Uses a `main_content` field plus the `title` field under the given
   * indexing option, run through the real `groupFieldData()`/
   * `prepareChunkText()` logic from the vendored ai_search plugin -- no
   * network, no database.
   */
  private function buildChunk(string $title_indexing_option): string {
    $embedding = (new \ReflectionClass(EmbeddingBase::class))->newInstanceWithoutConstructor();

    $converter = new HtmlConverter();
    $converter->getConfig()->setOption('strip_tags', TRUE);
    $this->setProtectedProperty($embedding, 'converter', $converter);
    $this->setProtectedProperty($embedding, 'entityTypeManager', $this->buildEntityTypeManager());
    $this->setProtectedProperty($embedding, 'configFactory', $this->buildConfigFactory($title_indexing_option));

    $index = $this->createMock(IndexInterface::class);
    $index->method('id')->willReturn('ys_beacon');

    $fields = [
      $this->buildField('rendered_item', self::BODY, 'Rendered HTML Output', 'main_content'),
      $this->buildField('title', self::TITLE, 'Title', $title_indexing_option, isLabelField: TRUE),
    ];

    [$title, $contextual_content, $main_content] = $this->invoke($embedding, 'groupFieldData', [$fields, $index]);

    return $this->invoke($embedding, 'prepareChunkText', [$title, $main_content, $contextual_content]);
  }

  /**
   * Counts case-insensitive occurrences of the title text in a chunk.
   */
  private function countTitleOccurrences(string $chunk): int {
    return substr_count(strtoupper($chunk), strtoupper(self::TITLE));
  }

  /**
   * Stubs the ys_beacon index config with the given title indexing option.
   */
  private function buildConfigFactory(string $title_indexing_option): ConfigFactoryInterface {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('getRawData')->willReturn([
      'indexing_options' => [
        'rendered_item' => ['indexing_option' => 'main_content'],
        'title' => ['indexing_option' => $title_indexing_option],
      ],
    ]);

    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('get')->with('ai_search.index.ys_beacon')->willReturn($config);

    return $config_factory;
  }

  /**
   * Stubs the node entity type's label key as `title`.
   */
  private function buildEntityTypeManager(): EntityTypeManagerInterface {
    $entity_type = $this->createMock(EntityTypeInterface::class);
    $entity_type->method('getKey')->with('label')->willReturn('title');

    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getDefinition')->with('node')->willReturn($entity_type);

    return $manager;
  }

  /**
   * Builds a Search API field mock with the given identifier and value.
   */
  private function buildField(string $id, string $value, string $label, string $indexing_option, bool $isLabelField = FALSE): FieldInterface {
    $field = $this->createMock(FieldInterface::class);
    $field->method('getFieldIdentifier')->willReturn($id);
    $field->method('getLabel')->willReturn($label);
    $field->method('getValues')->willReturn([$value]);
    $field->method('getType')->willReturn('string');

    $definition = $this->createMock(DataDefinitionInterface::class);
    $definition->method('getSettings')->willReturn([]);
    $definition->method('getDataType')->willReturn('string');
    $field->method('getDataDefinition')->willReturn($definition);

    if ($isLabelField) {
      $datasource = $this->createMock(DatasourceInterface::class);
      $datasource->method('getEntityTypeId')->willReturn('node');
      $field->method('getDatasource')->willReturn($datasource);
    }
    else {
      $field->method('getDatasource')->willReturn(NULL);
    }

    return $field;
  }

  /**
   * Sets a protected property via reflection.
   *
   * The class's constructor is bypassed via newInstanceWithoutConstructor,
   * so nothing is populated.
   */
  private function setProtectedProperty(object $object, string $property, mixed $value): void {
    $ref = new \ReflectionProperty($object, $property);
    $ref->setAccessible(TRUE);
    $ref->setValue($object, $value);
  }

  /**
   * Invokes a protected/private method via reflection.
   */
  private function invoke(object $object, string $method, array $args = []) {
    $ref = new \ReflectionMethod($object, $method);
    $ref->setAccessible(TRUE);
    return $ref->invokeArgs($object, $args);
  }

}
