<?php

namespace Drupal\ys_migrate\Controller;

/**
 * Streams a downloadable skeleton CSV for the Resource bulk importer.
 */
class ResourceCsvSampleController extends CsvSampleControllerBase {

  /**
   * {@inheritdoc}
   */
  protected function columns() {
    return $this->csvValidator->getExpectedResourceColumns();
  }

  /**
   * {@inheritdoc}
   */
  protected function filename() {
    return 'resource-import-sample.csv';
  }

  /**
   * {@inheritdoc}
   *
   * Values are illustrative only: a real http URL for External Source, a
   * date accepted by ResourceImportService::DATE_INPUT_FORMATS, and a
   * Date Format label accepted by ResourceImportService::parseDateFormat().
   */
  protected function exampleRow() {
    return [
      'title' => 'Yale Research Symposium Recap',
      'description' => 'Summary of presentations from the annual research symposium.',
      'abstract' => 'A short abstract of the symposium proceedings.',
      'citation' => 'Yale Research Review, 2026.',
      'journal publication name' => 'Yale Research Review',
      'journal publication issue' => 'Vol. 12, No. 2',
      'resource category' => 'Event',
      'audience' => 'Faculty, Staff',
      'custom vocab' => 'Sustainability',
      'resource publication date' => '2026-03-04',
      'date format' => 'Month/Day/Year',
      'tags' => 'Research, Symposium',
      'teaser title' => 'Symposium Recap',
      'teaser text' => 'Highlights from this year\'s research symposium.',
      'external source' => 'https://example.edu/symposium-2026',
      'cas login required' => 'No',
      'pin to beginning of list' => 'No',
    ];
  }

}
