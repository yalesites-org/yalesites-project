<?php

namespace Drupal\ys_migrate\Controller;

/**
 * Streams a downloadable skeleton CSV for the Post bulk importer.
 */
class PostCsvSampleController extends CsvSampleControllerBase {

  /**
   * {@inheritdoc}
   */
  protected function columns() {
    return $this->csvValidator->getExpectedPostColumns();
  }

  /**
   * {@inheritdoc}
   */
  protected function filename() {
    return 'post-import-sample.csv';
  }

  /**
   * {@inheritdoc}
   *
   * Values are illustrative only. The taxonomy names and the Teaser Media ID
   * must match terms and an image that exist on the site doing the import,
   * so the row as written may well be rejected there; that rejection is the
   * check working.
   *
   * External Source is the one column left empty: a post with one redirects
   * visitors to that address, so its Body would never be seen.
   */
  protected function exampleRow() {
    return [
      'title' => 'Yale Researchers Publish Climate Study',
      'publish date' => '2026-03-04',
      'author' => 'Office of Communications',
      'body' => '<p>A new study from the School of the Environment looks at ten years of local climate data.</p><h2>Key findings</h2><ul><li>Average summer temperatures rose.</li><li>Rainfall became less predictable.</li></ul>',
      'category' => 'News',
      'tags' => 'Research, Climate',
      'audience' => 'Faculty, Staff',
      'custom vocab' => 'Sustainability',
      'teaser title' => 'Climate Study Published',
      'teaser text' => 'Ten years of local climate data, and what they show.',
      'teaser media id' => '123',
      'external source' => '',
      'cas login required' => 'No',
      'url alias' => '/news/climate-study',
    ];
  }

}
