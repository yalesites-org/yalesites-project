<?php

namespace Drupal\Tests\ys_views_basic\Unit;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_views_basic\Plugin\Field\FieldWidget\EventViewWidget;
use Drupal\ys_views_basic\Plugin\Field\FieldWidget\PageViewWidget;
use Drupal\ys_views_basic\Plugin\Field\FieldWidget\PostViewWidget;
use Drupal\ys_views_basic\Plugin\Field\FieldWidget\ProfileViewWidget;
use Drupal\ys_views_basic\Plugin\Field\FieldWidget\ResourceViewWidget;
use Drupal\ys_views_basic\Plugin\Field\FieldWidget\ViewsBasicWidgetBase;
use Drupal\ys_views_basic\ViewsBasicManager;

/**
 * Tests the keyword search scope setting (#706).
 *
 * The search used to match only the title and teaser fields, so a result card
 * could show a visitor's keyword (a profile's department, say) that the search
 * never looked at. The scope ties the searched fields to what the result card
 * renders, or, as an advanced option, to every searchable text field of the
 * content type. These tests pin the field sets per content type and scope, and
 * the controls that sit under "Show search".
 *
 * @coversDefaultClass \Drupal\ys_views_basic\ViewsBasicManager
 *
 * @group ys_views_basic
 * @group yalesites
 */
class SearchScopeTest extends UnitTestCase {

  /**
   * The fields every scope searches, whatever the content type.
   */
  const ALWAYS = ['title', 'field_teaser_title', 'field_teaser_text'];

  /**
   * Fake selectors, in the shape getFormSelectors() returns.
   */
  const SELECTORS = [
    'show_search_filter_selector' => ':input[name="show_search_filter"]',
    'search_scope_selector' => ':input[name="search_scope"]',
    'profile_field_options_show_department_selector' => ':input[name="profile_field_options[show_department]"]',
    'profile_field_options_show_pronouns_selector' => ':input[name="profile_field_options[show_pronouns]"]',
    'profile_field_options_show_email_selector' => ':input[name="profile_field_options[show_email]"]',
    'post_field_options_show_eyebrow_selector' => ':input[name="post_field_options[show_eyebrow]"]',
  ];

  /**
   * Shown scope adds only what the profile card renders.
   *
   * @covers ::searchFieldsFor
   */
  public function testProfileShownFollowsTheDisplayToggles() {
    $base = array_merge(self::ALWAYS, ['field_position', 'field_subtitle']);
    $this->assertSame($base, ViewsBasicManager::searchFieldsFor('profile', 'shown', 'card', []));

    $department = ['profile_field_options' => ['show_department' => 'show_department', 'show_phone' => 'show_phone']];
    $this->assertSame(
      array_merge($base, ['field_department']),
      ViewsBasicManager::searchFieldsFor('profile', 'shown', 'card', $department),
      'Department is searched once it is shown; the phone is never searched.'
    );

    $both = ['profile_field_options' => ['show_department' => 'show_department', 'show_pronouns' => 'show_pronouns']];
    $this->assertSame(
      array_merge($base, ['field_department', 'field_pronouns']),
      ViewsBasicManager::searchFieldsFor('profile', 'shown', 'card', $both)
    );

    // An unchecked option is stored as 0 by #type checkboxes.
    $off = ['profile_field_options' => ['show_department' => 0, 'show_pronouns' => 0]];
    $this->assertSame($base, ViewsBasicManager::searchFieldsFor('profile', 'shown', 'card', $off));

    $email = ['profile_field_options' => ['show_email' => 'show_email']];
    $this->assertSame(
      array_merge($base, ['field_email']),
      ViewsBasicManager::searchFieldsFor('profile', 'shown', 'list_item', $email),
      'A shown email is searched.'
    );
  }

