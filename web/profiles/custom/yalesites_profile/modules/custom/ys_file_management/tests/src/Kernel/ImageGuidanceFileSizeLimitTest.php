<?php

namespace Drupal\Tests\ys_file_management\Kernel;

use Drupal\file\Entity\File;
use Drupal\field\Entity\FieldConfig;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use Drupal\Tests\ys_core\Kernel\YsKernelTestBase;

/**
 * Tests the oversize-upload message names actual size, maximum and a fix.
 *
 * @group ys_file_management
 * @group yalesites
 */
class ImageGuidanceFileSizeLimitTest extends YsKernelTestBase {

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
    'ys_file_management',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
    $this->createMediaType('image', ['id' => 'image']);
    $this->setMaxResolution('3840x2160');
  }

  /**
   * Sets the image field's maximum resolution.
   */
  protected function setMaxResolution(string $resolution): void {
    $field = FieldConfig::loadByName('media', 'image', 'field_media_image');
    $field->setSetting('max_resolution', $resolution)->save();
  }

  /**
   * Validates a new 3 MB file against a 1 MB limit; returns the messages.
   */
  protected function messagesFor(string $filename, string $mime): array {
    $file = File::create([
      'uri' => 'public://' . $filename,
      'filename' => $filename,
      'filemime' => $mime,
      'filesize' => 3 * 1024 * 1024,
    ]);
    $violations = $this->container->get('file.validator')
      ->validate($file, ['FileSizeLimit' => ['fileLimit' => 1024 * 1024]]);
    $messages = [];
    foreach ($violations as $violation) {
      $messages[] = strip_tags((string) $violation->getMessage());
    }
    return $messages;
  }

  /**
   * An oversize image keeps both sizes and gains a concrete next step.
   */
  public function testOversizeImageMessage(): void {
    $messages = $this->messagesFor('big.png', 'image/png');
    $this->assertCount(1, $messages);
    $this->assertStringContainsString('3 MB', $messages[0]);
    $this->assertStringContainsString('1 MB', $messages[0]);
    $this->assertStringContainsString('3840 x 2160', $messages[0]);
    $this->assertStringContainsString('lower quality', $messages[0]);
  }

  /**
   * Without a configured resolution the message still gives a next step.
   */
  public function testOversizeImageMessageWithoutResolution(): void {
    $this->setMaxResolution('');
    $messages = $this->messagesFor('big.png', 'image/png');
    $this->assertCount(1, $messages);
    $this->assertStringContainsString('lower quality', $messages[0]);
    $this->assertStringNotContainsString('pixels', $messages[0]);
  }

  /**
   * Non-image files keep core's message.
   */
  public function testOversizeNonImageKeepsCoreMessage(): void {
    $messages = $this->messagesFor('big.pdf', 'application/pdf');
    $this->assertCount(1, $messages);
    $this->assertStringContainsString('exceeding the maximum file size of 1 MB', $messages[0]);
    $this->assertStringNotContainsString('Resize', $messages[0]);
  }

}
