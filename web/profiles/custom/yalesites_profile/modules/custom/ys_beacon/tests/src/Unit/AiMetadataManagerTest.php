<?php

namespace Drupal\Tests\ys_beacon\Unit;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Utility\Token;
use Drupal\metatag\MetatagManager;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_beacon\Service\AiMetadataManager;

/**
 * Tests the AI metadata read off an entity before it reaches the index.
 *
 * The ai_description value is mapped as contextual_content in
 * ai_search.index.ys_beacon.yml, so whatever this service returns is embedded
 * into the vector for every chunk of the item. Sites migrated from the legacy
 * your.yale.edu platform carry raw importer bookkeeping in that metatag
 * ("source_url: https://your.yale.edu/media/3359/download?inline") rather than
 * a description, and a bare URL both adds noise to the vector and pulls the
 * chunk toward unrelated URL-shaped queries. Those values must be treated as
 * if no description had been provided at all.
 *
 * @group ys_beacon
 * @coversDefaultClass \Drupal\ys_beacon\Service\AiMetadataManager
 */
class AiMetadataManagerTest extends UnitTestCase {

  /**
   * The migration-metadata boundary, case by case.
   *
   * Both directions matter equally. Failing to suppress leaves the noise in the
   * vector, which is the bug; suppressing too eagerly silently discards an
   * editor's real description, and because the value is dropped without a log
   * or any admin signal that failure would be invisible. The great majority of
   * the platform is not migrated, so the preserved cases guard the common path.
   *
   * @return array
   *   Test cases of [description, expected value after sanitisation].
   */
  public static function descriptionProvider(): array {
    return [
      'reported gatewayassist value' => [
        'source_url: https://your.yale.edu/media/3359/download?inline',
        '',
      ],
      'a different bookkeeping key' => [
        'file_url: https://your.yale.edu/sites/default/files/2026-06/bank-statement-example.pdf',
        '',
      ],
      'bookkeeping key in mixed case' => [
        'Source_Url: https://your.yale.edu/media/3360/download?inline',
        '',
      ],
      'bookkeeping key behind leading whitespace' => [
        "  source_url: https://your.yale.edu/media/3359/download\n",
        '',
      ],
      'bookkeeping key on a later line' => [
        "title: Supplier Gateway\nsource_url: https://your.yale.edu/media/3359/download",
        '',
      ],
      'other bookkeeping key on a later line' => [
        "title: Supplier Gateway\nfile_url: https://your.yale.edu/media/3359/download",
        '',
      ],
      'prose quoting the bookkeeping key is a real description' => [
        'Set the source_url: parameter to the media path before importing.',
        'Set the source_url: parameter to the media path before importing.',
      ],
      'prose mentioning a URL is a real description' => [
        'Supplier onboarding steps; the full policy lives at https://your.yale.edu/policy/3359.',
        'Supplier onboarding steps; the full policy lives at https://your.yale.edu/policy/3359.',
      ],
      'an ordinary description' => [
        'Reference guide for suppliers using the gateway.',
        'Reference guide for suppliers using the gateway.',
      ],
    ];
  }

  /**
   * Migration bookkeeping is suppressed and real descriptions survive.
   *
   * @dataProvider descriptionProvider
   *
   * @covers ::getAiMetadata
   * @covers ::isMigrationMetadata
   */
  public function testDescriptionBoundary(string $description, string $expected): void {
    $this->assertSame($expected, $this->getMetadata(['ai_description' => $description])['ai_description']);
  }

  /**
   * Markup is still stripped from both AI values.
   *
   * Stripping runs before the migration check, so bookkeeping wrapped in markup
   * by a WYSIWYG is still detected.
   *
   * @covers ::getAiMetadata
   */
  public function testMarkupIsStrippedFromBothValues(): void {
    $metadata = $this->getMetadata([
      'ai_description' => '<p>Reference <strong>guide</strong>.</p>',
      'ai_tags' => '<em>supplier</em>, gateway',
    ]);

    $this->assertSame('Reference guide.', $metadata['ai_description']);
    $this->assertSame('supplier, gateway', $metadata['ai_tags']);

    $wrapped = $this->getMetadata([
      'ai_description' => '<p>source_url: https://your.yale.edu/media/3359/download</p>',
    ]);

    $this->assertSame('', $wrapped['ai_description']);
  }

  /**
   * Tags and the indexing flag are untouched by the description guard.
   *
   * @covers ::getAiMetadata
   */
  public function testTagsAndDisableFlagAreIndependent(): void {
    $metadata = $this->getMetadata([
      'ai_description' => 'source_url: https://your.yale.edu/media/3359/download',
      'ai_tags' => 'supplier, gateway',
      'ai_disable_indexing' => '1',
    ]);

    $this->assertSame('', $metadata['ai_description']);
    $this->assertSame('supplier, gateway', $metadata['ai_tags']);
    $this->assertTrue($metadata['ai_disable_index']);
  }

  /**
   * A media item with no AI metatags at all yields empty values.
   *
   * This is the shape a suppressed description has to match: the search_api
   * processor skips empty values, so a migrated item ends up on exactly the
   * same path as an item that never had a description. That contract is pinned
   * in AiMetadataPropertiesTest.
   *
   * @covers ::getAiMetadata
   */
  public function testMissingTagsYieldEmptyValues(): void {
    $metadata = $this->getMetadata([]);

    $this->assertSame('', $metadata['ai_description']);
    $this->assertSame('', $metadata['ai_tags']);
    $this->assertFalse($metadata['ai_disable_index']);
  }

  /**
   * Runs the service over a set of metatag values from a media entity.
   *
   * @param array $tags
   *   The metatag values as MetatagManager::tagsFromEntity() would return them.
   *
   * @return array
   *   The metadata array returned by the service.
   */
  private function getMetadata(array $tags): array {
    $entity = $this->createMock(ContentEntityInterface::class);
    $entity->method('getEntityTypeId')->willReturn('media');

    $metatagManager = $this->createMock(MetatagManager::class);
    $metatagManager->method('tagsFromEntity')->with($entity)->willReturn($tags);

    $token = $this->createMock(Token::class);
    $token->method('replace')->willReturnArgument(0);

    return (new AiMetadataManager($metatagManager, $token))->getAiMetadata($entity);
  }

}
