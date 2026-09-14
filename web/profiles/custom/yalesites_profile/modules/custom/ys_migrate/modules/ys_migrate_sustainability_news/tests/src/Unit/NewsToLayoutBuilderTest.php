<?php

declare(strict_types=1);

namespace Drupal\Tests\ys_migrate_sustainability_news\Unit;

use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\block_content\BlockContentInterface;
use Drupal\layout_builder\Section;
use Drupal\migrate\MigrateException;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\Row;
use Drupal\ys_migrate_sustainability_news\Plugin\migrate\process\sustainability_news\NewsToLayoutBuilder;

/**
 * Unit tests for the sn_news_to_layout_builder process plugin.
 *
 * @coversDefaultClass \Drupal\ys_migrate_sustainability_news\Plugin\migrate\process\sustainability_news\NewsToLayoutBuilder
 * @group ys_migrate_sustainability_news
 * @group yalesites
 */
class NewsToLayoutBuilderTest extends UnitTestCase {

  /**
   * The media storage mock.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $mediaStorage;

  /**
   * The block_content storage mock.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $blockStorage;

  /**
   * The entity type manager mock.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $entityTypeManager;

  /**
   * The uuid service mock.
   *
   * @var \Drupal\Component\Uuid\UuidInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $uuid;

  /**
   * The logger channel mock.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $logger;

  /**
   * The migrate executable mock, unused by the plugin but required by type.
   *
   * @var \Drupal\migrate\MigrateExecutableInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $migrateExecutable;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->mediaStorage = $this->createMock(EntityStorageInterface::class);
    $this->blockStorage = $this->createMock(EntityStorageInterface::class);
    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->entityTypeManager->method('getStorage')->willReturnMap([
      ['media', $this->mediaStorage],
      ['block_content', $this->blockStorage],
    ]);

    $this->uuid = $this->createMock(UuidInterface::class);
    // Guarantee a unique uuid per call regardless of how many components
    // are generated, since Section keys its components by uuid.
    $counter = 0;
    $this->uuid->method('generate')->willReturnCallback(function () use (&$counter) {
      return 'uuid-' . ++$counter;
    });

    $this->logger = $this->createMock(LoggerChannelInterface::class);
    $this->migrateExecutable = $this->createMock(MigrateExecutableInterface::class);
  }

  /**
   * Builds a plugin instance with the given process configuration.
   */
  protected function buildPlugin(array $configuration): NewsToLayoutBuilder {
    return new NewsToLayoutBuilder(
      $configuration,
      'sn_news_to_layout_builder',
      [],
      $this->entityTypeManager,
      $this->uuid,
      $this->logger
    );
  }

  /**
   * Reads a Section's raw layout settings via reflection.
   *
   * Section::getLayoutSettings() instantiates the layout plugin via
   * \Drupal::service('plugin.manager.core.layout'), which isn't available
   * in a unit test; reading the underlying property directly avoids that.
   */
  protected function getRawLayoutSettings(Section $section): array {
    $property = new \ReflectionProperty(Section::class, 'layoutSettings');
    $property->setAccessible(TRUE);
    return $property->getValue($section);
  }

  /**
   * Sets the plugin's private current-row state via reflection.
   *
   * Transform() normally sets this for the duration of one call; several
   * tests below exercise mapComponents()/resolveSource() directly, which
   * requires the row to already be in place.
   */
  protected function setRow(NewsToLayoutBuilder $plugin, Row $row): void {
    $property = new \ReflectionProperty(NewsToLayoutBuilder::class, 'row');
    $property->setAccessible(TRUE);
    $property->setValue($plugin, $row);
  }

  /**
   * Transform() requires at least one configured section.
   *
   * @covers ::transform
   */
  public function testTransformThrowsWhenNoSectionsConfigured() {
    $plugin = $this->buildPlugin([]);

    $this->expectException(MigrateException::class);
    $this->expectExceptionMessage('sn_news_to_layout_builder: you must specify at least one section.');

    $plugin->transform(NULL, $this->migrateExecutable, new Row(), 'layout_builder__layout');
  }

