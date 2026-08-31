<?php

namespace Drupal\Tests\ys_core\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\taxonomy\Entity\Vocabulary;

/**
 * Tests seeding of the Page Category vocabulary's default terms.
 *
 * Issue #575: the Page Category vocabulary shipped with no default terms, so
 * the selector was empty and gave editors no guidance. ys_core provides a
 * populate helper (run from an update hook) that seeds a starting set of terms.
 * This test covers that helper, including that it is idempotent.
 *
 * @group ys_core
 * @group yalesites
 */
class PageCategoryDefaultTermsTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'filter', 'text', 'taxonomy'];

  /**
   * The default Page Category terms the helper must seed.
   */
  const EXPECTED_TERMS = [
    'Featured',
    'About',
    'Research',
    'Academics',
    'Contact',
    'Landing Page',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('taxonomy_term');

    // The Page Category vocabulary (config entity), initially with no terms.
    Vocabulary::create(['vid' => 'page_category', 'name' => 'Page Category'])->save();

    // The helper lives in ys_core.install; load it without enabling ys_core
    // (which would pull in cas/role_delegation and a heavy container).
    $path = \Drupal::service('extension.list.module')->getPath('ys_core');
    require_once \Drupal::root() . '/' . $path . '/ys_core.install';
  }

  /**
   * Returns the names of all terms currently in the Page Category vocabulary.
   */
  protected function pageCategoryTermNames(): array {
    $terms = \Drupal::entityTypeManager()
      ->getStorage('taxonomy_term')
      ->loadByProperties(['vid' => 'page_category']);
    return array_map(fn($term) => $term->getName(), $terms);
  }

  /**
   * The helper seeds the default terms and is idempotent on re-run.
   */
  public function testPopulateSeedsDefaultTermsIdempotently(): void {
    // First run seeds exactly the default term set.
    $created = ys_core_populate_page_category_terms();
    $this->assertSame(count(self::EXPECTED_TERMS), $created, 'All default terms are created on the first run.');
    $this->assertEqualsCanonicalizing(self::EXPECTED_TERMS, $this->pageCategoryTermNames(), 'The vocabulary contains exactly the default terms.');

    // Second run is a no-op: no duplicates, same term set.
    $created_again = ys_core_populate_page_category_terms();
    $this->assertSame(0, $created_again, 'A second run creates no duplicate terms.');
    $this->assertEqualsCanonicalizing(self::EXPECTED_TERMS, $this->pageCategoryTermNames(), 'Re-running does not change the term set.');
  }

}
