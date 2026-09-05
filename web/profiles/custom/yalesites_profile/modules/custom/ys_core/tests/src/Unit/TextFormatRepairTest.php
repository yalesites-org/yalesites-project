<?php

namespace Drupal\Tests\ys_core\Unit;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_core\TextFormatRepair;

/**
 * Tests the out-of-contract text format decision logic.
 *
 * Covers the pure decision half of the service that repairs stored text
 * formats which a field's allowed_formats setting does not permit. See
 * yalesites-org/YaleSites-Internal#1646: core's
 * \Drupal\filter\Element\TextFormat::processFormat() intersects the user's
 * usable formats with the field's #allowed_formats, so a stored format outside
 * that list disables the widget entirely for any user without 'administer
 * filters'.
 *
 * @coversDefaultClass \Drupal\ys_core\TextFormatRepair
 *
 * @group ys_core
 * @group yalesites
 */
class TextFormatRepairTest extends UnitTestCase {

  /**
   * The entity field manager mock.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $entityFieldManager;

  /**
   * The service under test.
   *
   * @var \Drupal\ys_core\TextFormatRepair
   */
  protected $repair;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->entityFieldManager = $this->createMock(EntityFieldManagerInterface::class);
    $this->repair = new TextFormatRepair(
      $this->createMock(EntityTypeManagerInterface::class),
      $this->entityFieldManager,
      $this->createMock(Connection::class),
      $this->createMock(CacheTagsInvalidatorInterface::class)
    );
  }

  /**
   * Builds a field definition returning the given allowed_formats setting.
   */
  protected function fieldDefinition($allowed_formats): FieldDefinitionInterface {
    $definition = $this->createMock(FieldDefinitionInterface::class);
    $definition->method('getSetting')
      ->with('allowed_formats')
      ->willReturn($allowed_formats);
    return $definition;
  }

  /**
   * Wires the entity field manager to return one field definition.
   */
  protected function withField(string $field_name, $allowed_formats): void {
    $this->entityFieldManager->method('getFieldDefinitions')
      ->with('node', 'resource')
      ->willReturn([$field_name => $this->fieldDefinition($allowed_formats)]);
  }

  /**
   * A restricted field reports exactly the formats its config permits.
   *
   * @covers ::getAllowedFormats
   */
  public function testGetAllowedFormatsReturnsConfiguredList(): void {
    $this->withField('field_abstract', ['restricted_html']);
    $this->assertSame(
      ['restricted_html'],
      $this->repair->getAllowedFormats('node', 'resource', 'field_abstract')
    );
  }

  /**
   * A field with no allowed_formats setting is unrestricted, not empty-listed.
   *
   * @covers ::getAllowedFormats
   */
  public function testGetAllowedFormatsReturnsEmptyWhenUnset(): void {
    $this->withField('field_abstract', NULL);
    $this->assertSame(
      [],
      $this->repair->getAllowedFormats('node', 'resource', 'field_abstract')
    );
  }

  /**
   * A field that does not exist on the bundle yields no restriction.
   *
   * @covers ::getAllowedFormats
   */
  public function testGetAllowedFormatsReturnsEmptyForMissingField(): void {
    $this->withField('field_abstract', ['restricted_html']);
    $this->assertSame(
      [],
      $this->repair->getAllowedFormats('node', 'resource', 'field_nonexistent')
    );
  }

  /**
   * A stored format the field permits is left alone.
   *
   * @covers ::getRepairFormat
   */
  public function testPermittedFormatNeedsNoRepair(): void {
    $this->assertNull(
      $this->repair->getRepairFormat('restricted_html', ['restricted_html'])
    );
  }

  /**
   * A stored format outside the contract repairs to the first allowed format.
   *
   * This is the #1646 case: the vendor import wrote basic_html into a field
   * whose only permitted format has always been restricted_html.
   *
   * @covers ::getRepairFormat
   */
  public function testOutOfContractFormatRepairsToFirstAllowed(): void {
    $this->assertSame(
      'restricted_html',
      $this->repair->getRepairFormat('basic_html', ['restricted_html'])
    );
  }

  /**
   * The fallback format is not implicitly permitted, so it is repaired too.
   *
   * Core deliberately does not add the fallback format to #allowed_formats
   * (see the comment in TextFormat::processFormat()), so plain_text stored on
   * a restricted field locks the widget just as basic_html does.
   *
   * @covers ::getRepairFormat
   */
  public function testFallbackFormatIsAlsoOutOfContract(): void {
    $this->assertSame(
      'restricted_html',
      $this->repair->getRepairFormat('plain_text', ['restricted_html'])
    );
  }

  /**
   * A format that is permitted but not first is still left alone.
   *
   * Guards against a naive "rewrite everything to allowed[0]" implementation,
   * which is what LayoutUpdater::updateTextFormats() does for block content
   * and what must NOT happen here.
   *
   * @covers ::getRepairFormat
   */
  public function testSecondAllowedFormatIsNotRewrittenToFirst(): void {
    $this->assertNull(
      $this->repair->getRepairFormat('heading_html', ['restricted_html', 'heading_html'])
    );
  }

  /**
   * An unrestricted field never repairs, whatever the stored format.
   *
   * @covers ::getRepairFormat
   */
  public function testUnrestrictedFieldNeverRepairs(): void {
    $this->assertNull($this->repair->getRepairFormat('basic_html', []));
  }

  /**
   * An empty stored format is left alone rather than invented.
   *
   * @covers ::getRepairFormat
   * @dataProvider emptyFormatProvider
   */
  public function testEmptyStoredFormatIsLeftAlone($stored): void {
    $this->assertNull($this->repair->getRepairFormat($stored, ['restricted_html']));
  }

  /**
   * Empty-ish stored format values.
   */
  public static function emptyFormatProvider(): array {
    return [
      'null' => [NULL],
      'empty string' => [''],
    ];
  }

  /**
   * Comparison is strict, so a format list is matched by exact name only.
   *
   * @covers ::getRepairFormat
   */
  public function testFormatMatchingIsExact(): void {
    $this->assertSame(
      'restricted_html',
      $this->repair->getRepairFormat('restricted_html_legacy', ['restricted_html'])
    );
  }

}
