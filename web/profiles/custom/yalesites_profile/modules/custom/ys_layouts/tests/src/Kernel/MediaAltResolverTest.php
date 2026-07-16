<?php

namespace Drupal\Tests\ys_layouts\Kernel;

use Drupal\file\Entity\File;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use Drupal\ys_layouts\Service\MediaAltResolver;

/**
 * Tests alt-text resolution for resource cover images.
 *
 * Regression test for the bug where a resource's book-cover image rendered
 * the media name (the uploaded filename) as its alt text instead of the alt
 * configured on the media's image field.
 *
 * @coversDefaultClass \Drupal\ys_layouts\Service\MediaAltResolver
 *
 * @group ys_layouts
 * @group yalesites
 */
class MediaAltResolverTest extends KernelTestBase {

  use MediaTypeCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'file',
    'field',
    'image',
    'media',
  ];

  /**
   * The resolver under test.
   *
   * @var \Drupal\ys_layouts\Service\MediaAltResolver
   */
  protected MediaAltResolver $resolver;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installEntitySchema('media');
    $this->installSchema('file', 'file_usage');
    $this->installConfig(['system', 'field', 'file', 'media']);

    $this->resolver = new MediaAltResolver();
  }

  /**
   * Creates a saved file entity for referencing from media.
   */
  protected function createFile(): File {
    $file = File::create([
      'uri' => 'public://fun-home-cover.jpg',
      'filename' => 'fun-home-cover.jpg',
    ]);
    $file->save();
    return $file;
  }

  /**
   * An image media returns the alt configured on its image field.
   *
   * @covers ::resolve
   */
  public function testConfiguredAltUsedForImageMedia(): void {
    $this->createMediaType('image', ['id' => 'image']);
    $file = $this->createFile();

    $media = $this->container->get('entity_type.manager')
      ->getStorage('media')
      ->create([
        'bundle' => 'image',
        'name' => 'fun-home-cover.jpg',
        'field_media_image' => [
          'target_id' => $file->id(),
          'alt' => 'Cover of Fun Home: A Family Tragicomic',
        ],
      ]);
    $media->save();

    $this->assertSame(
      'Cover of Fun Home: A Family Tragicomic',
      $this->resolver->resolve($media),
    );
  }

  /**
   * An image with empty alt stays empty (decorative), not the media name.
   *
   * @covers ::resolve
   */
  public function testDecorativeImageReturnsEmptyAlt(): void {
    $this->createMediaType('image', ['id' => 'image']);
    $file = $this->createFile();

    $media = $this->container->get('entity_type.manager')
      ->getStorage('media')
      ->create([
        'bundle' => 'image',
        'name' => 'decorative-flourish.png',
        'field_media_image' => [
          'target_id' => $file->id(),
          'alt' => '',
        ],
      ]);
    $media->save();

    $this->assertSame('', $this->resolver->resolve($media));
  }

  /**
   * A non-image media (no alt field) falls back to the media label.
   *
   * @covers ::resolve
   */
  public function testNonImageMediaFallsBackToLabel(): void {
    $this->createMediaType('file', ['id' => 'document']);
    $file = File::create([
      'uri' => 'public://syllabus.pdf',
      'filename' => 'syllabus.pdf',
    ]);
    $file->save();

    $media = $this->container->get('entity_type.manager')
      ->getStorage('media')
      ->create([
        'bundle' => 'document',
        'name' => 'Fall 2026 Syllabus',
        'field_media_file' => [
          'target_id' => $file->id(),
        ],
      ]);
    $media->save();

    $this->assertSame(
      'Fall 2026 Syllabus',
      $this->resolver->resolve($media),
    );
  }

}
