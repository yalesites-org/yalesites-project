<?php

declare(strict_types=1);

namespace Drupal\ys_ai_tester;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Reads of the tester's own two tables that more than one class needs.
 *
 * Both the run views and the export service start from the same two places: a
 * run row looked up by the id in the route, and a result row's JSON citation
 * blob. Keeping one copy of each is not housekeeping — the CSV writer beside
 * this was split across two classes once and drifted, losing the BOM from one
 * of the two exports. A column added to the run lookup, or a fix to how an
 * absent citations value decodes, has to land in one place to stay consistent.
 *
 * Requires a $database property on the using class.
 */
trait TesterRunStorageTrait {

  /**
   * Loads a tester run row by id, or throws a 404.
   *
   * The id always arrives from a route parameter, so "no such run" is a
   * not-found response rather than an error: a deleted or mistyped run must
   * not come back as a valid but empty page or file.
   *
   * @param int $run_id
   *   The run id.
   * @param string $fields
   *   The columns to select (a code-controlled field list, not user input).
   *
   * @return object
   *   The run row.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
   *   When no run with the given id exists.
   */
  protected function loadRunOr404(int $run_id, string $fields): object {
    $run = $this->database->query(
      'SELECT ' . $fields . ' FROM {ys_ai_tester_run} WHERE id = :id',
      [':id' => $run_id]
    )->fetchObject();

    if (!$run) {
      throw new NotFoundHttpException();
    }

    return $run;
  }

  /**
   * Decodes a JSON-encoded citations string to an array.
   *
   * Absent, empty and malformed all decode to an empty list: a result stored
   * before citations were recorded, or by a backend that returned none, has no
   * sources rather than a broken sources list.
   */
  protected function decodeCitations(?string $citations): array {
    return json_decode($citations ?? '', TRUE) ?? [];
  }

}