  /**
   * A condensed profile renders only the title and position.
   *
   * People options left over from an earlier design are ignored, since the
   * condensed template never renders those fields.
   *
   * @covers ::searchFieldsFor
   */
  public function testProfileCondensedShownSearchesTitleAndPositionOnly() {
    $expected = array_merge(self::ALWAYS, ['field_position']);
    $this->assertSame($expected, ViewsBasicManager::searchFieldsFor('profile', 'shown', 'condensed', []));
    $stale = [
      'profile_field_options' => [
        'show_department' => 'show_department',
        'show_email' => 'show_email',
        'show_pronouns' => 'show_pronouns',
      ],
    ];
    $this->assertSame($expected, ViewsBasicManager::searchFieldsFor('profile', 'shown', 'condensed', $stale));
    $this->assertSame(
      ViewsBasicManager::searchFieldsFor('profile', 'all', 'card', []),
      ViewsBasicManager::searchFieldsFor('profile', 'all', 'condensed', []),
      'The all scope does not depend on the design.'
    );
  }

  /**
   * All scope adds every searchable profile text field.
   *
   * @covers ::searchFieldsFor
   */
  public function testProfileAllIgnoresTheDisplayToggles() {
    $expected = array_merge(self::ALWAYS, [
      'field_position',
      'field_subtitle',
      'field_department',
      'field_pronouns',
      'field_email',
    ]);
    $this->assertSame($expected, ViewsBasicManager::searchFieldsFor('profile', 'all', 'card', []));
    $this->assertSame($expected, ViewsBasicManager::searchFieldsFor('profile', 'all', 'card', ['profile_field_options' => ['show_department' => 'show_department']]));
  }

  /**
   * Posts search the lead-in only when it is shown, unless the scope is all.
   *
   * @covers ::searchFieldsFor
   */
  public function testPostFields() {
    $this->assertSame(self::ALWAYS, ViewsBasicManager::searchFieldsFor('post', 'shown', 'card', []));
    $this->assertSame(
      array_merge(self::ALWAYS, ['field_teaser_lead_in']),
      ViewsBasicManager::searchFieldsFor('post', 'shown', 'card', ['post_field_options' => ['show_eyebrow' => 'show_eyebrow']])
    );
    $this->assertSame(
      array_merge(self::ALWAYS, ['field_teaser_lead_in', 'field_author']),
      ViewsBasicManager::searchFieldsFor('post', 'all', 'card', [])
    );
  }

  /**
   * Events add their description and location fields only in the all scope.
   *
   * @covers ::searchFieldsFor
   */
  public function testEventFields() {
    $this->assertSame(self::ALWAYS, ViewsBasicManager::searchFieldsFor('event', 'shown', 'card', []));
    $this->assertSame(
      array_merge(self::ALWAYS, ['field_event_description', 'field_event_room', 'field_event_location_details']),
      ViewsBasicManager::searchFieldsFor('event', 'all', 'card', [])
    );
  }

  /**
   * Pages search the same fields in either scope.
   *
   * @covers ::searchFieldsFor
   */
  public function testPageFields() {
    $this->assertSame(self::ALWAYS, ViewsBasicManager::searchFieldsFor('page', 'shown', 'card', []));
    $this->assertSame(self::ALWAYS, ViewsBasicManager::searchFieldsFor('page', 'all', 'card', []));
  }

  /**
   * An unknown scope searches what the shown scope searches.
   *
   * @covers ::searchFieldsFor
   * @covers ::normalizeSearchScope
   */
  public function testUnknownScopeFallsBackToShown() {
    $this->assertSame(
      ViewsBasicManager::searchFieldsFor('profile', 'shown', 'card', []),
      ViewsBasicManager::searchFieldsFor('profile', 'everything', 'card', [])
    );
    $this->assertSame('shown', ViewsBasicManager::normalizeSearchScope(NULL));
    $this->assertSame('shown', ViewsBasicManager::normalizeSearchScope(''));
    $this->assertSame('shown', ViewsBasicManager::normalizeSearchScope('everything'));
    $this->assertSame('shown', ViewsBasicManager::normalizeSearchScope(['all']));
    $this->assertSame('all', ViewsBasicManager::normalizeSearchScope('all'));
  }

  /**
   * The scope is a choice only where the scopes can search different fields.
   *
   * Profiles show every field the all scope searches when every People option
   * is on, but each of those can be off, so the choice still matters.
   *
   * @covers ::offersSearchScope
   */
  public function testOffersSearchScope() {
    $this->assertTrue(ViewsBasicManager::offersSearchScope('profile'));
    $this->assertTrue(ViewsBasicManager::offersSearchScope('post'));
    $this->assertTrue(ViewsBasicManager::offersSearchScope('event'));
    $this->assertFalse(ViewsBasicManager::offersSearchScope('page'));
    $this->assertFalse(ViewsBasicManager::offersSearchScope('resource'), 'Resources keep their own search-field picker.');
  }

