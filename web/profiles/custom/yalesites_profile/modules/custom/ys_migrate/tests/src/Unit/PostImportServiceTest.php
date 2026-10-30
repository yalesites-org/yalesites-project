<?php

namespace Drupal\Tests\ys_migrate\Unit;

use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Layout\LayoutInterface;
use Drupal\Core\Layout\LayoutPluginManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\field\FieldConfigInterface;
use Drupal\layout_builder\Entity\LayoutEntityDisplayInterface;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\media\MediaInterface;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;
use Drupal\ys_migrate\Service\CsvValidatorService;
use Drupal\ys_migrate\Service\PostImportService;
use Drupal\ys_migrate\Service\TaxonomyResolverService;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Unit tests for PostImportService.
 *
 * @coversDefaultClass \Drupal\ys_migrate\Service\PostImportService
 * @group ys_migrate
 * @group yalesites
 */
class PostImportServiceTest extends UnitTestCase {

  /**
   * The taxonomy resolver mock.
   *
   * @var \Drupal\ys_migrate\Service\TaxonomyResolverService|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $taxonomyResolver;

  /**
   * Storage mocks keyed by entity type id.
   *
   * Any entity type without its own entry (field_config,
   * entity_form_display) gets $configStorage, whose load() finds nothing, so
   * the service falls back to its documented defaults.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface[]|\PHPUnit\Framework\MockObject\MockObject[]
   */
  protected $storages = [];

  /**
   * The storage mock for config the service reads but tests rarely vary.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $configStorage;

  /**
   * The logger channel mock.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $loggerChannel;

  /**
   * Values passed to block_content storage's create(), most recent last.
   *
   * @var array
   */
  protected $createdBlocks = [];

  /**
   * The service under test.
   *
   * @var \Drupal\ys_migrate\Service\PostImportService
   */
  protected $postImport;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Section reads its settings through the layout plugin, which it fetches
    // from the container. A stub plugin hands the settings straight back.
    $layoutManager = $this->createMock(LayoutPluginManagerInterface::class);
    $layoutManager->method('createInstance')
      ->willReturnCallback(function ($plugin_id, $configuration) {
        $layout = $this->createMock(LayoutInterface::class);
        $layout->method('getConfiguration')->willReturn($configuration);
        return $layout;
      });
    $container = new ContainerBuilder();
    $container->set('plugin.manager.core.layout', $layoutManager);
    \Drupal::setContainer($container);

    $currentUser = $this->createMock(AccountInterface::class);
    $currentUser->method('id')->willReturn(42);

    $this->taxonomyResolver = $this->createMock(TaxonomyResolverService::class);
    $this->taxonomyResolver->method('parseCommaSeparatedValues')
      ->willReturnCallback(function ($value) {
        return $value === '' ? [] : array_map('trim', array_filter(explode(',', $value)));
      });

    foreach (['node', 'media', 'path_alias', 'block_content', 'entity_view_display'] as $entity_type) {
      $this->storages[$entity_type] = $this->createMock(EntityStorageInterface::class);
    }
    $this->configStorage = $this->createMock(EntityStorageInterface::class);

    // Nothing uses a URL alias yet unless a test says otherwise.
    $this->storages['path_alias']->method('getQuery')->willReturnCallback(fn () => $this->mockQuery([]));

    // A plain object stands in for the block so the component's
    // block_serialized value can be unserialized and inspected.
    $this->storages['block_content']->method('create')
      ->willReturnCallback(function ($values) {
        $this->createdBlocks[] = $values;
        return (object) ['values' => $values];
      });

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')
      ->willReturnCallback(fn ($entity_type) => $this->storages[$entity_type] ?? $this->configStorage);

    $this->loggerChannel = $this->createMock(LoggerChannelInterface::class);
    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->with('ys_migrate')->willReturn($this->loggerChannel);

    $uuid = $this->createMock(UuidInterface::class);
    $uuid->method('generate')->willReturn('new-component-uuid');

