<?php

namespace Drupal\Tests\ys_book\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\Tests\ys_core\Kernel\YsKernelTestBase;

/**
 * Tests that the Content Collections tables declare their column headers.
 *
 * A <th> with no scope leaves a screen reader to infer the column association
 * from position alone, which is the WCAG 2.1 AA 1.3.1 failure this guards
 * against. Both of these headers are built by contrib book, so neither is
 * covered by the sweep over our own '#header' arrays — they are only reachable
 * because ys_book already overrides the controller and alters the form.
 *
 * @group ys_book
 * @group yalesites
 */
class BookTableHeaderScopeTest extends YsKernelTestBase {

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
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // getAllBooks() queries the book table, so the overview cannot be built
    // without it. No books are created: an empty collection list still renders
    // the header, which is the only thing under test here.
    $this->installSchema('book', ['book']);
  }

  /**
   * The Collections overview marks every header cell as a column header.
   *
   * The renamed "Collection" label is asserted first and in the same test: the
   * rename reads each header cell as a string, so promoting those cells to
   * arrays to carry the scope attribute is exactly what would break it. Both
   * live here rather than in two methods because a kernel bootstrap is the
   * expensive part of this suite, and one build proves both.
   */
  public function testOverviewHeadersDeclareColumnScope(): void {
    $build = $this->container->get('class_resolver')
      ->getInstanceFromDefinition('Drupal\ys_book\Controller\YsBookController')
      ->adminOverview();

    $labels = array_map(
      fn ($cell) => (string) (is_array($cell) ? $cell['data'] : $cell),
      $build['#header']
    );
    $this->assertSame(['Collection', 'Operations'], $labels);

    // renderInIsolation() takes its render array by reference, so the build
    // has to be a variable rather than a call.
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);

    $document = new \DOMDocument();
    $document->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOERROR);
    $cells = $document->getElementsByTagName('th');

    // Contrib builds two columns. Asserting the count first stops the loop
    // below from passing vacuously if the table ever stops rendering.
    $this->assertSame(2, $cells->count(), 'The overview renders both headers.');

    foreach ($cells as $cell) {
      $this->assertSame(
        'col',
        $cell->getAttribute('scope'),
        sprintf('Header "%s" should declare scope="col".', trim($cell->textContent))
      );
    }
  }

  /**
   * The collection outline form marks every header cell as a column header.
   *
   * Driven through the alter rather than the real form, which would need a
   * saved book node: the alter is the only part of that header ys_book owns,
   * and it is what has to add the attribute.
   */
  public function testOutlineFormHeadersDeclareColumnScope(): void {
    // The four plain-string cells contrib's bookAdminTable() builds.
    $form = [
      '#title' => 'Re-order book outline',
      '#submit' => [],
      'save' => ['#value' => 'Save book pages'],
      'table' => [
        '#type' => 'table',
        '#header' => ['Title', 'Weight', 'Hidden Book Information', 'Operations'],
      ],
    ];
    $form_state = new FormState();
    ys_book_form_book_admin_edit_alter($form, $form_state, 'book_admin_edit');

    $header = $form['table']['#header'];
    $this->assertCount(4, $header, 'The alter leaves the column count alone.');

    foreach ($header as $index => $cell) {
      $this->assertIsArray($cell, "Header cell $index carries attributes.");
      $this->assertSame('col', $cell['scope'] ?? NULL, "Header cell $index declares scope=\"col\".");
    }

    // The rename this alter already did must survive carrying the attribute.
    $this->assertSame('Menu link title', (string) $header[0]['data']);
  }

}
