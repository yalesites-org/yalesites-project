<?php

namespace Drupal\Tests\ys_taxonomy_manager\Kernel;

use Drupal\Tests\ys_core\Kernel\YsKernelTestBase;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\user\Entity\User;
use Drupal\ys_core\TaxonomyVocabularyManager;
use Drupal\ys_taxonomy_manager\Controller\YsTaxonomyManagerMainController;

/**
 * Tests that the Taxonomy Manager tables declare their column headers.
 *
 * A <th> with no scope leaves a screen reader to infer the column association
 * from position alone, which is the WCAG 2.1 AA 1.3.1 failure this guards
 * against. Both of this page's tables share one header definition, so one
 * omission would silently un-label both.
 *
 * @coversDefaultClass \Drupal\ys_taxonomy_manager\Controller\YsTaxonomyManagerMainController
 * @group ys_taxonomy_manager
 * @group yalesites
 */
class VocabularyTableHeaderScopeTest extends YsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'node',
    'taxonomy',
    'taxonomy_manager',
    'ys_taxonomy_manager',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');

    // One vocabulary on each side of the YaleSites/Localist split, so both
    // tables the controller can build are exercised.
    Vocabulary::create(['vid' => 'tags', 'name' => 'Tags'])->save();
    Vocabulary::create(['vid' => 'localist_places', 'name' => 'Places'])->save();

    // Rows are filtered by term-create access and the default account is
    // anonymous, so without a privileged user the controller renders no table
    // at all. This is the first user saved, so it is uid 1, which a kernel
    // test outside core/ treats as the super user — no role grant needed.
    $user = User::create(['name' => 'tester', 'status' => 1]);
    $user->save();
    $this->container->get('current_user')->setAccount($user);
  }

  /**
   * Every header cell the controller renders is marked up as a column header.
   *
   * @covers ::listVocabularies
   */
  public function testVocabularyTableHeadersDeclareColumnScope(): void {
    // Built by hand rather than from the container: the controller's only
    // YaleSites dependency needs nothing but the two entity managers, so the
    // ys_core module (which ys_taxonomy_manager does not declare) stays out of
    // the kernel.
    $entity_type_manager = $this->container->get('entity_type.manager');
    $entity_field_manager = $this->container->get('entity_field.manager');
    $controller = new YsTaxonomyManagerMainController(
      $entity_type_manager,
      $entity_field_manager,
      new TaxonomyVocabularyManager($entity_type_manager, $entity_field_manager)
    );
    $build = $controller->listVocabularies();
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);

    $document = new \DOMDocument();
    $document->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOERROR);
    $cells = $document->getElementsByTagName('th');

    // Two tables, two columns each: anything less means the fixture stopped
    // rendering the tables and the scope assertion below proves nothing.
    $this->assertSame(4, $cells->count(), 'Both vocabulary tables render their headers.');

    foreach ($cells as $cell) {
      $this->assertSame(
        'col',
        $cell->getAttribute('scope'),
        sprintf('Header "%s" should declare scope="col".', trim($cell->textContent))
      );
    }
  }

}
