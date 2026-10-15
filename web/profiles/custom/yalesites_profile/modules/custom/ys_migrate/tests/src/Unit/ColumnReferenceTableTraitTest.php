<?php

namespace Drupal\Tests\ys_migrate\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\ys_migrate\Form\ColumnReferenceTableTrait;

/**
 * Unit tests for ColumnReferenceTableTrait.
 *
 * @coversDefaultClass \Drupal\ys_migrate\Form\ColumnReferenceTableTrait
 * @group ys_migrate
 * @group yalesites
 */
class ColumnReferenceTableTraitTest extends UnitTestCase {

  /**
   * Builds an object using the trait, with StringTranslationTrait stubbed.
   */
  protected function traitObject() {
    return new class() {
      use ColumnReferenceTableTrait;

      /**
       * Stubs translation with plain string substitution.
       */
      public function t($string, array $args = []) {
        return strtr($string, $args);
      }

      /**
       * Exposes the protected trait method for the test to call.
       */
      public function build(array $columns, array $notes) {
        return $this->columnReferenceTable($columns, $notes);
      }

    };
  }

  /**
   * ColumnReferenceTable() renders one row per column, label then note.
   *
   * @covers ::columnReferenceTable
   */
  public function testColumnReferenceTableBuildsRowsInColumnOrder() {
    $columns = ['title' => 'Title', 'audience' => 'Audience'];
    $notes = ['title' => 'Required.', 'audience' => 'Comma-separated.'];

    $table = $this->traitObject()->build($columns, $notes);

    $this->assertSame('table', $table['#type']);
    $this->assertSame(['Title', 'Required.'], $table['#rows'][0]);
    $this->assertSame(['Audience', 'Comma-separated.'], $table['#rows'][1]);
  }

  /**
   * ColumnReferenceTable() renders an empty notes cell for an unlisted column.
   *
   * @covers ::columnReferenceTable
   */
  public function testColumnReferenceTableWithMissingNoteIsEmptyString() {
    $columns = ['title' => 'Title'];

    $table = $this->traitObject()->build($columns, []);

    $this->assertSame(['Title', ''], $table['#rows'][0]);
  }

  /**
   * ColumnReferenceTable() sets a "Column"/"Notes" header.
   *
   * Each cell is an array rather than a bare string so it can carry
   * scope="col" (WCAG 2.1 AA 1.3.1).
   *
   * @covers ::columnReferenceTable
   */
  public function testColumnReferenceTableSetsHeader() {
    $table = $this->traitObject()->build(['title' => 'Title'], []);

    $this->assertSame([
      ['data' => 'Column', 'scope' => 'col'],
      ['data' => 'Notes', 'scope' => 'col'],
    ], $table['#header']);
  }

}
