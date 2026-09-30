<?php

namespace Drupal\Tests\ys_views_basic\Unit;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\views\Plugin\views\filter\FilterPluginBase;
use Drupal\views\ViewExecutable;

require_once __DIR__ . '/../../../ys_views_basic.module';

/**
 * Tests the empty exposed taxonomy filter message on listing views.
 *
 * @group ys_views_basic
 * @group yalesites
 */
class EmptyExposedFilterMessageTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $container = new ContainerBuilder();
    $container->set('string_translation', $this->getStringTranslationStub());
    \Drupal::setContainer($container);
  }

  /**
   * Runs the alter for a view with one filter and one form element.
   *
   * @param string $view_id
   *   The view id.
   * @param string $plugin_id
   *   The filter plugin id.
   * @param array $options
   *   The select's options.
   * @param int|null $weight
   *   The select's #weight, or NULL for none.
   *
   * @return array
   *   The altered form.
   */
  protected function alter(string $view_id, string $plugin_id, array $options, ?int $weight = 3): array {
    $handler = $this->createMock(FilterPluginBase::class);
    $handler->method('getPluginId')->willReturn($plugin_id);
    $handler->method('isExposed')->willReturn(TRUE);
    $handler->options = ['expose' => ['identifier' => 'field_x', 'label' => 'Cat']];

    $view = $this->createMock(ViewExecutable::class);
    $view->method('id')->willReturn($view_id);
    $view->filter = ['field_x' => $handler];

    $form_state = $this->createMock(FormStateInterface::class);
    $form_state->method('get')->with('view')->willReturn($view);

    $form = [
      'field_x' => [
        '#type' => 'select',
        '#title' => 'Cat',
        '#options' => $options,
      ],
    ];
    if ($weight !== NULL) {
      $form['field_x']['#weight'] = $weight;
    }
    ys_views_basic_form_views_exposed_form_alter($form, $form_state);
    return $form;
  }

  /**
   * A taxonomy select with no choices is replaced by the message.
   */
  public function testZeroChoiceSelectIsReplaced() {
    $form = $this->alter('views_basic_scaffold', 'taxonomy_index_tid', []);
    $this->assertSame('item', $form['field_x']['#type']);
    $this->assertSame('Cat', (string) $form['field_x']['#title']);
    $this->assertSame(3, $form['field_x']['#weight']);
    $this->assertSame('There are no terms available to filter by.', (string) $form['field_x']['#markup']);
  }

  /**
   * An unweighted select gets no #weight, so it keeps its place in the form.
   */
  public function testUnweightedSelectStaysUnweighted() {
    $form = $this->alter('views_basic_scaffold', 'taxonomy_index_tid', [], NULL);
    $this->assertSame('item', $form['field_x']['#type']);
    $this->assertArrayNotHasKey('#weight', $form['field_x']);
  }

  /**
   * A select holding only the All option counts as empty.
   */
  public function testAllOnlySelectIsReplaced() {
    $form = $this->alter('views_basic_scaffold_events', 'taxonomy_index_tid', ['All' => '- Any -']);
    $this->assertSame('item', $form['field_x']['#type']);
  }

  /**
   * A select with choices is left alone.
   */
  public function testSelectWithChoicesIsUntouched() {
    $form = $this->alter('views_basic_scaffold', 'taxonomy_index_tid', ['All' => '- Any -', 5 => 'Five']);
    $this->assertSame('select', $form['field_x']['#type']);
  }

  /**
   * Views outside the listing family are left alone.
   */
  public function testOtherViewIsUntouched() {
    $form = $this->alter('frontpage', 'taxonomy_index_tid', []);
    $this->assertSame('select', $form['field_x']['#type']);
  }

  /**
   * Non-taxonomy filters are left alone.
   */
  public function testNonTaxonomyFilterIsUntouched() {
    $form = $this->alter('views_basic_scaffold', 'numeric', []);
    $this->assertSame('select', $form['field_x']['#type']);
  }

}
