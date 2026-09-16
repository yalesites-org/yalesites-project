<?php

namespace Drupal\Tests\ys_book\Kernel;

use Drupal\Tests\ys_core\Kernel\YsKernelTestBase;

/**
 * Tests that ys_book adds its title column to the contrib book table.
 *
 * A fresh install never runs ys_book_update_10005(), so hook_install() is the
 * only thing that adds the column; see _ys_book_add_book_title_column() for
 * why. Without this test that path is uncovered, because the other kernel
 * tests build the column into their fixtures directly.
 *
 * @group ys_book
 * @group yalesites
 */
class BookTitleColumnTest extends YsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'node',
    'field',
    'text',
    'book',
    'custom_book_block',
    'ys_book',
  ];

  /**
   * Installing ys_book adds the title column, and re-running it is harmless.
   */
  public function testInstallAddsTitleColumn(): void {
    // installSchema() builds the table from contrib book's own hook_schema(),
    // which is exactly the table a brand new site starts with.
    $this->installSchema('book', ['book']);

    $schema = $this->container->get('database')->schema();
    $this->assertFalse(
      $schema->fieldExists('book', 'title'),
      "Contrib book's schema has no title column, so ys_book must add it."
    );

    $this->container->get('module_handler')->loadInclude('ys_book', 'install');
    ys_book_install();

    $this->assertTrue(
      $schema->fieldExists('book', 'title'),
      'hook_install() adds the title column on a fresh site.'
    );

    // The update hook shares the same guarded helper, so running it against a
    // site that already has the column must not fail.
    ys_book_update_10005();

    $this->assertTrue(
      $schema->fieldExists('book', 'title'),
      'Re-running the column add is a no-op rather than an error.'
    );
  }

}
