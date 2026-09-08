<?php

namespace Drupal\Tests\ys_core\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\ys_core\Controller\AnnouncementsFeedController;

/**
 * Tests the category name filtering used when building feed items.
 *
 * Extracted as a pure static method because the surrounding feed() method
 * loads real node/taxonomy entities and this module has no precedent for
 * entity-creating Kernel tests - this keeps the whitelist-matching logic
 * itself fully Unit-testable.
 *
 * @group ys_core
 * @coversDefaultClass \Drupal\ys_core\Controller\AnnouncementsFeedController
 */
class AnnouncementsFeedControllerCategoriesTest extends UnitTestCase {

  /**
   * Only whitelisted names survive, in their original order and casing.
   *
   * @covers ::filterCategories
   */
  public function testKeepsOnlyWhitelistedNames(): void {
    $result = AnnouncementsFeedController::filterCategories(
      ['Feature release', 'Off-list', 'News'],
      ['Feature release', 'News', 'Important update'],
    );

    $this->assertSame(['Feature release', 'News'], $result);
  }

  /**
   * Matching is case-insensitive, but the term's own casing is emitted.
   *
   * The category field allows auto-created terms, so editor-typed casing
   * drift ("news" vs "News") is a real case, not a hypothetical one.
   *
   * @covers ::filterCategories
   */
  public function testMatchesCaseInsensitivelyButEmitsOriginalCasing(): void {
    $result = AnnouncementsFeedController::filterCategories(['news'], ['News']);

    $this->assertSame(['news'], $result);
  }

  /**
   * A post with no categories produces an empty, valid list.
   *
   * @covers ::filterCategories
   */
  public function testNoNamesProducesEmptyList(): void {
    $this->assertSame([], AnnouncementsFeedController::filterCategories([], ['News']));
  }

  /**
   * An empty whitelist keeps nothing, rather than falling back to "keep all".
   *
   * @covers ::filterCategories
   */
  public function testEmptyWhitelistKeepsNothing(): void {
    $this->assertSame([], AnnouncementsFeedController::filterCategories(['News'], []));
  }

  /**
   * Duplicate and blank names are removed before matching.
   *
   * @covers ::filterCategories
   */
  public function testDedupesAndTrims(): void {
    $result = AnnouncementsFeedController::filterCategories(
      [' News ', 'News', '', '  '],
      ['News'],
    );

    $this->assertSame(['News'], $result);
  }

}
