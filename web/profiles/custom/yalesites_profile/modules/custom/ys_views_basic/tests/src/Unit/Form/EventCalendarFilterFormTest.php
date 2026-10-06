<?php

namespace Drupal\Tests\ys_views_basic\Unit\Form;

use Drupal\Tests\UnitTestCase;
use Drupal\ys_views_basic\Form\EventCalendarFilterForm;

/**
 * Unit tests for the EventCalendarFilterForm.
 *
 * @coversDefaultClass \Drupal\ys_views_basic\Form\EventCalendarFilterForm
 * @group ys_views_basic
 */
class EventCalendarFilterFormTest extends UnitTestCase {

  /**
   * First load ignores the block's parent terms as visitor selections.
   *
   * The block params hold parent terms, which only limit the dropdown
   * choices. They must not become filters on the first render.
   *
   * @covers ::getFiltersFromParams
   */
  public function testFirstLoadIgnoresParentTerms() {
    $form = $this->getMockBuilder(EventCalendarFilterForm::class)
      ->disableOriginalConstructor()
      ->onlyMethods([])
      ->getMock();

    $filters = (new \ReflectionMethod($form, 'getFiltersFromParams'))->invoke($form, [
      'category_included_terms' => '103',
      'audience_included_terms' => '204',
      'custom_vocab_included_terms' => '305',
      'terms_include' => [7],
      'terms_exclude' => [8],
      'operator' => '+',
      'filters' => ['event_time_period' => 'future'],
    ]);

    $this->assertSame([], $filters['category_included_terms']);
    $this->assertSame([], $filters['audience_included_terms']);
    $this->assertSame([], $filters['custom_vocab_included_terms']);
    $this->assertSame([7], $filters['terms_include']);
    $this->assertSame([8], $filters['terms_exclude']);
    $this->assertSame('+', $filters['term_operator']);
    $this->assertSame('future', $filters['event_time_period']);
  }

}
