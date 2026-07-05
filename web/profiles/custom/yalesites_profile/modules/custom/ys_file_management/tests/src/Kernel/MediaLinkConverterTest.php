<?php

namespace Drupal\Tests\ys_file_management\Kernel;

use Drupal\entity_test\Entity\EntityTest;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\Entity\File;
use Drupal\KernelTests\KernelTestBase;
use Drupal\media\Entity\Media;
use Drupal\media\MediaInterface;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;

/**
 * Kernel tests for MediaLinkConverter.
 *
 * Issue #835 removes "media" as a link type. Existing content that already
 * linked a document via a media entity keeps markup that resolves to an
 * inaccessible media page, so the converter rewrites those links to point at
 * the underlying file (the accessible target the file matcher would produce).
 *
 * @group ys_file_management
 * @group yalesites
 */
class MediaLinkConverterTest extends KernelTestBase {

  use MediaTypeCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'file',
    'image',
    'media',
    'entity_test',
    'ys_file_management',
  ];

  /**
   * The media link converter service.
   *
   * @var \Drupal\ys_file_management\Service\MediaLinkConverterInterface
   */
  protected $converter;

  /**
   * The document media type (file source).
   *
   * @var \Drupal\media\MediaTypeInterface
   */
  protected $documentType;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installEntitySchema('media');
    $this->installEntitySchema('entity_test');
    $this->installSchema('file', 'file_usage');
    $this->installSchema('system', 'sequences');
    $this->installConfig(['system', 'field', 'file', 'media', 'ys_file_management']);

    // A formatted-text field on the generic test entity stands in for a node
    // body / block text field carrying Linkit markup.
    FieldStorageConfig::create([
      'entity_type' => 'entity_test',
      'field_name' => 'field_body',
      'type' => 'text_long',
    ])->save();
    FieldConfig::create([
      'entity_type' => 'entity_test',
      'field_name' => 'field_body',
      'bundle' => 'entity_test',
    ])->save();

    // A document media type uses the "file" source (field_media_file).
    $this->documentType = $this->createMediaType('file', ['id' => 'document']);

    $this->converter = $this->container->get('ys_file_management.media_link_converter');
  }

  /**
   * Creates a document media entity wrapping a saved file.
   *
   * @return array
   *   A [media, file] tuple.
   */
  protected function createDocumentMedia(string $filename): array {
    $file = File::create([
      'uri' => 'public://' . $filename,
      'filename' => $filename,
    ]);
    $file->save();

    $source_field = $this->documentType->getSource()->getConfiguration()['source_field'];
    $media = Media::create([
      'bundle' => 'document',
      'name' => $filename,
      $source_field => ['target_id' => $file->id()],
    ]);
    $media->save();

    return [$media, $file];
  }

  /**
   * Builds an anchor with the media data-attributes Linkit stores.
   */
  protected function mediaLink(MediaInterface $media): string {
    return sprintf(
      '<p><a data-entity-type="media" data-entity-uuid="%s" data-entity-substitution="media_download_inline" href="/media/%s/download?inline">My document</a></p>',
      $media->uuid(),
      $media->id()
    );
  }

  /**
   * The service is wired and honours its interface.
   */
  public function testServiceExists(): void {
    $this->assertInstanceOf(
      'Drupal\ys_file_management\Service\MediaLinkConverterInterface',
      $this->converter
    );
  }

  /**
   * A document media link is rewritten to point at the underlying file.
   */
  public function testConvertMarkupRewritesMediaLinkToFile(): void {
    [$media, $file] = $this->createDocumentMedia('reference.pdf');
    $file_url = $this->container->get('file_url_generator')->generateString($file->getFileUri());

    $result = $this->converter->convertMarkup($this->mediaLink($media));

    $this->assertSame(1, $result['converted']);
    $this->assertSame(0, $result['skipped']);
    $this->assertStringContainsString('data-entity-type="file"', $result['html']);
    $this->assertStringContainsString('data-entity-uuid="' . $file->uuid() . '"', $result['html']);
    $this->assertStringContainsString('data-entity-substitution="file"', $result['html']);
    $this->assertStringContainsString('href="' . $file_url . '"', $result['html']);
    $this->assertStringNotContainsString('data-entity-type="media"', $result['html']);
  }

  /**
   * Non-media links (node, plain URL) are left untouched.
   */
  public function testConvertMarkupLeavesNonMediaLinksUnchanged(): void {
    $html = '<p><a data-entity-type="node" data-entity-uuid="abc" href="/node/1">A page</a> and <a href="https://example.com">external</a></p>';

    $result = $this->converter->convertMarkup($html);

    $this->assertSame(0, $result['converted']);
    $this->assertSame(0, $result['skipped']);
    $this->assertSame($html, $result['html']);
  }

  /**
   * A media link whose entity cannot be resolved is skipped, not mangled.
   */
  public function testConvertMarkupSkipsUnresolvableMedia(): void {
    $html = '<p><a data-entity-type="media" data-entity-uuid="00000000-0000-0000-0000-000000000000" href="/media/999/download?inline">Ghost</a></p>';

    $result = $this->converter->convertMarkup($html);

    $this->assertSame(0, $result['converted']);
    $this->assertSame(1, $result['skipped']);
    $this->assertSame($html, $result['html']);
  }

  /**
   * The bulk pass rewrites and saves every entity carrying a media link.
   */
  public function testConvertAllContentUpdatesEntities(): void {
    [$media, $file] = $this->createDocumentMedia('report.pdf');
    $file_url = $this->container->get('file_url_generator')->generateString($file->getFileUri());

    $entity = EntityTest::create([
      'name' => 'Has media link',
      'field_body' => [
        'value' => $this->mediaLink($media),
        'format' => 'basic_html',
      ],
    ]);
    $entity->save();

    $stats = $this->converter->convertAllContent();

    $this->assertSame(1, $stats['links_converted']);
    $this->assertSame(1, $stats['entities_updated']);
    $this->assertSame(0, $stats['entities_failed']);

    $reloaded = EntityTest::load($entity->id());
    $value = $reloaded->get('field_body')->value;
    $this->assertStringContainsString('data-entity-type="file"', $value);
    $this->assertStringContainsString('href="' . $file_url . '"', $value);
    $this->assertStringNotContainsString('data-entity-type="media"', $value);
    // The stored text format must be preserved.
    $this->assertSame('basic_html', $reloaded->get('field_body')->format);
  }

}