  /**
   * CreateDefaultSection() requires default_section_type to be configured.
   *
   * @covers ::createDefaultSection
   */
  public function testCreateDefaultSectionThrowsWhenTypeMissing() {
    $plugin = $this->buildPlugin([]);

    $this->expectException(MigrateException::class);
    $this->expectExceptionMessage('sn_news_to_layout_builder: default_section_type is required when create_default_section is TRUE.');

    $plugin->createDefaultSection();
  }

  /**
   * CreateDefaultSection() builds the moderation control and meta block.
   *
   * @covers ::createDefaultSection
   */
  public function testCreateDefaultSectionBuildsExpectedComponents() {
    $plugin = $this->buildPlugin(['default_section_type' => 'post']);

    $section = $plugin->createDefaultSection();

    $this->assertEquals('layout_onecol', $section->getLayoutId());
    $this->assertEquals(['label' => 'Title and Metadata'], $this->getRawLayoutSettings($section));

    $components = array_values($section->getComponents());
    $this->assertCount(2, $components);

    // SectionComponent::get() only reads real object properties or the
    // "additional" array, not the plugin configuration -- toArray() is the
    // public way to inspect id/label/provider/etc.
    $first = $components[0]->toArray()['configuration'];
    $this->assertEquals('extra_field_block:node:post:content_moderation_control', $first['id']);
    $this->assertFalse($first['label_display']);
    $this->assertEquals(['entity' => 'layout_builder.entity'], $first['context_mapping']);

    $second = $components[1]->toArray()['configuration'];
    $this->assertEquals('post_meta_block', $second['id']);
    $this->assertEquals('Post Meta Block', $second['label']);
    $this->assertSame('', $second['label_display']);
    $this->assertEquals('ys_layouts', $second['provider']);
  }

  /**
   * MapSection() returns NULL for a layout id it doesn't support.
   *
   * @covers ::mapSection
   */
  public function testMapSectionWithUnsupportedIdReturnsNull() {
    $plugin = $this->buildPlugin([]);

    $result = $plugin->mapSection(['id' => 'layout_twocol', 'components' => []]);

    $this->assertNull($result);
  }

  /**
   * MapSection() delegates a one-column layout to createOneColumnSection().
   *
   * @covers ::mapSection
   */
  public function testMapSectionWithLayoutOnecolDelegates() {
    $plugin = $this->buildPlugin([]);

    $result = $plugin->mapSection(['id' => 'layout_onecol', 'components' => []]);

    $this->assertInstanceOf(Section::class, $result);
    $this->assertEquals('layout_onecol', $result->getLayoutId());
    $this->assertCount(0, $result->getComponents());
  }

  /**
   * MapComponents() returns NULL when the component has no source key.
   *
   * @covers ::mapComponents
   */
  public function testMapComponentsReturnsNullWhenSourceKeyMissing() {
    $plugin = $this->buildPlugin([]);

    $result = $plugin->mapComponents(['type' => 'text']);

    $this->assertNull($result);
  }

  /**
   * MapComponents() returns NULL when the resolved source value is empty.
   *
   * @covers ::mapComponents
   */
  public function testMapComponentsReturnsNullWhenResolvedSourceIsEmpty() {
    $plugin = $this->buildPlugin([]);
    $this->setRow($plugin, new Row(['body' => [['value' => '']]]));

    $result = $plugin->mapComponents(['type' => 'text', 'source' => 'body/0/value']);

    $this->assertNull($result);
  }

  /**
   * MapComponents() returns NULL for a component type it doesn't support.
   *
   * @covers ::mapComponents
   */
  public function testMapComponentsReturnsNullForUnsupportedType() {
    $plugin = $this->buildPlugin([]);
    $this->setRow($plugin, new Row(['caption' => 'Some value']));

    $result = $plugin->mapComponents(['type' => 'video', 'source' => 'caption']);

    $this->assertNull($result);
  }