    $this->postImport = new PostImportService(
      $currentUser,
      $this->taxonomyResolver,
      $entityTypeManager,
      $loggerFactory,
      $uuid
    );
    $this->postImport->setStringTranslation($this->getStringTranslationStub());
  }

  /**
   * Builds a query mock that returns the given ids from execute().
   */
  protected function mockQuery(array $ids): QueryInterface {
    $query = $this->createMock(QueryInterface::class);
    $query->method('condition')->willReturnSelf();
    $query->method('range')->willReturnSelf();
    $query->method('accessCheck')->willReturnSelf();
    $query->method('execute')->willReturn($ids);
    return $query;
  }

  /**
   * Makes findTerm() find the given terms, and nothing else.
   *
   * @param array $terms
   *   Term ids keyed by vocabulary, then by term name.
   */
  protected function knownTerms(array $terms): void {
    $this->taxonomyResolver->method('findTerm')
      ->willReturnCallback(function ($name, $vocabulary) use ($terms) {
        if (!isset($terms[$vocabulary][$name])) {
          return NULL;
        }
        $term = $this->createMock(TermInterface::class);
        $term->method('id')->willReturn($terms[$vocabulary][$name]);
        return $term;
      });
  }

  /**
   * Makes media storage hold one media item of the given type.
   */
  protected function mediaItem(int $id, string $type): void {
    $media = $this->createMock(MediaInterface::class);
    $media->method('bundle')->willReturn($type);
    $this->storages['media']->method('load')
      ->willReturnCallback(fn ($requested) => (string) $requested === (string) $id ? $media : NULL);
  }

  /**
   * Gives the post bundle the default layout config ships with.
   *
   * Mirrors core.entity_view_display.node.post.default.yml: a locked "Title
   * and Metadata" section, then an empty "Content Section".
   */
  protected function defaultLayout(): LayoutEntityDisplayInterface {
    $display = $this->createMock(LayoutEntityDisplayInterface::class);
    $display->method('getSections')->willReturn([
      new Section(
        'layout_onecol',
        ['label' => 'Title and Metadata'],
        [
          new SectionComponent('meta-uuid', 'content', [
            'id' => 'post_meta_block',
            'label' => 'Post Meta Block',
            'provider' => 'ys_layouts',
          ]),
        ],
        ['layout_builder_lock' => ['lock' => [1 => 1, 2 => 2]]]
      ),
      new Section(
        'layout_onecol',
        ['label' => 'Content Section'],
        [],
        ['layout_builder_lock' => ['lock' => [6 => 6]]]
      ),
    ]);
    $this->storages['entity_view_display']->method('load')->with('node.post.default')->willReturn($display);
    return $display;
  }

  /**
   * Captures the values the next node create() receives.
   */
  protected function captureNodeValues(?array &$captured): void {
    $node = $this->createMock(NodeInterface::class);
    $this->storages['node']->method('create')
      ->willReturnCallback(function ($values) use (&$captured, $node) {
        $captured = $values;
        return $node;
      });
  }

  /**
   * A full CSV row is trimmed and reshaped into the expected post data.
   *
   * @covers ::preparePostData
   */
  public function testPreparePostDataWithFullRow() {
    $this->knownTerms([
      'post_category' => ['News' => 11],
      'tags' => ['Research' => 12, 'Climate' => 13],
      'audience' => ['Faculty' => 14],
      'custom_vocab' => ['Sustainability' => 15],
    ]);
    $this->mediaItem(123, 'image');

    $result = $this->postImport->preparePostData([
      'title' => '  Climate Study  ',
      'publish date' => '03/04/2026',
      'author' => ' Office of Communications ',
      'body' => ' <p>Findings.</p> ',
      'category' => 'News',
      'tags' => 'Research, Climate',
      'audience' => 'Faculty',
      'custom vocab' => 'Sustainability',
      'teaser title' => ' Study ',
      'teaser text' => ' Short teaser. ',
      'teaser media id' => ' 123 ',
      'external source' => ' https://example.com/study ',
      'cas login required' => 'Yes',
      'url alias' => 'news/climate-study',
    ]);

    $this->assertSame('Climate Study', $result['title']);
    $this->assertSame('2026-03-04', $result['publish_date']);
    $this->assertSame('Office of Communications', $result['author']);
    $this->assertSame('<p>Findings.</p>', $result['body']);
    $this->assertFalse($result['body_has_media']);
    $this->assertSame(['News'], $result['category']);
    $this->assertSame(['Research', 'Climate'], $result['tags']);
    $this->assertSame(['Faculty'], $result['audience']);
    $this->assertSame(['Sustainability'], $result['custom_vocab']);
    $this->assertSame('Study', $result['teaser_title']);
    $this->assertSame('Short teaser.', $result['teaser_text']);
    $this->assertSame(123, $result['teaser_media']);
    $this->assertSame('https://example.com/study', $result['external_source']);
    $this->assertTrue($result['login_required']);
    // The leading slash is added, as the node form's own widget requires one.
    $this->assertSame('/news/climate-study', $result['url_alias']);
  }

  /**
   * Every recognised CSV column is actually read into the prepared data.
   *
   * A column added to CsvValidatorService but never read here is validated,
   * listed as recognised, and given an example in the sample CSV -- and then
   * silently dropped.
   *
   * @covers ::preparePostData
   */
  public function testEveryExpectedColumnIsReadIntoPreparedData() {
    // Each recognised column and the prepared-data key it lands in.
    $lands_in = [
      'title' => 'title',
      'publish date' => 'publish_date',
      'author' => 'author',
      'body' => 'body',
      'category' => 'category',
      'tags' => 'tags',
      'audience' => 'audience',
      'custom vocab' => 'custom_vocab',
      'teaser title' => 'teaser_title',
      'teaser text' => 'teaser_text',
      'teaser media id' => 'teaser_media',
      'external source' => 'external_source',
      'cas login required' => 'login_required',
      'url alias' => 'url_alias',
    ];

    $this->assertEqualsCanonicalizing(
      array_keys(CsvValidatorService::EXPECTED_POST_COLUMNS),
      array_keys($lands_in),
      'Every recognised column must name the prepared-data key it lands in.'
    );

    $prepared = $this->postImport->preparePostData(['publish date' => '2026-03-04']);
    // body_has_media is derived from Body rather than read from a column.
    unset($prepared['body_has_media']);

    $this->assertEqualsCanonicalizing(
      array_values($lands_in),
      array_keys($prepared),
      'Every mapped key must be produced by preparePostData().'
    );
  }

  /**
   * A row with only the required cells yields empty values for the rest.
   *
   * @covers ::preparePostData
   */
  public function testPreparePostDataWithRequiredCellsOnly() {
    $result = $this->postImport->preparePostData([
      'title' => 'Bare Post',
      'publish date' => '2026-03-04',
    ]);

    $this->assertSame('', $result['author']);
    $this->assertSame('', $result['body']);
    $this->assertFalse($result['body_has_media']);
    $this->assertSame([], $result['category']);
    $this->assertSame([], $result['tags']);
    $this->assertNull($result['teaser_media']);
    $this->assertSame('', $result['external_source']);
    $this->assertFalse($result['login_required']);
    $this->assertSame('', $result['url_alias']);
  }

  /**
   * Publish Date is required on every row.
   *
   * @covers ::preparePostData
   */
  public function testPreparePostDataRequiresPublishDate() {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Publish Date is required.');

    $this->postImport->preparePostData(['title' => 'No Date', 'publish date' => ' ']);
  }

  /**
   * An unparseable Publish Date is rejected rather than guessed at.
   *
   * @covers ::preparePostData
   */
  public function testPreparePostDataRejectsInvalidPublishDate() {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Publish Date "2026-02-31" is not a valid date.');

    $this->postImport->preparePostData(['title' => 'Bad Date', 'publish date' => '2026-02-31']);
  }

  /**
   * A term the site does not have is reported, never created.
   *
   * @covers ::preparePostData
   */
  public function testPreparePostDataRejectsUnknownTerms() {
    $this->knownTerms(['tags' => ['Research' => 12]]);
    $this->taxonomyResolver->expects($this->never())->method('findOrCreateTerm');
    $this->taxonomyResolver->expects($this->never())->method('resolveTerms');

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Tags has no existing term named "Reserch", "Climate".');

    $this->postImport->preparePostData([
      'title' => 'Typo',
      'publish date' => '2026-03-04',
      'tags' => 'Research, Reserch, Climate',
    ]);
  }

  /**
   * Each taxonomy column looks its terms up in its own vocabulary.
   *
   * @covers ::preparePostData
   */
  public function testPreparePostDataLooksUpEachColumnInItsOwnVocabulary() {
    // "News" exists as a tag but not as a post category.
    $this->knownTerms(['tags' => ['News' => 12]]);

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Category has no existing term named "News".');

    $this->postImport->preparePostData([
      'title' => 'Wrong Vocabulary',
      'publish date' => '2026-03-04',
      'category' => 'News',
    ]);
  }

  /**
   * Teaser Media ID must name an image in the media library.
   *
   * @covers ::preparePostData
   *
   * @dataProvider badTeaserMediaProvider
   */
  public function testPreparePostDataRejectsBadTeaserMedia(string $value, string $message) {
    $this->mediaItem(7, 'document');

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage($message);

    $this->postImport->preparePostData([
      'title' => 'Bad Media',
      'publish date' => '2026-03-04',
      'teaser media id' => $value,
    ]);
  }

  /**
   * Teaser Media ID cells the importer must reject, and why.
   */
  public static function badTeaserMediaProvider(): array {
    return [
      'not a number' => ['photo.jpg', 'Teaser Media ID "photo.jpg" is not a media ID.'],
      'no such media' => ['999', 'Teaser Media ID 999 does not match anything in the media library.'],
      'not an image' => ['7', 'Teaser Media ID 7 is not an image.'],
    ];
  }

  /**
   * A URL Alias must be a path, not a web address or something unusable.
   *
   * @covers ::preparePostData
   *
   * @dataProvider badUrlAliasProvider
   */
  public function testPreparePostDataRejectsBadUrlAlias(string $value, string $message) {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage($message);

    $this->postImport->preparePostData([
      'title' => 'Bad Alias',
      'publish date' => '2026-03-04',
      'url alias' => $value,
    ]);
  }

  /**
   * URL Alias cells the importer must reject, and why.
   */
  public static function badUrlAliasProvider(): array {
    return [
      'full URL' => ['https://example.com/news/a', 'not a full web address'],
      'protocol-relative URL' => ['//example.com/news/a', 'not a full web address'],
      'space' => ['/news/my post', 'cannot contain spaces'],
      'query string' => ['/news/a?page=2', 'cannot contain spaces, "?" or "#"'],
    ];
  }

  /**
   * Images, video, embeds and D7 media tokens in the Body flag the post.
   *
   * @covers ::preparePostData
   *
   * @dataProvider bodyMediaProvider
   */
  public function testPreparePostDataFlagsBodyMedia(string $body, bool $expected) {
    $result = $this->postImport->preparePostData([
      'title' => 'Body',
      'publish date' => '2026-03-04',
      'body' => $body,
    ]);

    $this->assertSame($expected, $result['body_has_media']);
  }

  /**
   * Body cells and whether each holds something a Text block cannot show.
   */
  public static function bodyMediaProvider(): array {
    return [
      'image' => ['<p>Intro</p><img src="/a.jpg" alt="A">', TRUE],
      'uppercase image tag' => ['<P>Intro</P><IMG SRC="/a.jpg">', TRUE],
      'iframe' => ['<iframe src="https://www.youtube.com/embed/x"></iframe>', TRUE],
      'video' => ['<video src="/a.mp4"></video>', TRUE],
      'drupal media' => ['<drupal-media data-entity-uuid="x"></drupal-media>', TRUE],
      'D7 media token' => ['<p>Intro</p>[[{"type":"media","fid":"12","view_mode":"default"}]]', TRUE],
      'D7 node embed token' => ['<p>See [[nid:1096]]</p>', TRUE],
      'formatted text only' => ['<h2>Heading</h2><ul><li>One</li></ul><table><tr><td>A</td></tr></table>', FALSE],
      'word that looks like a tag' => ['<p>Images, video and embeds.</p>', FALSE],
    ];
  }

  /**
   * D7 inline tokens are removed, since they would render as literal text.
   *
   * @covers ::preparePostData
   */
  public function testPreparePostDataStripsD7Tokens() {
    $result = $this->postImport->preparePostData([
      'title' => 'Tokens',
      'publish date' => '2026-03-04',
      'body' => '<p>Before</p>[[{"type":"media","fid":"12","attributes":{"alt":"A [b]"}}]]<p>After [[nid:1096]]</p>',
    ]);

    $this->assertSame('<p>Before</p><p>After </p>', $result['body']);
    $this->assertTrue($result['body_has_media']);
  }

  /**
   * A body that was nothing but a token is imported with no Text block.
   *
   * @covers ::preparePostData
   */
  public function testPreparePostDataTokenOnlyBodyLeavesNoBody() {
    $result = $this->postImport->preparePostData([
      'title' => 'Only Token',
      'publish date' => '2026-03-04',
      'body' => '[[{"type":"media","fid":"12"}]]',
    ]);

    $this->assertSame('', $result['body']);
    $this->assertTrue($result['body_has_media']);
  }

  /**
   * Plain text becomes escaped paragraphs, keeping single line breaks.
   *
   * @covers ::preparePostData
   */
  public function testPreparePostDataWrapsPlainTextBody() {
    $result = $this->postImport->preparePostData([
      'title' => 'Plain',
      'publish date' => '2026-03-04',
      'body' => "Research & teaching\nin one line pair.\r\n\r\nSecond paragraph.",
    ]);

    $this->assertSame(
      "<p>Research &amp; teaching<br>\nin one line pair.</p>\n<p>Second paragraph.</p>",
      $result['body']
    );
  }

  /**
   * Boolean columns reject values they do not recognise.
   *
   * @covers ::preparePostData
   */
  public function testPreparePostDataRejectsUnknownBoolean() {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('CAS Login Required');

    $this->postImport->preparePostData([
      'title' => 'Maybe',
      'publish date' => '2026-03-04',
      'cas login required' => 'maybe',
    ]);
  }

  /**
   * Teaser Text longer than the form display's limit is rejected.
   *
   * @covers ::preparePostData
   */
  public function testPreparePostDataRejectsLongTeaserText() {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Teaser Text is 151 characters; the limit is 150.');

    $this->postImport->preparePostData([
      'title' => 'Long Teaser',
      'publish date' => '2026-03-04',
      'teaser text' => str_repeat('a', 151),
    ]);
  }

  /**
   * An existing post is matched on both title and publish date.
   *
   * @covers ::findExistingPost
   */
  public function testFindExistingPostMatchesTitleAndPublishDate() {
    $conditions = [];
    $query = $this->createMock(QueryInterface::class);
    $query->method('condition')->willReturnCallback(function ($field, $value) use (&$conditions, $query) {
      $conditions[$field] = $value;
      return $query;
    });
    $query->method('range')->willReturnSelf();
    $query->method('accessCheck')->willReturnSelf();
    $query->method('execute')->willReturn([7]);

    $node = $this->createMock(NodeInterface::class);
    $this->storages['node']->method('getQuery')->willReturn($query);
    $this->storages['node']->method('load')->with(7)->willReturn($node);

    $this->assertSame($node, $this->postImport->findExistingPost('Weekly Update', '2026-03-04'));
    $this->assertSame([
      'type' => 'post',
      'title' => 'Weekly Update',
      'field_publish_date' => '2026-03-04',
    ], $conditions);
  }

  /**
   * No match returns NULL rather than loading anything.
   *
   * @covers ::findExistingPost
   */
  public function testFindExistingPostReturnsNullWhenAbsent() {
    $this->storages['node']->method('getQuery')->willReturn($this->mockQuery([]));
    $this->storages['node']->expects($this->never())->method('load');

    $this->assertNull($this->postImport->findExistingPost('Nothing', '2026-03-04'));
  }

  /**
   * Prepared data is mapped onto the post bundle's real field names.
   *
   * @covers ::createPostNode
   */
  public function testCreatePostNodeMapsFields() {
    $this->knownTerms([
      'post_category' => ['News' => 11],
      'tags' => ['Research' => 12],
      'audience' => ['Faculty' => 13],
      'custom_vocab' => ['Sustainability' => 14],
    ]);
    $this->captureNodeValues($captured);

    $this->postImport->createPostNode([
      'title' => 'Climate Study',
      'publish_date' => '2026-03-04',
      'author' => 'Office of Communications',
      'category' => ['News'],
      'tags' => ['Research'],
      'audience' => ['Faculty'],
      'custom_vocab' => ['Sustainability'],
      'teaser_title' => 'Study',
      'teaser_text' => 'Short teaser.',
      'teaser_media' => 123,
      'external_source' => 'https://example.com/study',
      'login_required' => TRUE,
      'url_alias' => '/news/climate-study',
    ] + $this->emptyData());

    $this->assertSame('post', $captured['type']);
    $this->assertSame('Climate Study', $captured['title']);
    $this->assertSame('2026-03-04', $captured['field_publish_date']);
    $this->assertSame('Office of Communications', $captured['field_author']);
    $this->assertSame([11], $captured['field_category']);
    $this->assertSame([12], $captured['field_tags']);
    $this->assertSame([13], $captured['field_audience']);
    $this->assertSame([14], $captured['field_custom_vocab']);
    $this->assertSame('Study', $captured['field_teaser_title']);
    $this->assertSame(['target_id' => 123], $captured['field_teaser_media']);
    $this->assertSame(['uri' => 'https://example.com/study'], $captured['field_external_source']);
    $this->assertSame(1, $captured['field_login_required']);
    $this->assertSame(42, $captured['uid']);

    // The post bundle is under the editorial workflow, so publication is
    // owned by moderation_state. Setting 'status' instead is silently
    // overridden, which is why it must not be used here.
    $this->assertSame('draft', $captured['moderation_state']);
    $this->assertArrayNotHasKey('status', $captured);

    // Long-text fields must carry an explicit format the field allows, or the
    // widget renders disabled on the node form.
    $this->assertSame(
      ['value' => 'Short teaser.', 'format' => 'heading_html'],
      $captured['field_teaser_text']
    );

    // Pathauto would otherwise replace the alias with one from its pattern.
    $this->assertSame(['alias' => '/news/climate-study', 'pathauto' => 0], $captured['path']);
  }

  /**
   * Empty optional values are omitted rather than written as empty fields.
   *
   * @covers ::createPostNode
   */
  public function testCreatePostNodeOmitsEmptyOptionalFields() {
    $this->captureNodeValues($captured);
    $this->storages['entity_view_display']->expects($this->never())->method('load');

    $this->postImport->createPostNode(['title' => 'Bare Post', 'publish_date' => '2026-03-04'] + $this->emptyData());

    $this->assertArrayNotHasKey('field_author', $captured);
    $this->assertArrayNotHasKey('field_teaser_title', $captured);
    $this->assertArrayNotHasKey('field_teaser_text', $captured);
    $this->assertArrayNotHasKey('field_teaser_media', $captured);
    $this->assertArrayNotHasKey('field_external_source', $captured);
    $this->assertArrayNotHasKey('path', $captured);
    // With no Body the post keeps the bundle's default layout, exactly as a
    // post an editor never opened in Layout Builder does.
    $this->assertArrayNotHasKey('layout_builder__layout', $captured);
  }

  /**
   * The Body lands as one unsaved Text block in a copy of the default layout.
   *
   * @covers ::createPostNode
   */
  public function testCreatePostNodePlacesBodyInContentSection() {
    $display = $this->defaultLayout();
    $this->captureNodeValues($captured);

    $this->postImport->createPostNode([
      'title' => 'With Body',
      'publish_date' => '2026-03-04',
      'body' => '<p>Findings.</p>',
    ] + $this->emptyData());

    $items = $captured['layout_builder__layout'];
    $this->assertCount(2, $items);

    // The "Title and Metadata" section is carried over intact, locks
    // included, as Layout Builder does when an editor first edits a layout.
    $meta = $items[0]['section'];
    $this->assertSame('Title and Metadata', $meta->getLayoutSettings()['label']);
    $this->assertSame([1 => 1, 2 => 2], $meta->getThirdPartySetting('layout_builder_lock', 'lock'));
    $this->assertSame(['meta-uuid'], array_keys($meta->getComponents()));

    $content = $items[1]['section'];
    $this->assertSame('Content Section', $content->getLayoutSettings()['label']);
    $components = $content->getComponents();
    $this->assertCount(1, $components);

    $configuration = $components['new-component-uuid']->get('configuration');
    $this->assertSame('inline_block:text', $configuration['id']);
    $this->assertSame('content', $components['new-component-uuid']->getRegion());
    // Unsaved, so saving the node saves the block and records its usage.
    $this->assertNull($configuration['block_revision_id']);

    $block = unserialize($configuration['block_serialized'], ['allowed_classes' => [\stdClass::class]]);
    $this->assertSame('text', $block->values['type']);
    $this->assertFalse($block->values['reusable']);
    $this->assertSame(
      ['value' => '<p>Findings.</p>', 'format' => 'basic_html'],
      $block->values['field_text']
    );

    // The bundle's own default layout must not be altered by the import.
    $this->assertSame([], $display->getSections()[1]->getComponents());
  }

  /**
   * The Text block's format is read from the field's own config.
   *
   * @covers ::createPostNode
   */
  public function testCreatePostNodeReadsTextBlockFormatFromFieldConfig() {
    $this->defaultLayout();
    $this->captureNodeValues($captured);

    $field = $this->createMock(FieldConfigInterface::class);
    $field->method('getSetting')->with('allowed_formats')->willReturn(['some_other_html']);
    $this->configStorage->method('load')
      ->willReturnCallback(fn ($id) => $id === 'block_content.text.field_text' ? $field : NULL);

    $this->postImport->createPostNode([
      'title' => 'Configured',
      'publish_date' => '2026-03-04',
      'body' => '<p>Body.</p>',
    ] + $this->emptyData());

    $this->assertSame('some_other_html', end($this->createdBlocks)['field_text']['format']);
  }

  /**
   * A post bundle without a Layout Builder default fails the row clearly.
   *
   * @covers ::createPostNode
   */
  public function testCreatePostNodeFailsWithoutDefaultLayout() {
    $this->storages['entity_view_display']->method('load')->willReturn(NULL);
    $this->storages['node']->expects($this->never())->method('create');

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('The default post layout could not be loaded');

    $this->postImport->createPostNode([
      'title' => 'Nowhere',
      'publish_date' => '2026-03-04',
      'body' => '<p>Body.</p>',
    ] + $this->emptyData());
  }

  /**
   * A failed save is logged and rethrown so the row error is reportable.
   *
   * @covers ::createPostNode
   */
  public function testCreatePostNodeRethrowsSaveFailure() {
    $node = $this->createMock(NodeInterface::class);
    $node->method('save')->willThrowException(new \Exception('Database went away'));
    $this->storages['node']->method('create')->willReturn($node);

    $this->loggerChannel->expects($this->once())->method('error');

    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('Database went away');

    $this->postImport->createPostNode(['title' => 'Doomed', 'publish_date' => '2026-03-04'] + $this->emptyData());
  }

  /**
   * Import counts creations and lists posts whose Body lost images.
   *
   * @covers ::processImport
   */
  public function testProcessImportFlagsPostsNeedingImages() {
    $this->defaultLayout();
    $this->captureNodeValues($captured);
    $this->storages['node']->method('getQuery')->willReturn($this->mockQuery([]));

    $result = $this->postImport->processImport([
      ['title' => 'Text Only', 'publish date' => '2026-03-04', 'body' => '<p>Words.</p>'],
      ['title' => 'Had Image', 'publish date' => '2026-03-05', 'body' => '<p>Words.</p><img src="/a.jpg">'],
    ], FALSE);

    $this->assertSame(2, $result['created']);
    $this->assertSame(0, $result['skipped']);
    $this->assertSame([], $result['errors']);
    $this->assertSame(['Had Image'], $result['needs_images']);
  }

  /**
   * A duplicate is skipped, even when its URL Alias is (by itself) in use.
   *
   * Re-running a file must report its rows as duplicates, not as alias
   * clashes with the posts the first run created.
   *
   * @covers ::processImport
   */
  public function testProcessImportSkipsDuplicatesBeforeCheckingAliases() {
    $existing = $this->createMock(NodeInterface::class);
    $this->storages['node']->method('getQuery')->willReturn($this->mockQuery([9]));
    $this->storages['node']->method('load')->willReturn($existing);
    $this->storages['node']->expects($this->never())->method('create');

    // Replace the default "no alias in use" query.
    $this->storages['path_alias'] = $this->createMock(EntityStorageInterface::class);
    $this->storages['path_alias']->method('getQuery')->willReturn($this->mockQuery([3]));

    $result = $this->postImport->processImport([
      ['title' => 'Already Here', 'publish date' => '2026-03-04', 'url alias' => '/news/already-here'],
    ], TRUE);

    $this->assertSame(0, $result['created']);
    $this->assertSame(1, $result['skipped']);
    $this->assertSame([], $result['errors']);
  }

  /**
   * A URL Alias another page already uses fails only its own row.
   *
   * @covers ::processImport
   */
  public function testProcessImportReportsAliasInUse() {
    $this->captureNodeValues($captured);
    $this->storages['node']->method('getQuery')->willReturn($this->mockQuery([]));
    $this->storages['path_alias'] = $this->createMock(EntityStorageInterface::class);
    $this->storages['path_alias']->method('getQuery')->willReturn($this->mockQuery([3]));

    $result = $this->postImport->processImport([
      ['title' => 'Clash', 'publish date' => '2026-03-04', 'url alias' => '/about', '_row_number' => 4],
    ], TRUE);

    $this->assertSame(0, $result['created']);
    $this->assertCount(1, $result['errors']);
    $this->assertStringContainsString('Row 4: URL Alias "/about" is already in use', $this->text($result['errors'][0]));
  }

  /**
   * A bad cell fails only its own row and reports the real CSV line number.
   *
   * @covers ::processImport
   */
  public function testProcessImportReportsRowErrorsWithoutStoppingTheRun() {
    $this->knownTerms(['tags' => ['Research' => 12]]);
    $this->captureNodeValues($captured);
    $this->storages['node']->method('getQuery')->willReturn($this->mockQuery([]));

    $result = $this->postImport->processImport([
      ['title' => 'Good', 'publish date' => '2026-03-04', '_row_number' => 2],
      ['title' => 'Bad', 'publish date' => '2026-03-04', 'tags' => 'Unknown', '_row_number' => 7],
      ['title' => 'Also Good', 'publish date' => '2026-03-05', 'tags' => 'Research', '_row_number' => 8],
    ], FALSE);

    $this->assertSame(2, $result['created']);
    $this->assertCount(1, $result['errors']);
    $this->assertStringContainsString('Row 7: Tags has no existing term named "Unknown"', $this->text($result['errors'][0]));
  }

  /**
   * Preview creates nothing and separates duplicates from importable rows.
   *
   * @covers ::previewImport
   */
  public function testPreviewImportCreatesNothing() {
    $existing = $this->createMock(NodeInterface::class);
    $this->storages['node']->method('getQuery')->willReturnOnConsecutiveCalls(
      $this->mockQuery([]),
      $this->mockQuery([9])
    );
    $this->storages['node']->method('load')->willReturn($existing);
    $this->storages['node']->expects($this->never())->method('create');
    $this->storages['block_content']->expects($this->never())->method('create');

    $result = $this->postImport->previewImport([
      ['title' => 'New One', 'publish date' => '2026-03-04', 'body' => '<p>Body.</p>'],
      ['title' => 'Already Here', 'publish date' => '2026-03-04'],
    ], TRUE);

    $this->assertCount(1, $result['valid_posts']);
    $this->assertSame('New One', $result['valid_posts'][0]['title']);
    $this->assertSame(['Already Here'], $result['duplicates']);
    $this->assertSame([], $result['errors']);
    $this->assertSame(2, $result['total']);
  }

  /**
   * A title and date repeated in one file is a duplicate; a new date is not.
   *
   * @covers ::previewImport
   */
  public function testPreviewImportKeysDuplicatesOnTitleAndDate() {
    $this->storages['node']->method('getQuery')->willReturn($this->mockQuery([]));

    $result = $this->postImport->previewImport([
      ['title' => 'Weekly Update', 'publish date' => '2026-03-04'],
      ['title' => 'Weekly Update', 'publish date' => '2026-03-04'],
      ['title' => 'Weekly Update', 'publish date' => '2026-03-11'],
    ], TRUE);

    $this->assertCount(2, $result['valid_posts']);
    $this->assertSame(['Weekly Update'], $result['duplicates']);
  }

  /**
   * A URL Alias used by two rows is an error at preview, as at import.
   *
   * @covers ::previewImport
   */
  public function testPreviewImportReportsAliasRepeatedWithinTheFile() {
    $this->storages['node']->method('getQuery')->willReturn($this->mockQuery([]));

    $result = $this->postImport->previewImport([
      ['title' => 'First', 'publish date' => '2026-03-04', 'url alias' => '/news/same'],
      ['title' => 'Second', 'publish date' => '2026-03-05', 'url alias' => 'news/same', '_row_number' => 3],
    ], TRUE);

    $this->assertCount(1, $result['valid_posts']);
    $this->assertCount(1, $result['errors']);
    $this->assertStringContainsString('Row 3: URL Alias "/news/same" is used by more than one row.', $this->text($result['errors'][0]));
  }

  /**
   * Preview surfaces bad cells before anything is saved.
   *
   * @covers ::previewImport
   */
  public function testPreviewImportReportsRowErrors() {
    $this->storages['node']->method('getQuery')->willReturn($this->mockQuery([]));

    $result = $this->postImport->previewImport([
      ['title' => 'Fine', 'publish date' => '2026-03-04'],
      ['title' => 'Broken', 'publish date' => '', '_row_number' => 3],
    ], FALSE);

    $this->assertCount(1, $result['valid_posts']);
    $this->assertCount(1, $result['errors']);
    $this->assertStringContainsString('Row 3: Publish Date is required.', $this->text($result['errors'][0]));
  }

  /**
   * The plain text of a row error, which is escaped markup.
   */
  protected function text($message): string {
    return html_entity_decode((string) $message, ENT_QUOTES);
  }

  /**
   * Empty prepared values, for tests that only care about a few keys.
   */
  protected function emptyData(): array {
    return [
      'author' => '',
      'body' => '',
      'body_has_media' => FALSE,
      'category' => [],
      'tags' => [],
      'audience' => [],
      'custom_vocab' => [],
      'teaser_title' => '',
      'teaser_text' => '',
      'teaser_media' => NULL,
      'external_source' => '',
      'login_required' => FALSE,
      'url_alias' => '',
    ];
  }

}
