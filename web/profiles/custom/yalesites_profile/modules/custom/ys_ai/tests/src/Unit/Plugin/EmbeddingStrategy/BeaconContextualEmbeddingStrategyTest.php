<?php

namespace Drupal\Tests\ys_ai\Unit\Plugin\EmbeddingStrategy;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Item\FieldInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_ai\Plugin\EmbeddingStrategy\BeaconContextualEmbeddingStrategy;
use League\HTMLToMarkdown\HtmlConverter;

/**
 * @coversDefaultClass \Drupal\ys_ai\Plugin\EmbeddingStrategy\BeaconContextualEmbeddingStrategy
 *
 * @group yalesites
 */
class BeaconContextualEmbeddingStrategyTest extends UnitTestCase {

  /**
   * The Search API index id whose indexing_options the strategy reads.
   */
  const INDEX_ID = 'beacon_index';

  /**
   * Date attributes become ISO 8601 even when the item field is downgraded.
   *
   * The ai_search backend downgrades every field to its fallback type before
   * indexing, so the item fields handed to buildBaseMetadata() report type
   * "string" (with the raw numeric timestamp), while the index's configured
   * type stays "date". The strategy must key off the configured type.
   *
   * @covers ::buildBaseMetadata
   */
  public function testDateAttributesBecomeIso8601(): void {
    // Item fields as the backend hands them over: downgraded to "string".
    $item_fields = [
      'created' => $this->field('created', 'string', ['1700000000']),
      'changed' => $this->field('changed', 'string', ['1700086400']),
      'title_1' => $this->field('title_1', 'string', ['Hello']),
    ];

    // The index's configured field types: created/changed are dates.
    $index = $this->index([
      'created' => 'date',
      'changed' => 'date',
      'title_1' => 'string',
    ]);

    $metadata = $this->strategy(array_keys($item_fields))
      ->buildBaseMetadata(array_values($item_fields), $index);

    $this->assertSame('2023-11-14T22:13:20Z', $metadata['created']);
    $this->assertSame('2023-11-15T22:13:20Z', $metadata['changed']);
    // A non-date attribute is left untouched.
    $this->assertSame('Hello', $metadata['title_1']);
  }

  /**
   * Builds the plugin under test with the indexing_options it should see.
   *
   * @param string[] $attribute_fields
   *   Field identifiers to mark as filterable attributes.
   *
   * @return \Drupal\ys_ai\Plugin\EmbeddingStrategy\BeaconContextualEmbeddingStrategy
   *   The configured strategy instance.
   */
  protected function strategy(array $attribute_fields): BeaconContextualEmbeddingStrategy {
    $indexing_options = [];
    foreach (['created', 'changed', 'title_1'] as $identifier) {
      $indexing_options[$identifier] = [
        'indexing_option' => in_array($identifier, $attribute_fields, TRUE) ? 'attributes' : 'main_content',
      ];
    }

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('getRawData')->willReturn(['indexing_options' => $indexing_options]);
    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('get')
      ->with('ai_search.index.' . self::INDEX_ID)
      ->willReturn($config);

    // The plugin's constructor is final and pulls in several final/heavy
    // services that buildBaseMetadata() does not use. Instantiate without the
    // constructor and inject only what the method touches: the config factory
    // (to read indexing_options) and a real HtmlConverter (used by the parent
    // to render single-value string attributes).
    $strategy = (new \ReflectionClass(BeaconContextualEmbeddingStrategy::class))
      ->newInstanceWithoutConstructor();
    $this->setProperty($strategy, 'configFactory', $config_factory);
    $this->setProperty($strategy, 'converter', new HtmlConverter());
    return $strategy;
  }

  /**
   * Sets a protected/inherited property on an object via reflection.
   *
   * @param object $object
   *   The object to mutate.
   * @param string $name
   *   The property name (may be declared on a parent class).
   * @param mixed $value
   *   The value to set.
   */
  protected function setProperty(object $object, string $name, mixed $value): void {
    $property = (new \ReflectionClass($object))->getProperty($name);
    $property->setAccessible(TRUE);
    $property->setValue($object, $value);
  }

  /**
   * Builds a mock index that reports its id and configured field types.
   *
   * @param array $configured_types
   *   Field identifier => configured Search API type.
   *
   * @return \Drupal\search_api\IndexInterface
   *   The mock index.
   */
  protected function index(array $configured_types): IndexInterface {
    $configured = [];
    foreach ($configured_types as $identifier => $type) {
      $field = $this->createMock(FieldInterface::class);
      $field->method('getType')->willReturn($type);
      $configured[$identifier] = $field;
    }

    $index = $this->createMock(IndexInterface::class);
    $index->method('id')->willReturn(self::INDEX_ID);
    $index->method('getFields')->willReturn($configured);
    return $index;
  }

  /**
   * Builds a mock Search API field with the given identifier, type and values.
   *
   * @param string $identifier
   *   The field identifier.
   * @param string $type
   *   The Search API data type (e.g. 'string').
   * @param array $values
   *   The field values.
   *
   * @return \Drupal\search_api\Item\FieldInterface
   *   The mock field.
   */
  protected function field(string $identifier, string $type, array $values): FieldInterface {
    $definition = $this->createMock(DataDefinitionInterface::class);
    $definition->method('getSettings')->willReturn([]);
    $definition->method('getDataType')->willReturn('field_item:' . $type);

    $field = $this->createMock(FieldInterface::class);
    $field->method('getFieldIdentifier')->willReturn($identifier);
    $field->method('getType')->willReturn($type);
    $field->method('getValues')->willReturn($values);
    $field->method('getDataDefinition')->willReturn($definition);
    return $field;
  }

}
