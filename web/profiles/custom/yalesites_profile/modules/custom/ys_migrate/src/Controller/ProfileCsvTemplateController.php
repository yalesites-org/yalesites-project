<?php

namespace Drupal\ys_migrate\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Controller for profile CSV template downloads.
 */
class ProfileCsvTemplateController extends ControllerBase {

  /**
   * Downloads a CSV template for profile imports.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The CSV file response.
   */
  public function downloadTemplate() {
    $headers = [
      'Display Name',
      'First Name',
      'Last Name',
      'Honorific Prefix',
      'Pronouns',
      'Position',
      'Subtitle',
      'Department',
      'Email',
      'Telephone',
      'Address',
      'Teaser Title',
      'Teaser Text',
      'Affiliation',
      'Audience',
      'Tags',
      'Custom Vocabulary',
    ];

    $sample_data = [
      [
        'Dr. Jane Smith',
        'Jane',
        'Smith',
        'Dr.',
        'she/her',
        'Associate Professor',
        'Director of Graduate Studies',
        'Computer Science',
        'jane.smith@yale.edu',
        '(203) 432-1234',
        '51 Prospect Street, New Haven, CT 06511',
        'Computer Science Professor',
        'Leading researcher in artificial intelligence and machine learning.',
        'Faculty, Computer Science',
        'Students, Faculty',
        'AI, Machine Learning, Research',
        'Computer Science, Engineering',
      ],
      [
        'Prof. John Doe',
        'John',
        'Doe',
        'Prof.',
        'he/him',
        'Professor',
        'Department Chair',
        'Mathematics',
        'john.doe@yale.edu',
        '(203) 432-5678',
        '10 Hillhouse Avenue, New Haven, CT 06511',
        'Mathematics Professor',
        'Expert in number theory and mathematical analysis.',
        'Faculty, Mathematics',
        'Students, Faculty, Staff',
        'Mathematics, Number Theory',
        'Mathematics, Science',
      ],
    ];

    $csv_content = $this->arrayToCsv(array_merge([$headers], $sample_data));
    $response = new Response($csv_content);
    $response->headers->set('Content-Type', 'text/csv');
    $response->headers->set('Content-Disposition', 'attachment; filename="profile_import_template.csv"');

    return $response;
  }

  /**
   * Converts an array to CSV format.
   *
   * @param array $data
   *   The data array.
   *
   * @return string
   *   The CSV content.
   */
  protected function arrayToCsv(array $data) {
    $output = fopen('php://temp', 'r+');

    foreach ($data as $row) {
      fputcsv($output, $row);
    }

    rewind($output);
    $csv = stream_get_contents($output);
    fclose($output);

    return $csv;
  }

}
