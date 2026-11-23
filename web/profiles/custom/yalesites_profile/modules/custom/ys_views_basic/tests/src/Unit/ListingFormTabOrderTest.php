<?php

namespace Drupal\Tests\ys_views_basic\Unit;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the tab order of the listing block form.
 *
 * Editors decide what shows (tags, sorting, pinned) before how results look.
 *
 * @group ys_views_basic
 * @group yalesites
 */
class ListingFormTabOrderTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $container = new ContainerBuilder();
    $container->set('string_translation', $this->getStringTranslationStub());
    \Drupal::setContainer($container);
    require_once dirname(__DIR__, 3) . '/ys_views_basic.module';
  }

  /**
   * Tabs read General, Tags, Results, then Display options.
   */
  public function testTabWeights(): void {
    $element = [
      'group_user_selection' => [
        'entity_and_view_mode' => [],
        'filter_and_sort' => [],
        'options' => [],
      ],
    ];
    $form_state = $this->createMock(FormStateInterface::class);
    $complete = [];
    $result = _ys_views_basic_listing_form_tabs($element, $form_state, $complete);

    $weights = [
      'general' => $result['group_listing_general']['#weight'],
      'filter_and_sort' => $result['group_user_selection']['filter_and_sort']['#weight'],
      'entity_and_view_mode' => $result['group_user_selection']['entity_and_view_mode']['#weight'],
      'options' => $result['group_user_selection']['options']['#weight'],
    ];
    asort($weights);
    $this->assertSame(
      ['general', 'filter_and_sort', 'entity_and_view_mode', 'options'],
      array_keys($weights)
    );
  }

}
