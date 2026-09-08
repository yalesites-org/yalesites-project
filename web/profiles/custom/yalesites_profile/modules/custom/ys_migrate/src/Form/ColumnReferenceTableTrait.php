<?php

namespace Drupal\ys_migrate\Form;

/**
 * Shared "Recognised columns" reference table for the CSV import forms.
 *
 * Keeps the two forms' column-guidance tables structurally identical, so a
 * change to how one presents its columns doesn't quietly drift from the
 * other.
 */
trait ColumnReferenceTableTrait {

  /**
   * Builds the recognised-columns reference table render array.
   *
   * @param array $columns
   *   Expected columns keyed by machine name, valued by display label -- the
   *   shape CsvValidatorService::getExpectedColumns()/
   *   getExpectedResourceColumns() return.
   * @param array $notes
   *   Guidance text keyed by the same machine names as $columns. A column
   *   with no matching key renders an empty notes cell.
   *
   * @return array
   *   A #type => table render array.
   */
  protected function columnReferenceTable(array $columns, array $notes) {
    $rows = [];

    foreach ($columns as $key => $label) {
      $rows[] = [$label, $notes[$key] ?? ''];
    }

    return [
      '#type' => 'table',
      '#caption' => $this->t('Recognised columns'),
      '#header' => [$this->t('Column'), $this->t('Notes')],
      '#rows' => $rows,
    ];
  }

}
