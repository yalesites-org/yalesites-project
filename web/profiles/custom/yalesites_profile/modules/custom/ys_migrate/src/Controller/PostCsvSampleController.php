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
   * Values are illustrative only. The taxonomy names must match terms that
   * exist on the site doing the import, so the row as written may well be
   * rejected there; that rejection is the check working.
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
      'external source' => '',
      'cas login required' => 'No',
      'url alias' => '/news/climate-study',
    ];
  }

  /**
   * {@inheritdoc}
   *
   * The second row is the other shape a post takes: a publication that links
   * to its External Source and has no Body, which imports without a warning
   * because visitors are sent to that address. Its Category is one every new
   * site has, and its URL Alias is left for Pathauto.
   */
  protected function exampleRows() {
    return [
      $this->exampleRow(),
      [
        'title' => 'Coastal Flooding and Urban Planning',
        'publish date' => '2026-02-12',
        'author' => 'School of the Environment',
        'category' => 'Environment',
        'teaser text' => 'A new article on planning for coastal flood risk.',
        'external source' => 'https://example.com/coastal-flooding',
        'cas login required' => 'No',
      ],
    ];
  }

}
