<?php

namespace Drupal\Tests\ys_views_basic\Kernel;

use Drupal\block_content\Entity\BlockContent;
use Drupal\block_content\Entity\BlockContentType;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;

/**
 * Tests the cards_per_row -> card_size migration deploy hook (#1648).
 *
 * The dial was briefly a numeric "Cards per row" select (3 or 4) before review
 * settled on a card size, so any listing saved against that shape has to keep
 * the appearance its author chose. 3 was the 3-up grid, which is "large"; 4 was
 * the 4-up grid, which is "small".
 *
 * @group yalesites
 */
class CardSizeMigrationTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'block_content',
    'path_alias',
    'views',
    'ys_views_basic',
  ];

  /**
   * The block content storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected $blockStorage;

  /**
   * The bundles that carry field_view_params and a card grid.
   *
   * `view` is the legacy bundle: it still exists in config and still has the
   * field, so the hook has to sweep it too.
   *
   * @var string[]
   */
  protected const BUNDLES = ['post_card', 'event_card', 'page_card', 'profile_card', 'view'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('block_content');

    FieldStorageConfig::create([
      'field_name' => 'field_view_params',
      'entity_type' => 'block_content',
      'type' => 'views_basic_params',
    ])->save();

    foreach (static::BUNDLES as $bundle) {
      BlockContentType::create(['id' => $bundle, 'label' => $bundle])->save();
      FieldConfig::create([
        'field_name' => 'field_view_params',
        'entity_type' => 'block_content',
        'bundle' => $bundle,
        'label' => 'View params',
      ])->save();
    }

    $this->blockStorage = $this->container->get('entity_type.manager')
      ->getStorage('block_content');

    require_once $this->root . '/profiles/custom/yalesites_profile/modules/custom/ys_views_basic/ys_views_basic.deploy.php';
  }

  /**
   * Creates a listing block whose params are $params.
   */
  protected function createListing(string $bundle, array $params): BlockContent {
    $block = BlockContent::create([
      'type' => $bundle,
      'info' => $bundle . ' listing',
      'field_view_params' => ['params' => json_encode($params)],
    ]);
    $block->save();
    return $block;
  }

  /**
   * Returns the decoded params currently stored on a block.
   */
  protected function storedParams(int $id): array {
    $this->blockStorage->resetCache([$id]);
    $block = $this->blockStorage->load($id);
    return json_decode($block->get('field_view_params')->first()->getValue()['params'], TRUE);
  }

  /**
   * The numeric dial values convert to the size that renders the same grid.
   *
   * @dataProvider providerConversion
   */
  public function testCardsPerRowConvertsToCardSize(int $cards_per_row, string $expected) {
    $block = $this->createListing('post_card', [
      'view_mode' => 'card',
      'cards_per_row' => $cards_per_row,
    ]);
    $revision_before = $block->getRevisionId();

    ys_views_basic_deploy_10003();

    $params = $this->storedParams($block->id());
    $this->assertSame($expected, $params['card_size'], 'The stored count became the equivalent size.');
    $this->assertArrayNotHasKey('cards_per_row', $params, 'The superseded key is removed.');
    // The converting save must stay in place. A Layout Builder inline block is
    // referenced by a specific block_revision_id and rendered through
    // loadRevision(), so creating a new revision here would leave every layout
    // pointing at the pre-migration revision and this hook would change
    // nothing on any actual page. Pinned so a future setNewRevision() fails
    // here rather than in production.
    $this->blockStorage->resetCache([$block->id()]);
    $this->assertSame(
      $revision_before,
      $this->blockStorage->load($block->id())->getRevisionId(),
      'The conversion saved in place rather than creating a new revision.'
    );
  }

  /**
   * Data provider for ::testCardsPerRowConvertsToCardSize().
   */
  public static function providerConversion(): array {
    return [
      'three up is large' => [3, 'large'],
      'four up is small' => [4, 'small'],
    ];
  }

  /**
   * Every bundle that can hold a card grid is swept, including legacy "view".
   */
  public function testAllListingBundlesAreSwept() {
    $ids = [];
    foreach (static::BUNDLES as $bundle) {
      $ids[$bundle] = $this->createListing($bundle, ['cards_per_row' => 4])->id();
    }

    ys_views_basic_deploy_10003();

    foreach ($ids as $bundle => $id) {
      $this->assertSame('small', $this->storedParams($id)['card_size'], "$bundle was converted.");
    }
  }

  /**
   * Unrelated params survive the rewrite untouched.
   */
  public function testOtherParamsArePreserved() {
    $original = [
      'view_mode' => 'card',
      'filters' => ['types' => ['post'], 'terms_include' => NULL],
      'field_options' => ['show_thumbnail' => 'show_thumbnail'],
      'sort_by' => 'field_publish_date:DESC',
      'display' => 'limit',
      'limit' => 6,
      'cards_per_row' => 4,
      'pin_label' => 'Pinned',
    ];
    $block = $this->createListing('post_card', $original);

    ys_views_basic_deploy_10003();

    $params = $this->storedParams($block->id());
    unset($original['cards_per_row']);
    foreach ($original as $key => $value) {
      $this->assertSame($value, $params[$key], "$key is unchanged.");
    }
  }

  /**
   * A listing with no dial value is left alone rather than backfilled.
   *
   * The read path already defaults an absent key to large
   * (ViewsBasicManager::getDefaultParamValue()), so writing one in would create
   * a pointless revision on every listing on every site.
   */
  public function testListingWithoutTheKeyIsNotRewritten() {
    $block = $this->createListing('post_card', ['view_mode' => 'card', 'limit' => 10]);
    $revision_before = $block->getRevisionId();

    ys_views_basic_deploy_10003();

    $params = $this->storedParams($block->id());
    $this->assertArrayNotHasKey('card_size', $params, 'No value is invented for a listing that never had one.');
    $this->blockStorage->resetCache([$block->id()]);
    $this->assertSame(
      $revision_before,
      $this->blockStorage->load($block->id())->getRevisionId(),
      'The block was not re-saved.'
    );
  }

  /**
   * A listing already holding a card size is left alone.
   */
  public function testAlreadyMigratedListingIsNotRewritten() {
    $block = $this->createListing('post_card', ['card_size' => 'small']);
    $revision_before = $block->getRevisionId();

    ys_views_basic_deploy_10003();

    $this->assertSame('small', $this->storedParams($block->id())['card_size']);
    $this->blockStorage->resetCache([$block->id()]);
    $this->assertSame(
      $revision_before,
      $this->blockStorage->load($block->id())->getRevisionId(),
      'The block was not re-saved.'
    );
  }

  /**
   * An unrecognised count falls back to the default rather than being kept.
   */
  public function testUnrecognisedCountFallsBackToTheDefault() {
    $block = $this->createListing('post_card', ['cards_per_row' => 7]);

    ys_views_basic_deploy_10003();

    $params = $this->storedParams($block->id());
    $this->assertSame('large', $params['card_size'], 'A count with no grid rule becomes the default size.');
    $this->assertArrayNotHasKey('cards_per_row', $params);
  }

  /**
   * Running the hook twice is a no-op the second time.
   */
  public function testHookIsIdempotent() {
    $block = $this->createListing('post_card', ['cards_per_row' => 4]);

    ys_views_basic_deploy_10003();
    $this->blockStorage->resetCache([$block->id()]);
    $revision_after_first = $this->blockStorage->load($block->id())->getRevisionId();

    ys_views_basic_deploy_10003();

    $this->assertSame('small', $this->storedParams($block->id())['card_size']);
    $this->blockStorage->resetCache([$block->id()]);
    $this->assertSame(
      $revision_after_first,
      $this->blockStorage->load($block->id())->getRevisionId(),
      'The second run re-saved nothing.'
    );
  }

  /**
   * An undecodable params blob is skipped, not rewritten blind.
   */
  public function testMalformedParamsAreSkipped() {
    $bad = BlockContent::create([
      'type' => 'post_card',
      'info' => 'malformed',
      'field_view_params' => ['params' => 'not json at all'],
    ]);
    $bad->save();
    $converted = $this->createListing('post_card', ['cards_per_row' => 4]);

    ys_views_basic_deploy_10003();

    $this->blockStorage->resetCache([$bad->id()]);
    $this->assertSame(
      'not json at all',
      $this->blockStorage->load($bad->id())->get('field_view_params')->first()->getValue()['params'],
      'The undecodable blob was left exactly as it was.'
    );
    $this->assertSame('small', $this->storedParams($converted->id())['card_size'], 'The sweep continued past it.');
  }

  /**
   * A block with an empty params field does not break the sweep.
   */
  public function testEmptyParamsAreSkipped() {
    $empty = BlockContent::create(['type' => 'post_card', 'info' => 'empty']);
    $empty->save();
    $converted = $this->createListing('post_card', ['cards_per_row' => 4]);

    ys_views_basic_deploy_10003();

    $this->assertSame('small', $this->storedParams($converted->id())['card_size'], 'The sweep continued past the empty block.');
  }

}
