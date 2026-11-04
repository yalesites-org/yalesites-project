<?php

namespace Drupal\ys_migrate\Service;

use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\layout_builder\Entity\LayoutEntityDisplayInterface;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;

/**
 * Service for importing post content from CSV data.
 *
 * A post has no body field: everything below its title is built in Layout
 * Builder. The Body column therefore becomes a single inline Text block in the
 * post's own layout, which keeps headings, links, lists and tables. The Text
 * block's format allows no images, video or embeds, so a body containing them
 * is imported without them and the post is listed in the import result for an
 * editor to finish by hand.
 *
 * Taxonomy columns only accept terms the site already has: a misspelt term is
 * reported against its row rather than added to the vocabulary.
 */
class PostImportService extends CsvImportServiceBase {

  /**
   * {@inheritdoc}
   */
  const BUNDLE = 'post';

  /**
   * {@inheritdoc}
   */
  const TEXT_FORMAT_FALLBACKS = [
    'field_teaser_text' => 'heading_html',
  ];

  /**
   * Moderation state imported posts are created in.
   *
   * Matches the editorial workflow's own default_moderation_state.
   */
  const MODERATION_STATE = 'draft';

  /**
   * {@inheritdoc}
   */
  const VOCABULARIES = [
    'category' => 'post_category',
    'tags' => 'tags',
    'audience' => 'audience',
    'custom_vocab' => 'custom_vocab',
  ];

  /**
   * {@inheritdoc}
   */
  const URL_ALIAS_EXAMPLE = '/news/my-post';

  /**
   * The Text block field the Body is stored in.
   */
  const TEXT_BLOCK_FIELD = 'block_content.text.field_text';

  /**
   * Fallback format for the Text block field, which allows only this one.
   */
  const TEXT_BLOCK_FORMAT_FALLBACK = 'basic_html';

  /**
   * Label of the default layout section the Body is placed in.
   *
   * Matches core.entity_view_display.node.post.default.yml, where that
   * section is a one-column layout whose only region is "content".
   */
  const CONTENT_SECTION_LABEL = 'Content Section';

  /**
   * The UUID generator, for the Layout Builder components the import adds.
   *
   * @var \Drupal\Component\Uuid\UuidInterface
   */
  protected $uuid;

  /**
   * The post bundle's default layout sections, loaded once per import run.
   *
   * @var \Drupal\layout_builder\Section[]|null
   */
  protected $defaultSections;

  /**
   * Constructs a PostImportService object.
   *
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   * @param \Drupal\ys_migrate\Service\TaxonomyResolverService $taxonomy_resolver
   *   The taxonomy resolver service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\Component\Uuid\UuidInterface $uuid
   *   The UUID generator.
   */
  public function __construct(
    AccountInterface $current_user,
    TaxonomyResolverService $taxonomy_resolver,
    EntityTypeManagerInterface $entity_type_manager,
    LoggerChannelFactoryInterface $logger_factory,
    UuidInterface $uuid,
  ) {
    parent::__construct($current_user, $taxonomy_resolver, $entity_type_manager, $logger_factory);
    $this->uuid = $uuid;
  }

  /**
   * Prepares post data from a CSV row.
   *
   * @param array $row
   *   The CSV row data, keyed by lowercased column header.
   *
   * @return array
   *   Prepared post data. Taxonomy values stay as names, already checked to
   *   exist, so the preview can show what the editor typed.
   *
   * @throws \InvalidArgumentException
   *   If a cell cannot be interpreted. Thrown per row so one bad cell fails
   *   only its own row rather than the whole file.
   */
  public function preparePostData(array $row) {
    $body = $this->parseRichText($row['body'] ?? '');

    return [
      'title' => trim($row['title'] ?? ''),
      'publish_date' => $this->parseRequiredDate($row['publish date'] ?? '', 'Publish Date'),
      'author' => trim($row['author'] ?? ''),
      'body' => $body['html'],
      'body_has_media' => $body['has_media'],
      'category' => $this->parseTerms($row['category'] ?? '', 'category', 'Category'),
      'tags' => $this->parseTerms($row['tags'] ?? '', 'tags', 'Tags'),
      'audience' => $this->parseTerms($row['audience'] ?? '', 'audience', 'Audience'),
      // Alternative header spellings are folded onto the canonical name by
      // CsvValidatorService::POST_COLUMN_ALIASES before we see the row.
      'custom_vocab' => $this->parseTerms($row['custom vocab'] ?? '', 'custom_vocab', 'Custom Vocab'),
      'teaser_title' => trim($row['teaser title'] ?? ''),
      'teaser_text' => $this->parseTeaserText($row['teaser text'] ?? ''),
      'external_source' => $this->parseExternalSource($row['external source'] ?? ''),
      'login_required' => $this->parseBoolean($row['cas login required'] ?? '', 'CAS Login Required'),
      'url_alias' => $this->parseUrlAlias($row['url alias'] ?? ''),
    ];
  }

