<?php

namespace Drupal\Tests\ys_layouts\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\ys_layouts\Theme\ExposedFormSuggestions;

/**
 * Tests that gin_lb template suggestions are dropped for View block filters.
 *
 * On layout_builder.remove_block gin_lb adds __gin_lb suggestions to every
 * form, which outrank atomic's form--views--*.html.twig and strip the
 * ys-filter-form classes (yalesites-org/YaleSites-Internal#2310).
 *
 * @group yalesites
 * @group ys_layouts
 */
class ExposedFormSuggestionsTest extends UnitTestCase {

  /**
   * The suggestions gin_lb produces for a View block filter form.
   */
  protected const GIN_LB_SUGGESTIONS = [
    'form__views_exposed_form',
    'form__views_exposed_form__views_basic_scaffold',
    'form__gin_lb',
    'form__views_exposed_form__gin_lb',
    'form__views_exposed_form__views_basic_scaffold__gin_lb',
  ];

  /**
   * Tests the gin_lb suggestions are removed for a site exposed form.
   */
  public function testGinLbSuggestionsRemovedForExposedForm(): void {
    $result = ExposedFormSuggestions::filter(
      self::GIN_LB_SUGGESTIONS,
      ['#form_id' => 'views_exposed_form', '#id' => 'views-exposed-form-views-basic-scaffold-block-1']
    );
    $this->assertSame([
      'form__views_exposed_form',
      'form__views_exposed_form__views_basic_scaffold',
    ], $result);
  }

  /**
   * Tests the media library filter form keeps its gin_lb styling.
   */
  public function testMediaLibraryFormKeepsGinLbSuggestions(): void {
    $result = ExposedFormSuggestions::filter(
      self::GIN_LB_SUGGESTIONS,
      ['#form_id' => 'views_exposed_form', '#id' => 'views-exposed-form-media-library-widget']
    );
    $this->assertSame(self::GIN_LB_SUGGESTIONS, $result);
  }

  /**
   * Tests the media library Table view filter form keeps its gin_lb styling.
   */
  public function testMediaLibraryTableFormKeepsGinLbSuggestions(): void {
    $result = ExposedFormSuggestions::filter(
      self::GIN_LB_SUGGESTIONS,
      ['#form_id' => 'views_exposed_form', '#id' => 'views-exposed-form-media-library-widget-table']
    );
    $this->assertSame(self::GIN_LB_SUGGESTIONS, $result);
  }

  /**
   * Tests other forms are untouched.
   */
  public function testOtherFormsUntouched(): void {
    $result = ExposedFormSuggestions::filter(
      ['form__gin_lb'],
      ['#form_id' => 'layout_builder_remove_block']
    );
    $this->assertSame(['form__gin_lb'], $result);
  }

}