  /**
   * MapComponents() builds a text block, optionally wrapping it in a <p>.
   *
   * @covers ::mapComponents
   */
  public function testMapComponentsCreatesTextBlockWithWrapValue() {
    $plugin = $this->buildPlugin([]);
    $this->setRow($plugin, new Row(['caption' => 'Plain caption text']));

    $block = $this->createMock(BlockContentInterface::class);
    $block->method('bundle')->willReturn('text');
    $block->expects($this->once())->method('save');

    $this->blockStorage->expects($this->once())
      ->method('create')
      ->with($this->callback(function (array $values) {
        return $values['type'] === 'text'
          && $values['field_text']['value'] === '<p>Plain caption text</p>'
          && $values['field_text']['format'] === 'basic_html';
      }))
      ->willReturn($block);

    $result = $plugin->mapComponents(['type' => 'text', 'source' => 'caption', 'wrap_value' => TRUE]);

    $this->assertSame($block, $result);
  }

  /**
   * MapComponents() strips D7-specific tokens from text before saving.
   *
   * @covers ::mapComponents
   */
  public function testMapComponentsSanitizesTextBlockBody() {
    $plugin = $this->buildPlugin([]);
    $body = 'Intro [[nid:1096]] middle [[{"type":"media","fid":"55"}]] outro.';
    $this->setRow($plugin, new Row(['body' => [['value' => $body]]]));

    $block = $this->createMock(BlockContentInterface::class);
    $this->blockStorage->expects($this->once())
      ->method('create')
      ->with($this->callback(function (array $values) {
        return $values['field_text']['value'] === 'Intro  middle  outro.';
      }))
      ->willReturn($block);

    $plugin->mapComponents(['type' => 'text', 'source' => 'body/0/value']);
  }

  /**
   * MapComponents() builds an image block from the first non-empty source.
   *
   * The media's alt text is used as an optional caption.
   *
   * @covers ::mapComponents
   */
  public function testMapComponentsCreatesImageBlockUsingFirstNonEmptySource() {
    $plugin = $this->buildPlugin([]);
    $row = new Row();
    // '_teaser_mid_image2' is left unset so resolution falls back to the
    // second entry in 'sources'.
    $row->setDestinationProperty('_teaser_mid_image', 42);
    $this->setRow($plugin, $row);

    $field_media_image = new class() {

      /**
       * The image alt text.
       *
       * @var string
       */
      public $alt = 'A researcher in a lab.';

    };
    $media = $this->createMock(FieldableEntityInterface::class);
    $media->method('id')->willReturn(42);
    $media->method('get')->with('field_media_image')->willReturn($field_media_image);
    $this->mediaStorage->expects($this->once())->method('load')->with(42)->willReturn($media);

    $block = $this->createMock(BlockContentInterface::class);
    $this->blockStorage->expects($this->once())
      ->method('create')
      ->with($this->callback(function (array $values) {
        return $values['type'] === 'image'
          && $values['field_media']['target_id'] === 42
          && $values['field_text']['value'] === '<p>A researcher in a lab.</p>';
      }))
      ->willReturn($block);

    $result = $plugin->mapComponents([
      'type' => 'image',
      'sources' => ['@_teaser_mid_image2', '@_teaser_mid_image'],
    ]);

    $this->assertSame($block, $result);
  }

  /**
   * MapComponents() logs a warning and returns NULL when the media is gone.
   *
   * @covers ::mapComponents
   */
  public function testMapComponentsReturnsNullWhenMediaCannotBeLoaded() {
    $plugin = $this->buildPlugin([]);
    $row = new Row();
    $row->setDestinationProperty('_teaser_mid_image', 99);
    $this->setRow($plugin, $row);

    $this->mediaStorage->method('load')->with(99)->willReturn(NULL);
    $this->blockStorage->expects($this->never())->method('create');
    $this->logger->expects($this->once())
      ->method('warning')
      ->with(
        'sn_news_to_layout_builder: could not load media entity @id — image block skipped.',
        ['@id' => 99]
      );

    $result = $plugin->mapComponents(['type' => 'image', 'source' => '@_teaser_mid_image']);

    $this->assertNull($result);
  }