  /**
   * Finds an existing post by title and publish date.
   *
   * Title alone is too loose for posts, where a series such as a weekly
   * update reuses its title; the publish date tells those apart.
   *
   * @param string $title
   *   The title to search for.
   * @param string $publish_date
   *   The publish date, as Y-m-d.
   *
   * @return \Drupal\node\NodeInterface|null
   *   The existing post node or NULL.
   */
  public function findExistingPost($title, $publish_date) {
    $nids = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', self::BUNDLE)
      ->condition('title', $title)
      ->condition('field_publish_date', $publish_date)
      ->range(0, 1)
      ->accessCheck(FALSE)
      ->execute();

    if (!empty($nids)) {
      return $this->entityTypeManager->getStorage('node')->load(reset($nids));
    }

    return NULL;
  }

  /**
   * Creates a post node from the prepared data.
   *
   * @param array $data
   *   The post data, as returned by preparePostData().
   *
   * @return \Drupal\node\NodeInterface
   *   The created node.
   *
   * @throws \Exception
   *   If the node cannot be saved. The reason is logged and re-thrown so the
   *   caller can surface it instead of a generic failure message.
   */
  public function createPostNode(array $data) {
    $values = [
      'type' => self::BUNDLE,
      'title' => $data['title'],
      'field_publish_date' => $data['publish_date'],
      'field_category' => $this->termIds($data['category'], 'category', 'Category'),
      'field_tags' => $this->termIds($data['tags'], 'tags', 'Tags'),
      'field_audience' => $this->termIds($data['audience'], 'audience', 'Audience'),
      'field_custom_vocab' => $this->termIds($data['custom_vocab'], 'custom_vocab', 'Custom Vocab'),
      'field_login_required' => (int) $data['login_required'],
      'uid' => $this->currentUser->id(),
      // The post bundle runs under the editorial workflow, so publication is
      // owned by moderation_state; a 'status' value set here is silently
      // overridden. Imported posts land as drafts for an editor to review.
      'moderation_state' => self::MODERATION_STATE,
    ];

    // Optional values are omitted rather than written as empty, so an imported
    // node looks the same as one an editor left blank in the node form.
    if ($data['author'] !== '') {
      $values['field_author'] = $data['author'];
    }
    if ($data['teaser_title'] !== '') {
      $values['field_teaser_title'] = $data['teaser_title'];
    }
    if ($data['teaser_text'] !== '') {
      $values['field_teaser_text'] = [
        'value' => $data['teaser_text'],
        'format' => $this->textFormat('field_teaser_text'),
      ];
    }
    if ($data['external_source'] !== '') {
      $values['field_external_source'] = ['uri' => $data['external_source']];
    }
    if ($data['url_alias'] !== '') {
      // 'pathauto' => 0 is PathautoState::SKIP: without it Pathauto replaces
      // the alias the editor asked for with one built from its own pattern.
      $values['path'] = ['alias' => $data['url_alias'], 'pathauto' => 0];
    }
    if ($data['body'] !== '') {
      $values['layout_builder__layout'] = $this->buildLayout($data['body']);
    }

    $node = $this->entityTypeManager->getStorage('node')->create($values);

    try {
      $node->save();
      return $node;
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('ys_migrate')->error('Failed to create post node: @error', ['@error' => $e->getMessage()]);
      // Re-throw so processImport() can report the real reason to the user
      // instead of a generic "could not create post" message.
      throw $e;
    }
  }

  /**
   * {@inheritdoc}
   *
   * Adds a 'needs_images' key: the titles whose Body held images, video or
   * embeds that could not be imported, which an editor must add by hand.
   */
  public function processImport(array $data, $skip_duplicates) {
    $created = 0;
    $skipped = 0;
    $errors = [];
    $needs_images = [];

    foreach ($data as $index => $row) {
      try {
        $post_data = $this->preparePostData($row);

        if ($skip_duplicates && $this->findExistingPost($post_data['title'], $post_data['publish_date'])) {
          $skipped++;
          continue;
        }

        // Checked after the duplicate test, so re-running a file reports its
        // rows as skipped duplicates rather than as aliases already in use.
        $this->assertAliasAvailable($post_data['url_alias']);

        $this->createPostNode($post_data);
        $created++;

        if ($post_data['body_has_media']) {
          $needs_images[] = $post_data['title'];
        }
      }
      catch (\Exception $e) {
        $errors[] = $this->rowError($row, $index, $e);
      }
    }

    return [
      'created' => $created,
      'skipped' => $skipped,
      'errors' => $errors,
      'needs_images' => $needs_images,
    ];
  }