  /**
   * Builds a widget of the given class bound to the given bundle.
   */
  private function widget(string $class, string $bundle, ?string $stored_scope = NULL): ViewsBasicWidgetBase {
    $manager = $this->createMock(ViewsBasicManager::class);
    $manager->method('getDefaultParamValue')->willReturnCallback(
      fn($type) => $type === 'search_scope' ? ($stored_scope ?? 'shown') : []
    );
    $field_definition = $this->createMock(FieldDefinitionInterface::class);
    $field_definition->method('getTargetBundle')->willReturn($bundle);
    $widget = new $class(
      'widget',
      [],
      $field_definition,
      [],
      [],
      $manager,
      $this->createMock(EntityTypeManagerInterface::class),
      $this->getConfigFactoryStub(['ys_core.site' => ['font_pairing' => 'yalenew']]),
    );
    $widget->setStringTranslation($this->getStringTranslationStub());
    return $widget;
  }

  /**
   * Builds field items whose delta 0 carries the given params.
   */
  private function items(?string $params): FieldItemListInterface {
    $item = (object) ['params' => $params];
    $items = $this->createMock(FieldItemListInterface::class);
    $items->method('offsetGet')->willReturn($item);
    return $items;
  }

  /**
   * Invokes a protected method on the widget.
   */
  private function invoke(object $object, string $method, array $args = []) {
    $ref = new \ReflectionMethod($object, $method);
    $ref->setAccessible(TRUE);
    return $ref->invokeArgs($object, $args);
  }

  /**
   * Builds the entity-specific options, then the search scope controls.
   *
   * The order matches ::formElement(): the scope controls look for the
   * per-type display checkboxes the entity-specific options added.
   */
  private function build(ViewsBasicWidgetBase $widget, ?string $params = NULL): array {
    $form = [];
    $items = $this->items($params);
    $this->invoke($widget, 'buildEntitySpecificOptions', [&$form, $items, 0]);
    $this->invoke($widget, 'buildSearchScopeControls', [&$form, $items, 0, self::SELECTORS]);
    return $form['group_user_selection']['entity_and_view_mode'] ?? [];
  }

  /**
   * Returns the "Included in search" list items, keyed by field name.
   */
  private function listItems(array $group): array {
    $items = [];
    foreach ($group['search_fields_included']['#items'] as $field => $item) {
      $states = isset($item['#wrapper_attributes']['data-drupal-states'])
        ? Json::decode($item['#wrapper_attributes']['data-drupal-states'])
        : NULL;
      $items[$field] = ['label' => (string) $item['#markup'], 'states' => $states];
    }
    return $items;
  }