  /**
   * Transform() assembles the default section plus a one-column section.
   *
   * The sections are returned in order, built from the configured
   * components.
   *
   * @covers ::transform
   * @covers ::createOneColumnSection
   */
  public function testTransformBuildsFullLayoutWithDefaultAndContentSections() {
    $row = new Row(['body' => [['value' => 'Plain body text.']]]);
    $row->setDestinationProperty('_teaser_mid_image', 7);

    $field_media_image = new class() {

      /**
       * The image alt text.
       *
       * @var string
       */
      public $alt = '';

    };
    $media = $this->createMock(FieldableEntityInterface::class);
    $media->method('get')->with('field_media_image')->willReturn($field_media_image);
    $this->mediaStorage->method('load')->with(7)->willReturn($media);

    $image_block = $this->createMock(BlockContentInterface::class);
    $image_block->method('bundle')->willReturn('image');
    $image_block->method('label')->willReturn('Image Block');
    $image_block->method('getRevisionId')->willReturn(10);

    $text_block = $this->createMock(BlockContentInterface::class);
    $text_block->method('bundle')->willReturn('text');
    $text_block->method('label')->willReturn('Text Block');
    $text_block->method('getRevisionId')->willReturn(11);

    $this->blockStorage->method('create')->willReturnCallback(
      fn (array $values) => $values['type'] === 'image' ? $image_block : $text_block
    );

    $configuration = [
      'create_default_section' => TRUE,
      'default_section_type' => 'post',
      'sections' => [
        [
          'id' => 'layout_onecol',
          'components' => [
            ['type' => 'image', 'sources' => ['@_teaser_mid_image']],
            ['type' => 'text', 'source' => 'body/0/value'],
          ],
        ],
      ],
    ];
    $plugin = $this->buildPlugin($configuration);

    $result = $plugin->transform(NULL, $this->migrateExecutable, $row, 'layout_builder__layout');

    $this->assertIsArray($result);
    $this->assertCount(2, $result);
    $this->assertEquals(['label' => 'Title and Metadata'], $this->getRawLayoutSettings($result[0]));

    $content_components = array_values($result[1]->getComponents());
    $this->assertCount(2, $content_components);
    $image_configuration = $content_components[0]->toArray()['configuration'];
    $text_configuration = $content_components[1]->toArray()['configuration'];
    $this->assertEquals('inline_block:image', $image_configuration['id']);
    $this->assertEquals(10, $image_configuration['block_revision_id']);
    $this->assertEquals('inline_block:text', $text_configuration['id']);
    $this->assertEquals(11, $text_configuration['block_revision_id']);
  }

  /**
   * Transform() skips a section entirely if it has no configured components.
   *
   * @covers ::transform
   */
  public function testTransformSkipsSectionsWithNoComponents() {
    $configuration = [
      'create_default_section' => FALSE,
      'sections' => [
        ['id' => 'layout_onecol', 'components' => []],
      ],
    ];
    $plugin = $this->buildPlugin($configuration);

    $result = $plugin->transform(NULL, $this->migrateExecutable, new Row(), 'layout_builder__layout');

    $this->assertSame([], $result);
  }

  /**
   * SanitizeBodyHtml() strips Node Embed and D7 Media WYSIWYG tokens.
   *
   * Invoked via reflection since the method is private; this characterizes
   * the exact regex behavior in isolation from block creation.
   *
   * @covers ::sanitizeBodyHtml
   */
  public function testSanitizeBodyHtmlStripsD7Tokens() {
    $plugin = $this->buildPlugin([]);
    $method = new \ReflectionMethod(NewsToLayoutBuilder::class, 'sanitizeBodyHtml');
    $method->setAccessible(TRUE);

    $result = $method->invoke($plugin, 'Before [[nid:42]] After [[{"type":"media","fid":"7","view_mode":"full"}]] End');

    $this->assertEquals('Before  After  End', $result);
  }

  /**
   * SanitizeBodyHtml() leaves ordinary HTML untouched.
   *
   * @covers ::sanitizeBodyHtml
   */
  public function testSanitizeBodyHtmlLeavesPlainHtmlUnchanged() {
    $plugin = $this->buildPlugin([]);
    $method = new \ReflectionMethod(NewsToLayoutBuilder::class, 'sanitizeBodyHtml');
    $method->setAccessible(TRUE);

    $result = $method->invoke($plugin, '<p>Nothing to strip here.</p>');

    $this->assertEquals('<p>Nothing to strip here.</p>', $result);
  }

}