  /**
   * {@inheritdoc}
   *
   * Posts are keyed by title and publish date, and a pair repeated inside the
   * file counts as a duplicate too. A URL Alias repeated inside the file is an
   * error, as it would be at import. Adds an 'errors' key alongside
   * 'valid_posts', so a row that cannot be parsed is reported at preview
   * rather than at import.
   */
  public function previewImport(array $data, $skip_duplicates) {
    $duplicates = [];
    $valid_posts = [];
    $errors = [];
    $seen_posts = [];
    $seen_aliases = [];

    foreach ($data as $index => $row) {
      try {
        $post_data = $this->preparePostData($row);
        $key = $post_data['title'] . "\0" . $post_data['publish_date'];

        // A post repeated inside the file is a duplicate too: the import
        // creates the first row, then skips the rest. Tracking it here keeps
        // the preview's count honest, which is the whole point of previewing.
        if ($skip_duplicates
          && (isset($seen_posts[$key]) || $this->findExistingPost($post_data['title'], $post_data['publish_date']))) {
          $duplicates[] = $post_data['title'];
          continue;
        }

        $this->assertAliasAvailable($post_data['url_alias']);
        if ($post_data['url_alias'] !== '' && isset($seen_aliases[$post_data['url_alias']])) {
          throw new \InvalidArgumentException(sprintf(
            'URL Alias "%s" is used by more than one row.',
            $post_data['url_alias']
          ));
        }

        $seen_posts[$key] = TRUE;
        if ($post_data['url_alias'] !== '') {
          $seen_aliases[$post_data['url_alias']] = TRUE;
        }
        $valid_posts[] = $post_data;
      }
      catch (\Exception $e) {
        $errors[] = $this->rowError($row, $index, $e);
      }
    }

    return [
      'valid_posts' => $valid_posts,
      'duplicates' => $duplicates,
      'errors' => $errors,
      'total' => count($data),
    ];
  }

  /**
   * Parses a required date cell.
   *
   * @param string $value
   *   The raw cell value.
   * @param string $column
   *   The column label, for the error message.
   *
   * @return string
   *   The date as Y-m-d.
   *
   * @throws \InvalidArgumentException
   *   If the cell is empty or not a valid date.
   */
  protected function parseRequiredDate($value, $column) {
    $date = $this->parseDate($value, $column);

    if ($date === NULL) {
      throw new \InvalidArgumentException(sprintf('%s is required.', $column));
    }

    return $date;
  }

  /**
   * Builds a post layout holding the Body as one Text block.
   *
   * Starts from a copy of the post bundle's default sections, as Layout
   * Builder itself does when an editor first customises a post's layout. That
   * keeps the "Title and Metadata" section, and the locks on it, in place.
   *
   * @param string $body
   *   The Body markup.
   *
   * @return array
   *   Field items for layout_builder__layout.
   *
   * @throws \RuntimeException
   *   If the post bundle has no default layout to start from.
   */
  protected function buildLayout($body) {
    $sections = array_map(
      static fn (Section $section) => clone $section,
      $this->defaultSections()
    );

    if (!$sections) {
      throw new \RuntimeException('The default post layout could not be loaded, so the Body has nowhere to go.');
    }

    $content_section = end($sections);
    foreach ($sections as $section) {
      if (($section->getLayoutSettings()['label'] ?? '') === self::CONTENT_SECTION_LABEL) {
        $content_section = $section;
      }
    }

    $content_section->appendComponent($this->textBlockComponent($body));

    return array_map(static fn (Section $section) => ['section' => $section], $sections);
  }

  /**
   * Builds the inline Text block component that holds the Body.
   *
   * @param string $body
   *   The Body markup.
   *
   * @return \Drupal\layout_builder\SectionComponent
   *   The component, with its block not yet saved.
   */
  protected function textBlockComponent($body) {
    $block = $this->entityTypeManager->getStorage('block_content')->create([
      'type' => 'text',
      'info' => 'Body',
      'reusable' => FALSE,
      'field_text' => [
        'value' => $body,
        'format' => $this->allowedTextFormat(self::TEXT_BLOCK_FIELD, self::TEXT_BLOCK_FORMAT_FALLBACK),
      ],
    ]);

    // The block is handed over unsaved, the way Layout Builder's own block
    // form does it: saving the node then saves the block and records the
    // inline block usage that ties the block's access and clean-up to this
    // post. A block saved here first would never get that usage record.
    return new SectionComponent($this->uuid->generate(), 'content', [
      'id' => 'inline_block:text',
      'label' => 'Body',
      'label_display' => FALSE,
      'provider' => 'layout_builder',
      'view_mode' => 'full',
      'block_id' => NULL,
      'block_revision_id' => NULL,
      'block_serialized' => serialize($block),
      'context_mapping' => [],
    ]);
  }

  /**
   * Loads the post bundle's default layout sections.
   *
   * @return \Drupal\layout_builder\Section[]
   *   The sections, or an empty array when the display does not use Layout
   *   Builder.
   */
  protected function defaultSections() {
    if ($this->defaultSections === NULL) {
      $display = $this->entityTypeManager->getStorage('entity_view_display')
        ->load('node.' . self::BUNDLE . '.default');
      $this->defaultSections = $display instanceof LayoutEntityDisplayInterface ? $display->getSections() : [];
    }

    return $this->defaultSections;
  }

}