  /**
   * The profile form offers the scope, its warning and the field list.
   *
   * @covers \Drupal\ys_views_basic\Plugin\Field\FieldWidget\ViewsBasicWidgetBase::buildSearchScopeControls
   */
  public function testProfileBuildsScopeControls() {
    $group = $this->build($this->widget(ProfileViewWidget::class, 'profile_card'));

    $radios = $group['search_scope'];
    $this->assertSame('radios', $radios['#type']);
    $this->assertSame('Which content fields are included in keyword search?', (string) $radios['#title']);
    $this->assertSame(['shown' => 'Fields shown in results', 'all' => 'All searchable fields'], array_map('strval', $radios['#options']));
    $this->assertSame('shown', $radios['#default_value']);
    $this->assertStringNotContainsString("\u{2014}", (string) $radios['#description']);
    $this->assertSame(
      ['disabled' => [self::SELECTORS['show_search_filter_selector'] => ['checked' => FALSE]]],
      $radios['#states'],
      'The scope is disabled while search is off, like the other filter settings.'
    );

    $warning = $group['search_scope_warning'];
    $this->assertSame(
      [
        'visible' => [
          self::SELECTORS['search_scope_selector'] => ['value' => 'all'],
          self::SELECTORS['show_search_filter_selector'] => ['checked' => TRUE],
        ],
      ],
      $warning['#states'],
      'The warning shows only while search is on and the all scope is picked.'
    );
    $this->assertContains('messages--warning', $warning['#attributes']['class']);
    $this->assertSame('Search hidden content', (string) $warning['heading']['#value']);
    $this->assertStringStartsWith('Searching hidden content may return results', (string) $warning['text']['#value']);

    $this->assertSame('Included in search', (string) $group['search_fields_included']['#title']);
    $this->assertContains('core/drupal.states', $group['search_fields_included']['#attached']['library']);
    $items = $this->listItems($group);
    $this->assertSame(
      [
        'title',
        'field_teaser_title',
        'field_teaser_text',
        'field_position',
        'field_subtitle',
        'field_department',
        'field_pronouns',
        'field_email',
      ],
      array_keys($items)
    );
    $this->assertSame(
      ['Title', 'Teaser Title', 'Teaser Text', 'Position', 'Subtitle', 'Department', 'Pronouns', 'Email'],
      array_column($items, 'label')
    );
    // Searched in either scope on a card: no condition.
    foreach (['title', 'field_teaser_title', 'field_teaser_text', 'field_position', 'field_subtitle'] as $field) {
      $this->assertNull($items[$field]['states'], "$field is always listed.");
    }
    // Searched once shown, or with the all scope.
    $this->assertSame([
      'visible' => [
        [self::SELECTORS['profile_field_options_show_department_selector'] => ['checked' => TRUE]],
        'or',
        [self::SELECTORS['search_scope_selector'] => ['value' => 'all']],
      ],
    ], $items['field_department']['states']);
    $this->assertSame(
      self::SELECTORS['profile_field_options_show_pronouns_selector'],
      array_key_first($items['field_pronouns']['states']['visible'][0])
    );
    // Searched once shown, like the department.
    $this->assertSame(
      self::SELECTORS['profile_field_options_show_email_selector'],
      array_key_first($items['field_email']['states']['visible'][0])
    );
  }

  /**
   * A conditional list item can be shown and hidden on its own.
   *
   * The states library toggles the closest form wrapper of the element that
   * carries the states. Without its own wrapper class, a list item would
   * toggle the whole search settings body instead.
   *
   * @covers \Drupal\ys_views_basic\Plugin\Field\FieldWidget\ViewsBasicWidgetBase::buildSearchScopeControls
   */
  public function testConditionalListItemsAreTheirOwnStateWrapper() {
    $group = $this->build($this->widget(ProfileViewWidget::class, 'profile_card'));
    $items = $group['search_fields_included']['#items'];
    $this->assertContains('js-form-wrapper', $items['field_email']['#wrapper_attributes']['class']);
    $this->assertContains('js-form-wrapper', $items['field_department']['#wrapper_attributes']['class']);
  }

  /**
   * A stored scope is the radios' default.
   *
   * @covers \Drupal\ys_views_basic\Plugin\Field\FieldWidget\ViewsBasicWidgetBase::buildSearchScopeControls
   */
  public function testStoredScopeIsTheDefault() {
    $group = $this->build($this->widget(ProfileViewWidget::class, 'profile_card', 'all'), '{"search_scope":"all"}');
    $this->assertSame('all', $group['search_scope']['#default_value']);
  }

  /**
   * Condensed profiles list only the title and position as always searched.
   *
   * The condensed design renders neither the subtitle nor the People fields,
   * so those are listed only for the all scope.
   *
   * @covers \Drupal\ys_views_basic\Plugin\Field\FieldWidget\ViewsBasicWidgetBase::buildSearchScopeControls
   */
  public function testProfileCondensedListsOnlyPositionAsShown() {
    $items = $this->listItems($this->build($this->widget(ProfileViewWidget::class, 'profile_condensed')));
    foreach (['title', 'field_teaser_title', 'field_teaser_text', 'field_position'] as $field) {
      $this->assertNull($items[$field]['states'], "$field is always listed.");
    }
    $all_only = ['visible' => [self::SELECTORS['search_scope_selector'] => ['value' => 'all']]];
    foreach (['field_subtitle', 'field_department', 'field_pronouns', 'field_email'] as $field) {
      $this->assertSame($all_only, $items[$field]['states'], "$field is listed only for the all scope.");
    }
  }

