<?php

namespace Drupal\Tests\ys_core\Kernel;

use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\ys_core\Linkit\RankedEntityMatcher;
use Drupal\ys_core\Linkit\RankedFileMatcher;
use Drupal\ys_core\Linkit\RankedNodeMatcher;

/**
 * Tests that Linkit suggests exact title matches first.
 *
 * The titles are chosen so stock Linkit, which sorts every title containing
 * the search A-Z and keeps the first "limit" of them, would list the exact
 * match fourth, and drop it altogether under a limit of two.
 *
 * @see \Drupal\ys_core\Linkit\RankedMatcherTrait
 *
 * @group yalesites
 * @group ys_core
 */
class LinkitRankedMatcherTest extends YsKernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'file',
    'filter',
    'text',
    'node',
    'entity_test',
    'linkit',
    'ys_core',
  ];

  /**
   * The Linkit matcher plugin manager.
   *
   * @var \Drupal\linkit\MatcherManager
   */
  protected $matcherManager;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['filter', 'node']);
    $this->matcherManager = $this->container->get('plugin.manager.linkit.matcher');

    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    foreach ([
      'Course Registration',
      'Academic Registration',
      'Registration Deadlines',
      'Registration',
      'Pre-Registration Checklist',
      'Commencement',
    ] as $title) {
      Node::create(['type' => 'page', 'title' => $title, 'status' => 1])->save();
    }

    $this->setUpCurrentUser([], ['access content']);
  }

  /**
   * Runs the content matcher and returns the suggested titles in order.
   *
   * @param string $search
   *   The text typed into the link field.
   * @param int $limit
   *   The matcher's result limit; 0 for none.
   *
   * @return string[]
   *   The suggestion labels.
   */
  protected function suggest(string $search, int $limit = 0): array {
    $matcher = $this->matcherManager->createInstance('entity:node', [
      'settings' => ['limit' => $limit],
    ]);
    return array_map(
      fn ($suggestion) => $suggestion->getLabel(),
      $matcher->execute($search)->getSuggestions(),
    );
  }

  /**
   * Exact titles come first, then titles that start with the search.
   */
  public function testExactThenPrefixThenContains(): void {
    $this->assertSame([
      'Registration',
      'Registration Deadlines',
      'Academic Registration',
      'Course Registration',
      'Pre-Registration Checklist',
    ], $this->suggest('Registration'));
  }

  /**
   * The exact match ignores case, as the stock "contains" match does.
   */
  public function testExactMatchIgnoresCase(): void {
    $this->assertSame('Registration', $this->suggest('registration')[0]);
  }

  /**
   * The limit no longer cuts an exact match off, and still caps the total.
   */
  public function testLimitKeepsExactMatch(): void {
    $this->assertSame(['Registration'], $this->suggest('Registration', 1));
    $this->assertSame(
      ['Registration', 'Registration Deadlines'],
      $this->suggest('Registration', 2),
    );
    $this->assertCount(4, $this->suggest('Registration', 4));
  }

  /**
   * A search nothing matches returns no suggestions.
   */
  public function testNoMatch(): void {
    $this->assertSame([], $this->suggest('Nonexistent'));
  }

  /**
   * The matchers on the YaleSites profile run the ranked classes.
   */
  public function testMatcherClassesAreSwapped(): void {
    $this->assertInstanceOf(RankedNodeMatcher::class, $this->matcherManager->createInstance('entity:node'));
    $this->assertInstanceOf(RankedFileMatcher::class, $this->matcherManager->createInstance('entity:file'));
    // Media gets one of the generic matchers Linkit derives per entity type;
    // entity_test stands in for it here.
    $this->assertSame(RankedEntityMatcher::class, $this->matcherManager->getDefinition('entity:entity_test')['class']);
  }

}
