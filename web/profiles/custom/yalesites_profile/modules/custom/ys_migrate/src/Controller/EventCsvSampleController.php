<?php

namespace Drupal\ys_migrate\Controller;

/**
 * Streams a downloadable skeleton CSV for the Event bulk importer.
 */
class EventCsvSampleController extends CsvSampleControllerBase {

  /**
   * {@inheritdoc}
   */
  protected function columns() {
    return $this->csvValidator->getExpectedEventColumns();
  }

  /**
   * {@inheritdoc}
   */
  protected function filename() {
    return 'event-import-sample.csv';
  }

  /**
   * {@inheritdoc}
   *
   * Values are illustrative only. The taxonomy names must match terms that
   * exist on the site doing the import, so the row as written may well be
   * rejected there; that rejection is the check working.
   *
   * All Day and Stream URL are left empty: the row is an in-person event
   * with a start time, which is what an empty All Day means.
   */
  protected function exampleRow() {
    return [
      'title' => 'Climate Research Symposium',
      'start' => '2026-03-04 14:00',
      'end' => '2026-03-04 16:30',
      'all day' => '',
      'description' => '<p>Researchers from the School of the Environment share ten years of local climate data.</p><h2>Schedule</h2><ul><li>2:00 pm: Talks</li><li>3:30 pm: Panel discussion</li></ul>',
      'location details' => "Kroon Hall, Burke Auditorium\n195 Prospect Street, New Haven, CT",
      'event experience' => 'In-person',
      'event website' => 'https://example.yale.edu/climate-symposium',
      'event website text' => 'Register',
      'ticket cost' => 'Free',
      'ticket registration url' => 'https://example.yale.edu/climate-symposium/register',
      'stream url' => '',
      'event category' => 'Lecture',
      'tags' => 'Research, Climate',
      'audience' => 'Faculty, Staff',
      'custom vocab' => 'Sustainability',
      'teaser title' => 'Climate Research Symposium',
      'teaser text' => 'Ten years of local climate data, and what they show.',
      'cas login required' => 'No',
      'url alias' => '/events/climate-research-symposium',
      'event id' => '1234',
    ];
  }

}