  /**
   * The post lead-in is listed when shown or with the all scope.
   *
   * Category is searched in either scope, so it is always listed.
   *
   * @covers \Drupal\ys_views_basic\Plugin\Field\FieldWidget\ViewsBasicWidgetBase::buildSearchScopeControls
   */
  public function testPostListsLeadInAndAuthor() {
    $group = $this->build($this->widget(PostViewWidget::class, 'post_card'));
    $this->assertArrayHasKey('search_scope', $group);
    $items = $this->listItems($group);
    $this->assertSame(['Title', 'Teaser Title', 'Teaser Text', 'Category', 'Teaser Lead-in', 'Author'], array_column($items, 'label'));
    $this->assertNull($items['field_category']['states'], 'Category is listed in either scope.');
    $this->assertSame(
      self::SELECTORS['post_field_options_show_eyebrow_selector'],
      array_key_first($items['field_teaser_lead_in']['states']['visible'][0])
    );
    $this->assertSame(['visible' => [self::SELECTORS['search_scope_selector'] => ['value' => 'all']]], $items['field_author']['states']);
  }

  /**
   * Events list their category in either scope, and the rest for all.
   *
   * @covers \Drupal\ys_views_basic\Plugin\Field\FieldWidget\ViewsBasicWidgetBase::buildSearchScopeControls
   */
  public function testEventListsCategory() {
    $group = $this->build($this->widget(EventViewWidget::class, 'event_card'));
    $this->assertArrayHasKey('search_scope', $group);
    $items = $this->listItems($group);
    $this->assertSame(
      ['Title', 'Teaser Title', 'Teaser Text', 'Category', 'Description', 'Room', 'Location Details'],
      array_column($items, 'label')
    );
    $this->assertNull($items['field_category']['states'], 'Category is listed in either scope.');
    $this->assertSame(['visible' => [self::SELECTORS['search_scope_selector'] => ['value' => 'all']]], $items['field_event_room']['states']);
  }

  /**
   * Only posts and events search their category term names.
   *
   * Tags and affiliation are never searched, and pages and profiles keep
   * their category out of the search.
   *
   * @covers ::searchesCategories
   */
  public function testSearchesCategories() {
    $this->assertTrue(ViewsBasicManager::searchesCategories('post'));
    $this->assertTrue(ViewsBasicManager::searchesCategories('event'));
    $this->assertFalse(ViewsBasicManager::searchesCategories('page'));
    $this->assertFalse(ViewsBasicManager::searchesCategories('profile'));
    $this->assertFalse(ViewsBasicManager::searchesCategories('resource'));
  }

  /**
   * Pages get the field list but no scope choice, which would change nothing.
   *
   * @covers \Drupal\ys_views_basic\Plugin\Field\FieldWidget\ViewsBasicWidgetBase::buildSearchScopeControls
   */
  public function testPageListsFieldsWithoutScope() {
    $group = $this->build($this->widget(PageViewWidget::class, 'page_card'));
    $this->assertArrayNotHasKey('search_scope', $group);
    $this->assertArrayNotHasKey('search_scope_warning', $group);
    $items = $this->listItems($group);
    $this->assertSame(['Title', 'Teaser Title', 'Teaser Text'], array_column($items, 'label'));
    $this->assertSame([NULL, NULL, NULL], array_column($items, 'states'));
  }

  /**
   * Resources keep their own search-field picker and get none of this.
   *
   * @covers \Drupal\ys_views_basic\Plugin\Field\FieldWidget\ViewsBasicWidgetBase::buildSearchScopeControls
   */
  public function testResourceBuildsNoScopeControls() {
    $form = [];
    $widget = $this->widget(ResourceViewWidget::class, 'resource_card');
    $this->invoke($widget, 'buildSearchScopeControls', [&$form, $this->items(NULL), 0, self::SELECTORS]);
    $this->assertSame([], $form);
  }

