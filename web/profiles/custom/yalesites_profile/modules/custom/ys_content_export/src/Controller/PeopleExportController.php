<?php

namespace Drupal\ys_content_export\Controller;

use Drupal\ys_content_export\PeopleExportBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Streams a CSV export of the accounts shown on the People page.
 */
class PeopleExportController extends ExportControllerBase {

  /**
   * The view behind the People page.
   *
   * Core's People view, reached at /admin/people. Exporting through it means
   * the file carries the same accounts, in the same order, as the screen.
   */
  const VIEW_ID = 'user_admin_people';

  /**
   * Streams a CSV download of the accounts shown on the People page.
   *
   * The route permission ("administer users") gates access, mirroring the
   * People view's own access check, so the button is available to exactly the
   * admins who can already open the page. The exported rows come from that
   * view with the request's exposed-filter query replayed, so the CSV reflects
   * the filters and search the admin has applied.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request, carrying the People page's exposed-filter query.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   A streamed CSV file download response.
   */
  public function export(Request $request): Response {
    return $this->streamCsv(
      'user',
      $this->filteredIds(self::VIEW_ID, 'uid', $request),
      array_values(PeopleExportBuilder::getColumns()),
      fn($user) => PeopleExportBuilder::getRow($user, $this->dateFormatter),
      $this->filename('people')
    );
  }

}
