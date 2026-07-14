<?php

namespace Drupal\Tests\ys_beacon\Unit;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;
use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;
use Drupal\media\MediaSourceInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_beacon\Service\EntityCitationResolver;

/**
 * Tests citation title/URL derivation shared by indexing and retrieval.
 *
 * @group ys_beacon
 * @coversDefaultClass \Drupal\ys_beacon\Service\EntityCitationResolver
 */
class EntityCitationResolverTest extends UnitTestCase {

  /**
   * The title is the entity label (node title or media name).
   *
   * @covers ::title
   */
  public function testTitleIsEntityLabel(): void {
    $entity = $this->createMock(ContentEntityInterface::class);
    $entity->method('label')->willReturn('Housing Guide');

    $resolver = new EntityCitationResolver($this->createMock(EntityTypeManagerInterface::class));
    $this->assertSame('Housing Guide', $resolver->title($entity));
  }

  /**
   * A non-media entity links to its absolute canonical URL.
   *
   * @covers ::url
   */
  public function testUrlUsesCanonicalForNonMedia(): void {
    $url = $this->createMock(Url::class);
    $url->method('toString')->willReturn('https://site.example/node/1');

    $entity = $this->createMock(ContentEntityInterface::class);
    $entity->method('hasLinkTemplate')->with('canonical')->willReturn(TRUE);
    $entity->method('toUrl')->with('canonical', ['absolute' => TRUE])->willReturn($url);

    $resolver = new EntityCitationResolver($this->createMock(EntityTypeManagerInterface::class));
    $this->assertSame('https://site.example/node/1', $resolver->url($entity));
  }

  /**
   * A media entity links directly to its source file URL.
   *
   * @covers ::url
   */
  public function testUrlUsesSourceFileForMedia(): void {
    $file = $this->createMock(FileInterface::class);
    $file->method('createFileUrl')->with(FALSE)->willReturn('https://site.example/files/doc.pdf');

    $file_storage = $this->createMock(EntityStorageInterface::class);
    $file_storage->method('load')->with('42')->willReturn($file);

    $etm = $this->createMock(EntityTypeManagerInterface::class);
    $etm->method('getStorage')->with('file')->willReturn($file_storage);

    $source = $this->createMock(MediaSourceInterface::class);
    $media = $this->createMock(MediaInterface::class);
    $media->method('getSource')->willReturn($source);
    $source->method('getSourceFieldValue')->with($media)->willReturn('42');

    $resolver = new EntityCitationResolver($etm);
    $this->assertSame('https://site.example/files/doc.pdf', $resolver->url($media));
  }

  /**
   * An entity with no canonical link yields no URL rather than an error.
   *
   * @covers ::url
   */
  public function testUrlIsNullWhenNoCanonicalLink(): void {
    $entity = $this->createMock(ContentEntityInterface::class);
    $entity->method('hasLinkTemplate')->with('canonical')->willReturn(FALSE);

    $resolver = new EntityCitationResolver($this->createMock(EntityTypeManagerInterface::class));
    $this->assertNull($resolver->url($entity));
  }

}
