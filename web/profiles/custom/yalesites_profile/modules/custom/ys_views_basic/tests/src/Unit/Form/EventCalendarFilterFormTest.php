<?php

namespace Drupal\Tests\ys_views_basic\Unit\Form;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_views_basic\Form\EventCalendarFilterForm;
use Drupal\ys_views_basic\Service\EventsCalendar;
use Drupal\ys_views_basic\ViewsBasicManager;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

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

  /**
   * Block params with the search and category filters exposed.
   */
  private const PARAMS = [
    'exposed_filter_options' => [
      'show_search_filter' => 'show_search_filter',
      'show_category_filter' => 'show_category_filter',
    ],
    'category_included_terms' => '',
    'terms_include' => [],
    'terms_exclude' => [],
    'operator' => '+',
    'filters' => ['event_time_period' => 'all'],
  ];

  /**
   * Creates the form under test with mocked services.
   *
   * @param \Drupal\ys_views_basic\Service\EventsCalendar|null $calendar
   *   The calendar service, or NULL for a stub returning an empty month.
   * @param \Symfony\Component\HttpFoundation\Request|null $request
   *   The current request, or NULL for a plain page request.
   *
   * @return \Drupal\ys_views_basic\Form\EventCalendarFilterForm
   *   The form object.
   */
  private function createForm(?EventsCalendar $calendar = NULL, ?Request $request = NULL): EventCalendarFilterForm {
    $manager = $this->createMock(ViewsBasicManager::class);
    $manager->method('getTaxonomyParents')->willReturn(['' => '- Any -', 7 => 'Arts', 16 => 'Science']);
    if (!$calendar) {
      $calendar = $this->createMock(EventsCalendar::class);
      $calendar->method('getCalendar')->willReturn([]);
    }
    $form = new EventCalendarFilterForm($manager, $this->createMock(EntityTypeManagerInterface::class), $calendar);
    $form->setStringTranslation($this->getStringTranslationStub());
    $requestStack = new RequestStack();
    $requestStack->push($request ?? Request::create('/calendar'));
    $form->setRequestStack($requestStack);
    return $form;
  }

  /**
   * Builds the form array from the block params.
   *
   * @param \Drupal\Core\Form\FormState $form_state
   *   The form state.
   * @param \Drupal\ys_views_basic\Service\EventsCalendar|null $calendar
   *   The calendar service, or NULL for a stub returning an empty month.
   *
   * @return array
   *   The built form.
   */
  private function build(FormState $form_state, ?EventsCalendar $calendar = NULL): array {
    return $this->createForm($calendar)->buildForm([], $form_state, json_encode(self::PARAMS), 'wrapper');
  }

  /**
   * The form opts in to live filtering while keeping a working Apply button.
   *
   * @covers ::buildForm
   */
  public function testFormIsWiredForLiveFiltering() {
    $built = $this->build(new FormState());

    $this->assertContains('ys_views_basic/event_calendar_live_filter', $built['#attached']['library']);

    // Apply stays in the markup for the no-JS fallback, hidden when JS runs.
    $submit = $built['filters_container']['actions']['submit'];
    $this->assertSame('submit', $submit['#type']);
    $this->assertContains('js-hide', $submit['#attributes']['class']);
    // Focus must stay on the filter the visitor changed, not jump to Apply.
    $this->assertTrue($submit['#ajax']['disable-refocus']);

    // The calendar's debounced search hook keys off this class.
    $this->assertContains('ys-events-search-input', $built['filters_container']['search']['#attributes']['class']);
  }

  /**
   * Only a no-JS Apply rebuilds; an AJAX submit leaves it to the callback.
   *
   * Without the rebuild, a no-JS submit redirects and drops the filters.
   *
   * @covers ::submitForm
   * @dataProvider submitRebuildProvider
   */
  public function testSubmitRebuildsOnlyWithoutAjax(array $query, bool $rebuild) {
    $form = [];
    $form_state = new FormState();
    $this->createForm(NULL, Request::create('/calendar?' . http_build_query($query), 'POST'))->submitForm($form, $form_state);
    $this->assertSame($rebuild, $form_state->isRebuilding());
  }

  /**
   * Data provider for testSubmitRebuildsOnlyWithoutAjax().
   */
  public static function submitRebuildProvider(): array {
    return [
      'no-JS submit' => [[], TRUE],
      'AJAX submit' => [[FormBuilderInterface::AJAX_FORM_REQUEST => 1], FALSE],
    ];
  }

  /**
   * A no-JS rebuild renders the calendar with the visitor's filters.
   *
   * @covers ::buildForm
   */
  public function testRebuildRendersVisitorFilters() {
    $calendar = $this->createMock(EventsCalendar::class);
    $calendar->expects($this->once())
      ->method('getCalendar')
      ->with($this->anything(), $this->anything(), $this->callback(
        fn(array $filters) => array_values($filters['category_included_terms']) === ['7'] && $filters['search'] === 'art'
      ))
      ->willReturn([]);

    $form_state = new FormState();
    $form_state->setValues(['category_included_terms' => ['7' => '7'], 'search' => ' art ']);
    $form_state->setRebuild();

    $built = $this->build($form_state, $calendar);

    // The visitor's choices stay selected in the rebuilt form.
    $this->assertSame(['7' => '7'], $built['filters_container']['category_included_terms']['#default_value']);
    $this->assertSame(' art ', $built['filters_container']['search']['#default_value']);
  }

  /**
   * The announcement counts distinct events in the displayed month only.
   *
   * @covers ::buildResultsAnnouncement
   * @dataProvider announcementProvider
   */
  public function testResultsAnnouncement(array $grid, string $expected) {
    $language = $this->createMock(LanguageInterface::class);
    $language->method('getId')->willReturn('en');
    $languageManager = $this->createMock(LanguageManagerInterface::class);
    $languageManager->method('getCurrentLanguage')->willReturn($language);
    $container = new ContainerBuilder();
    $container->set('language_manager', $languageManager);
    $container->set('string_translation', $this->getStringTranslationStub());
    \Drupal::setContainer($container);

    $message = (new \ReflectionMethod(EventCalendarFilterForm::class, 'buildResultsAnnouncement'))
      ->invoke($this->createForm(), $grid);
    $this->assertSame($expected, (string) $message);
  }

  /**
   * Data provider for testResultsAnnouncement().
   */
  public static function announcementProvider(): array {
    $cell = fn(string $month, array $urls) => [
      'date' => ['day' => '01', 'month' => $month, 'year' => '2026'],
      'events' => array_map(fn($url) => ['url' => $url], $urls),
    ];
    // Row 0 opens with a September day, the last row ends with a November
    // day; neither counts toward October.
    $grid = fn(array $october) => [
      [$cell('09', ['/sept']), $cell('10', $october[0] ?? [])],
      [$cell('10', $october[1] ?? [])],
      [$cell('10', $october[2] ?? []), $cell('11', ['/nov'])],
    ];
    return [
      'none' => [$grid([]), 'No events found for October 2026.'],
      'one event on two days' => [$grid([['/a'], ['/a']]), '1 event found for October 2026.'],
      'distinct events' => [$grid([['/a'], ['/a', '/b'], ['/c']]), '3 events found for October 2026.'],
    ];
  }

}