  /**
   * The scope controls move into the search filter's settings row.
   *
   * @covers \Drupal\ys_views_basic\Plugin\Field\FieldWidget\ViewsBasicWidgetBase::buildExposedFilterAccordion
   */
  public function testScopeControlsLiveInTheSearchFilterRow() {
    $element = [
      'exposed_filter_options' => [
        'show_search_filter' => ['#type' => 'checkbox'],
      ],
      'search_scope' => ['#type' => 'radios'],
      'search_scope_warning' => ['#type' => 'container'],
      'search_fields_included' => ['#theme' => 'item_list'],
    ];
    $built = ProfileViewWidget::buildExposedFilterAccordion($element, $this->createMock(FormStateInterface::class));
    $settings = $built['exposed_filter_options']['show_search_filter__row']['settings'];
    $this->assertSame(
      ['search_scope', 'search_scope_warning', 'search_fields_included'],
      array_values(array_filter(array_keys($settings), fn($key) => $key[0] !== '#'))
    );
    foreach (['search_scope', 'search_scope_warning', 'search_fields_included'] as $key) {
      $this->assertArrayNotHasKey($key, $built);
    }
  }

  /**
   * The resource search row still holds only the search-field picker.
   *
   * @covers \Drupal\ys_views_basic\Plugin\Field\FieldWidget\ViewsBasicWidgetBase::buildExposedFilterAccordion
   */
  public function testResourceSearchRowKeepsItsOwnSettings() {
    $element = [
      'exposed_filter_options' => [
        'show_search_filter' => ['#type' => 'checkbox'],
      ],
      'search_fields' => ['#type' => 'checkboxes'],
    ];
    $built = ResourceViewWidget::buildExposedFilterAccordion($element, $this->createMock(FormStateInterface::class));
    $this->assertArrayHasKey('search_fields', $built['exposed_filter_options']['show_search_filter__row']['settings']);
  }

  /**
   * The search help names the field list rather than claiming titles only.
   *
   * @covers \Drupal\ys_views_basic\Plugin\Field\FieldWidget\ViewsBasicWidgetBase::getExposedFilterDescriptions
   */
  public function testSearchHelpPointsAtTheFieldList() {
    $help = fn(string $class, string $bundle) => (string) $this->invoke($this->widget($class, $bundle), 'getExposedFilterDescriptions')['show_search_filter'];

    $this->assertSame('Searches content fields and category names. Tags are not searched.', $help(PostViewWidget::class, 'post_card'));
    $this->assertSame('Searches content fields and category names. Tags are not searched.', $help(EventViewWidget::class, 'event_card'));
    $this->assertSame('Searches content fields. Tags and categories are not searched.', $help(PageViewWidget::class, 'page_card'));
    $this->assertSame('Searches content fields. Tags and affiliations are not searched.', $help(ProfileViewWidget::class, 'profile_card'));
    // The help sits on the Show search checkbox, which shows while search is
    // off and the "Included in search" list is collapsed, so it must not
    // point at that list.
    foreach ([PostViewWidget::class, PageViewWidget::class, ProfileViewWidget::class] as $class) {
      $this->assertStringNotContainsString('Included in search', $help($class, 'x_card'));
    }
  }

  /**
   * The saved scope is the submitted one, normalized.
   *
   * A disabled radio group submits nothing, so a block whose search is off
   * keeps the scope it had. A block saved before the scope existed, or a page
   * listing with no radios, saves the shown scope.
   *
   * @covers \Drupal\ys_views_basic\Plugin\Field\FieldWidget\ViewsBasicWidgetBase::submittedSearchScope
   */
  public function testSubmittedSearchScope() {
    $scope = fn(array $built) => $this->invoke($this->widget(ProfileViewWidget::class, 'profile_card'), 'submittedSearchScope', [$built]);
    $this->assertSame('all', $scope(['search_scope' => ['#value' => 'all', '#default_value' => 'shown']]));
    $this->assertSame('shown', $scope(['search_scope' => ['#value' => 'shown', '#default_value' => 'all']]));
    $this->assertSame('all', $scope(['search_scope' => ['#value' => NULL, '#default_value' => 'all']]), 'Disabled radios keep the stored scope.');
    $this->assertSame('shown', $scope(['search_scope' => ['#value' => 'bogus']]));
    $this->assertSame('shown', $scope([]), 'No radios saves the shown scope.');
  }

}
