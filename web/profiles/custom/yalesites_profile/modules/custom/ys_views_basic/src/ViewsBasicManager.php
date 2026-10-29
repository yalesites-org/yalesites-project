<?php

namespace Drupal\ys_views_basic;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityDisplayRepository;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\layout_builder\OverridesSectionStorageInterface;
use Drupal\node\NodeInterface;
use Drupal\views\ViewEntityInterface;
use Drupal\views\ViewExecutable;
use Drupal\views\ViewExecutableFactory;
use Drupal\ys_views_basic\Service\ExposedTaxonomyFilterOptions;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Service for managing the Views Basic plugins.
 */
class ViewsBasicManager extends ControllerBase implements ContainerInjectionInterface {

  /**
   * Allowed entity types for users to select.
   *
   * Format:
   * 'content_type_machine_name' => [
   *   'label' => 'Human readable label',
   *   'view_modes' => [
   *     'view_mode_machine_name1' => 'Human readable label 1',
   *     'view_mode_machine_name2' => 'Human readable label 2',
   *   ],
   *   'sort_by' => [
   *     'field_machine_name:ASC' => 'Human readable sort label',
   *   ]
   * ],
   *
   * @todo This seems fragile and would better be inside a config page for
   * admins to select.
   *
   * @var array
   */

  const ALLOWED_ENTITIES = [
    'post' => [
      'label' => 'Posts',
      'img' => '/profiles/custom/yalesites_profile/modules/custom/ys_views_basic/assets/icons/content-type-post.svg',
      'img_alt' => 'Speech bubble icon',
      'view_modes' => [
        'card' => [
          'label' => 'Card Grid',
          'img' => '/profiles/custom/yalesites_profile/modules/custom/ys_views_basic/assets/icons/display-type-card-grid.svg',
          'img_alt' => 'Icon showing 3 generic cards next to each other. Image placement is on the top of each card.',
        ],
        'list_item' => [
          'label' => 'List',
          'img' => '/profiles/custom/yalesites_profile/modules/custom/ys_views_basic/assets/icons/display-type-list-view.svg',
          'img_alt' => 'Icon showing 3 generic list items one on top of the other. Image placement is on the left of each list item.',
        ],
        'condensed' => [
          'label' => 'Condensed',
          'img' => '/profiles/custom/yalesites_profile/modules/custom/ys_views_basic/assets/icons/display-type-condensed.svg',
          'img_alt' => 'Icon showing 3 generic list items one on top of the other with no images on the items.',
        ],
      ],
      'sort_by' => [
        'field_publish_date:DESC' => 'Publish Date - newer first',
        'field_publish_date:ASC' => 'Publish Date - older first',
      ],
    ],
    'event' => [
      'label' => 'Events',
      'img' => '/profiles/custom/yalesites_profile/modules/custom/ys_views_basic/assets/icons/content-type-event.svg',
      'img_alt' => 'Calendar icon',
      'view_modes' => [
        'card' => [
          'label' => 'Card Grid',
          'img' => '/profiles/custom/yalesites_profile/modules/custom/ys_views_basic/assets/icons/display-type-card-grid.svg',
          'img_alt' => 'Icon showing 3 generic cards next to each other. Image placement is on the top of each card.',
        ],
        'list_item' => [
          'label' => 'List',
          'img' => '/profiles/custom/yalesites_profile/modules/custom/ys_views_basic/assets/icons/display-type-list-view.svg',
          'img_alt' => 'Icon showing 3 generic list items one on top of the other. Image placement is on the left of each list item.',
        ],
        'condensed' => [
          'label' => 'Condensed',
          'img' => '/profiles/custom/yalesites_profile/modules/custom/ys_views_basic/assets/icons/display-type-condensed.svg',
          'img_alt' => 'Icon showing 3 generic list items one on top of the other with no images on the items.',
        ],
      ],
      // Soonest first is listed first so a new listing defaults to it; the
      // keys are unchanged so every saved listing keeps its sort (#1864).
      'sort_by' => [
        'field_event_date:ASC' => 'Event Date - soonest first',
        'field_event_date:DESC' => 'Event Date - latest first',
      ],
    ],
    'page' => [
      'label' => 'Pages',
      'img' => '/profiles/custom/yalesites_profile/modules/custom/ys_views_basic/assets/icons/content-type-page.svg',
      'img_alt' => 'Blank page icon',
      'view_modes' => [
        'card' => [
          'label' => 'Card Grid',
          'img' => '/profiles/custom/yalesites_profile/modules/custom/ys_views_basic/assets/icons/display-type-card-grid.svg',
          'img_alt' => 'Icon showing 3 generic cards next to each other. Image placement is on the top of each card.',
        ],
        'list_item' => [
          'label' => 'List',
          'img' => '/profiles/custom/yalesites_profile/modules/custom/ys_views_basic/assets/icons/display-type-list-view.svg',
          'img_alt' => 'Icon showing 3 generic list items one on top of the other. Image placement is on the left of each list item.',
        ],
        'condensed' => [
          'label' => 'Condensed',
          'img' => '/profiles/custom/yalesites_profile/modules/custom/ys_views_basic/assets/icons/display-type-condensed.svg',
          'img_alt' => 'Icon showing 3 generic list items one on top of the other with no images on the items.',
        ],
      ],
      'sort_by' => [
        'title:ASC' => 'Title - A-Z',
        'title:DESC' => 'Title - Z-A',
      ],
    ],
    'profile' => [
      'label' => 'Profiles',
      'img' => '/profiles/custom/yalesites_profile/modules/custom/ys_views_basic/assets/icons/content-type-profile.svg',
      'img_alt' => 'Generic person head icon',
      'view_modes' => [
        'card' => [
          'label' => 'Card Grid',
          'img' => '/profiles/custom/yalesites_profile/modules/custom/ys_views_basic/assets/icons/display-type-card-grid.svg',
          'img_alt' => 'Icon showing 3 generic cards next to each other. Image placement is on the top of each card.',
        ],
        'list_item' => [
          'label' => 'List',
          'img' => '/profiles/custom/yalesites_profile/modules/custom/ys_views_basic/assets/icons/display-type-list-view.svg',
          'img_alt' => 'Icon showing 3 generic list items one on top of the other. Image placement is on the left of each list item.',
        ],
        'condensed' => [
          'label' => 'Condensed',
          'img' => '/profiles/custom/yalesites_profile/modules/custom/ys_views_basic/assets/icons/display-type-condensed.svg',
          'img_alt' => 'Icon showing 3 generic list items one on top of the other with no images on the items.',
        ],
      ],
      'sort_by' => [
        'field_last_name:ASC' => 'Last Name - A-Z',
        'field_last_name:DESC' => 'Last Name - Z-A',
      ],
    ],
    // Resources (#1723) keep the design labels and sort options of the
    // ys_views_content_resources widget they replace.
    'resource' => [
      'label' => 'Resources',
      'img' => '/profiles/custom/yalesites_profile/modules/custom/ys_views_basic/assets/icons/content-type-resource.svg',
      'img_alt' => 'Document icon',
      'view_modes' => [
        'card' => [
          'label' => 'Card Grid',
          'img' => '/profiles/custom/yalesites_profile/modules/custom/ys_views_basic/assets/icons/display-type-card-grid.svg',
          'img_alt' => 'Icon showing 3 generic cards next to each other. Image placement is on the top of each card.',
        ],
        'portrait_grid' => [
          'label' => 'Portrait Grid',
          'img' => '/profiles/custom/yalesites_profile/modules/custom/ys_views_basic/assets/icons/display-type-portrait-grid.svg',
          'img_alt' => 'Placeholder icon for portrait grid display mode.',
        ],
        'list_item' => [
          'label' => 'List',
          'img' => '/profiles/custom/yalesites_profile/modules/custom/ys_views_basic/assets/icons/display-type-list-view.svg',
          'img_alt' => 'Icon showing 3 generic list items one on top of the other. Image placement is on the left of each list item.',
        ],
        'condensed' => [
          'label' => 'Condensed',
          'img' => '/profiles/custom/yalesites_profile/modules/custom/ys_views_basic/assets/icons/display-type-condensed.svg',
          'img_alt' => 'Icon showing 3 generic list items one on top of the other with no images on the items.',
        ],
      ],
      'sort_by' => [
        'field_publish_date:DESC' => 'Published Date - newer first',
        'field_publish_date:ASC' => 'Published Date - older first',
      ],
    ],
  ];

  /**
   * Default pin label.
   */
  const DEFAULT_PIN_LABEL = 'Pinned';

  /*
   * Define constants for content types.
   */
  const CONTENT_TYPE_POST = 'post';
  const CONTENT_TYPE_EVENT = 'event';
  const CONTENT_TYPE_PAGE = 'page';
  const CONTENT_TYPE_PROFILE = 'profile';
  const CONTENT_TYPE_RESOURCE = 'resource';

  /**
   * The resource-only exposed filters, keyed by their exposed-filter option.
   *
   * Each maps to the filter id it switches on in the resource scaffold view.
   * The shared filters (search, category, custom vocabulary, audience) are
   * handled for every content type in ::setupView().
   */
  const RESOURCE_EXPOSED_FILTERS = [
    'show_year_filter' => 'resource_year_filter',
    'show_academic_year_filter' => 'field_academic_years_target_id',
    'show_discipline_filter' => 'field_discipline_target_id',
    'show_areas_of_study_filter' => 'field_areas_of_study_target_id',
    'show_geographic_areas_filter' => 'field_geographic_areas_target_id',
  ];

  /**
   * The fields a resource search runs across when the block picked none.
   */
  const RESOURCE_DEFAULT_SEARCH_FIELDS = [
    'title' => 'title',
    'field_teaser_text' => 'field_teaser_text',
    'field_teaser_title' => 'field_teaser_title',
  ];

  /**
   * Keyword search scope: search the fields the result card renders (#706).
   *
   * The default, and what every listing saved before the scope existed has.
   */
  const SEARCH_SCOPE_SHOWN = 'shown';

  /**
   * Keyword search scope: search every searchable text field of the type.
   */
  const SEARCH_SCOPE_ALL = 'all';

  /**
   * The content types whose search runs across ::searchFieldsFor() (#706).
   *
   * Resources are left out: they keep their own search-field picker
   * (::applyResourceFilters()).
   */
  const SEARCH_SCOPE_TYPES = [
    self::CONTENT_TYPE_POST,
    self::CONTENT_TYPE_EVENT,
    self::CONTENT_TYPE_PAGE,
    self::CONTENT_TYPE_PROFILE,
  ];

  /**
   * The fields every keyword search runs across, in either scope (#706).
   *
   * The set the scaffold views' combine filter has always searched.
   */
  const SEARCH_FIELDS_ALWAYS = ['title', 'field_teaser_title', 'field_teaser_text'];

  /**
   * The content types whose keyword search matches category names (#706).
   *
   * Searched in either scope, through the category combine filter
   * (::applySearchScope()). Tags and affiliation are never searched.
   */
  const SEARCH_CATEGORY_TYPES = [self::CONTENT_TYPE_POST, self::CONTENT_TYPE_EVENT];

  /**
   * Profile view modes whose cards render the subtitle and People fields.
   *
   * Card and list item embed the shared profile reference card, which renders
   * the subtitle and honours the show_department/email/phone/pronouns flags.
   * The condensed card renders only the title and position, and ignores any
   * flags a block kept from an earlier design.
   */
  const PROFILE_DETAIL_VIEW_MODES = ['card', 'list_item'];

  /**
   * The extra fields the shown scope searches, per content type (#706).
   *
   * Each is a text field the result card renders. An empty condition means
   * every card renders it. 'view_modes' limits it to the designs that render
   * it, and 'option' (a [params key, option] pair) to listings whose display
   * option shows it, so the search never matches text the card hides.
   */
  const SEARCH_FIELDS_SHOWN = [
    self::CONTENT_TYPE_PROFILE => [
      'field_position' => [],
      'field_subtitle' => ['view_modes' => self::PROFILE_DETAIL_VIEW_MODES],
      'field_department' => [
        'view_modes' => self::PROFILE_DETAIL_VIEW_MODES,
        'option' => ['profile_field_options', 'show_department'],
      ],
      'field_pronouns' => [
        'view_modes' => self::PROFILE_DETAIL_VIEW_MODES,
        'option' => ['profile_field_options', 'show_pronouns'],
      ],
      'field_email' => [
        'view_modes' => self::PROFILE_DETAIL_VIEW_MODES,
        'option' => ['profile_field_options', 'show_email'],
      ],
    ],
    self::CONTENT_TYPE_POST => [
      'field_teaser_lead_in' => ['option' => ['post_field_options', 'show_eyebrow']],
    ],
  ];

  /**
   * The extra fields the all scope searches, per content type (#706).
   *
   * Every searchable text field of the type, whatever the card renders.
   */
  const SEARCH_FIELDS_ALL = [
    self::CONTENT_TYPE_PROFILE => [
      'field_position',
      'field_subtitle',
      'field_department',
      'field_pronouns',
      'field_email',
    ],
    self::CONTENT_TYPE_POST => ['field_teaser_lead_in', 'field_author'],
    self::CONTENT_TYPE_EVENT => [
      'field_event_description',
      'field_event_room',
      'field_event_location_details',
    ],
  ];

  /**
   * The resource-only field display options (#1723).
   *
   * Stored under resource_field_options and set on each result node while
   * its row renders (ListingRowDisplay) under these same names, which is what
   * atomic's node--resource--*.html.twig templates read.
   */
  const RESOURCE_FIELD_OPTIONS = [
    'show_teaser_text',
    'show_discipline',
    'show_journal_name',
    'show_journal_issue',
    'show_authors',
    'show_publish_date',
  ];

  /**
   * The card sizes a card grid may be set to (#1648).
   *
   * A size rather than a column count, because the column count is not ours to
   * promise: the grid is driven by container queries on the cards wrapper, so
   * the layout region works out how many cards of the chosen size actually fit.
   * "large" is the 3/2/1 grid every card listing has always rendered; "small"
   * is 4/3/2/1. Expressing it this way keeps every stored value valid when an
   * author moves the block into a narrower region, which a stored column count
   * could not.
   */
  const CARD_SIZE_OPTIONS = ['large', 'small'];

  /**
   * The card size used when a listing has not chosen one.
   *
   * Large, so every listing saved before the dial existed renders unchanged.
   */
  const CARD_SIZE_DEFAULT = 'large';

  /**
   * The views ::setupView() packs arguments for, in ::VIEW_ARGUMENT_ORDER.
   *
   * A named argument read is only guaranteed correct for these views. The
   * style plugin, the pager, the sort and the taxonomy filters are shared
   * with the content_resources view, which packs a different, shorter list of
   * its own (ViewsContentResourcesManager::setupView()).
   *
   * Resource listings (#1723) get a scaffold view of their own rather than
   * reusing content_resources: that view stays wired to the old module's
   * hooks, which read its arguments in the old order, until the old module is
   * removed.
   */
  const SCAFFOLD_VIEWS = [
    'views_basic_scaffold',
    'views_basic_scaffold_events',
    'views_basic_scaffold_resources',
  ];

  /**
   * The order of the arguments setupView() passes to the scaffold views.
   *
   * The scaffold views declare only two real contextual filters (type, tid),
   * so everything past those is a side channel read back positionally by
   * ListingRowDisplay, hook_views_pre_view() and the style plugin.
   * ::setupView() builds its argument array FROM this list, so what is written
   * cannot drift from what those three read: they resolve every index through
   * ::viewArgumentIndex().
   *
   * The views plugins in Plugin/views (the sort, the pager, the taxonomy and
   * time-period filters, and the style plugin's view mode lookup) resolve
   * their indices through ::viewArgumentIndex() too, but ungated: the
   * content_resources view drives the same plugins from its own, shorter
   * argument list (ViewsContentResourcesManager::setupView()), so there a
   * name resolved here can land on a different argument. The indices match
   * the bare numbers those plugins read before names existed, so nothing
   * changed for content_resources, but reordering this list still needs
   * that view's argument order checked by hand.
   *
   * Appending here is safe; reordering or removing an entry is not, and is
   * what this constant exists to make obvious. (#1648 learned this the hard
   * way: original_settings used to be last, and one caller recovered it with
   * end($args) rather than by index, so appending an argument silently fed the
   * wrong JSON into every pager and exposed-filter AJAX re-render.)
   */
  const VIEW_ARGUMENT_ORDER = [
    'type',
    'terms_include',
    'terms_exclude',
    'sort',
    'view',
    'items',
    'event_time_period',
    'offset',
    'field_display_options',
    'event_field_display_options',
    'post_field_display_options',
    'pin_settings',
    'original_settings',
    'profile_field_display_options',
    'resource_field_display_options',
  ];

  /**
   * The query argument naming which listing a URL's filter values belong to.
   *
   * Every listing block renders one of the shared scaffold views, so their
   * exposed filters share identifiers (search, field_category_target_id, ...).
   * Without this, a no-JS submit, a reload or a shared link applies one
   * listing's ?search= to every listing on the page. The exposed form carries
   * the listing's key in this argument, and ::setupView() drops the filter
   * values of any listing whose key does not match (YaleSites-Internal#1926).
   */
  const LISTING_QUERY_KEY = 'listing';

  /**
   * Derives the listing key for a listing block from its UUID.
   *
   * A crc32b hash gives 8 hex characters: stable across requests, so a shared
   * link still names the same listing, and short enough to sit in the URL. A
   * collision between the few listings on one page is negligible.
   *
   * @param string $uuid
   *   The host block content UUID.
   *
   * @return string
   *   The listing key.
   */
  public static function listingKey(string $uuid): string {
    return hash('crc32b', $uuid);
  }

  /**
   * Reads the listing key back from a scaffold view's arguments.
   *
   * @param array $args
   *   The view arguments ::setupView() wrote.
   *
   * @return string|null
   *   The listing key, or NULL when the arguments carry none.
   */
  public static function listingKeyFromArgs(array $args): ?string {
    $key = self::fieldDisplayOptionsFromArgs($args)['listing_key'];
    return is_string($key) ? $key : NULL;
  }

  /**
   * What the field_display_options argument holds when it cannot be read.
   *
   * Every flag is off. ::setupView() always writes the full set, so these
   * only apply to an argument list it did not write: a stale cached one, or
   * a crafted views/ajax request.
   */
  const FIELD_DISPLAY_OPTION_DEFAULTS = [
    'show_categories' => 0,
    'show_tags' => 0,
    'show_thumbnail' => 0,
    'block_has_heading' => 0,
    'block_uuid' => NULL,
    'host_nid' => NULL,
    'card_size' => self::CARD_SIZE_DEFAULT,
    'listing_key' => NULL,
  ];

  /**
   * Decodes the field_display_options argument of a scaffold view.
   *
   * The one place that argument is read back. It may come from the browser
   * (views/ajax), so anything that is not a JSON object falls back to
   * ::FIELD_DISPLAY_OPTION_DEFAULTS, and missing keys are filled from it.
   * Values are not type checked here; each reader checks the ones it uses.
   *
   * @param array $args
   *   The view arguments.
   *
   * @return array
   *   The options, with every key of ::FIELD_DISPLAY_OPTION_DEFAULTS set.
   */
  public static function fieldDisplayOptionsFromArgs(array $args): array {
    return self::displayOptionsFromArgs($args, 'field_display_options', self::FIELD_DISPLAY_OPTION_DEFAULTS);
  }

  /**
   * Decodes one JSON-encoded scaffold view argument, filling in defaults.
   *
   * @param array $args
   *   The view arguments.
   * @param string $name
   *   An argument name from ::VIEW_ARGUMENT_ORDER.
   * @param array $defaults
   *   The value of every expected key when the argument lacks it.
   *
   * @return array
   *   The decoded argument merged over $defaults, or $defaults alone when the
   *   argument is missing or is not a JSON object.
   */
  public static function displayOptionsFromArgs(array $args, string $name, array $defaults): array {
    $value = $args[self::viewArgumentIndex($name)] ?? NULL;
    $decoded = is_string($value) ? json_decode($value, TRUE) : NULL;
    return is_array($decoded) ? $decoded + $defaults : $defaults;
  }

  /**
   * Listing params that must be a scalar when present (see ::decodeParams()).
   */
  const SCALAR_PARAMS = [
    'sort_by',
    'view_mode',
    'display',
    'limit',
    'offset',
    'card_size',
    'pin_label',
    'category_filter_label',
    'operator',
    'include_operator',
    'exclude_operator',
  ];

  /**
   * Listing params that must be an array when present (see ::decodeParams()).
   *
   * Only the groups something writes into: the other option groups are read
   * through empty() or behind an is_array() check, which a scalar survives.
   */
  const ARRAY_PARAMS = [
    'exposed_filter_options',
  ];

  /**
   * Returns whether a value is a sort some listing content type offers.
   *
   * The allowlist is every key of ::ALLOWED_ENTITIES[*]['sort_by'], the only
   * values the authoring widgets offer (each "field_name:DIRECTION").
   *
   * @param mixed $sort
   *   The stored or submitted sort.
   *
   * @return bool
   *   TRUE when $sort is one of those keys.
   */
  public static function isAllowedSort(mixed $sort): bool {
    if (!is_string($sort)) {
      return FALSE;
    }
    foreach (self::ALLOWED_ENTITIES as $definition) {
      if (isset($definition['sort_by'][$sort])) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Decodes stored listing params, or returns NULL when they are unusable.
   *
   * The params reach ::configureView() from block content and, on the
   * views/ajax path, from the browser. This checks the shape ::configureView()
   * relies on without a guard, so a malformed value is refused instead of
   * ending in a TypeError.
   *
   * @param mixed $params
   *   The JSON-encoded params.
   *
   * @return array|null
   *   The decoded params, or NULL when they cannot configure a listing.
   */
  public static function decodeParams(mixed $params): ?array {
    $decoded = is_string($params) ? json_decode($params, TRUE) : NULL;
    if (!is_array($decoded) || !is_array($decoded['filters'] ?? NULL)) {
      return NULL;
    }
    $types = $decoded['filters']['types'] ?? NULL;
    if (!is_array($types) || $types === [] || array_filter($types, 'is_string') !== $types) {
      return NULL;
    }
    // The sort reaches the query as an ORDER BY expression (ViewsBasicSort),
    // and on the views/ajax path it comes from the browser: only a sort some
    // content type offers is accepted. Empty is what the preview formatter
    // writes, and sorts nothing.
    if (isset($decoded['sort_by']) && $decoded['sort_by'] !== '' && !self::isAllowedSort($decoded['sort_by'])) {
      return NULL;
    }
    // Values copied straight into a view argument, a sort or a label. An
    // array here reaches array_key_exists() or string functions as a
    // TypeError.
    foreach (self::SCALAR_PARAMS as $key) {
      if (isset($decoded[$key]) && !is_scalar($decoded[$key])) {
        return NULL;
      }
    }
    if (isset($decoded['filters']['event_time_period']) && !is_scalar($decoded['filters']['event_time_period'])) {
      return NULL;
    }
    // ::normalizeResourceParams() unsets a key inside this group, which is a
    // fatal error on a string.
    foreach (self::ARRAY_PARAMS as $key) {
      if (isset($decoded[$key]) && !is_array($decoded[$key])) {
        return NULL;
      }
    }
    foreach (['terms_include', 'terms_exclude'] as $key) {
      $terms = $decoded['filters'][$key] ?? [];
      if (!is_array($terms)) {
        return NULL;
      }
      // ::getTermId() takes a term id or a {target_id: id} item.
      foreach ($terms as $term) {
        if (!is_scalar($term) && !(is_array($term) && is_scalar($term['target_id'] ?? NULL))) {
          return NULL;
        }
      }
    }
    return $decoded;
  }

  /**
   * Returns the positional index of a scaffold view argument.
   *
   * @param string $name
   *   An argument name from ::VIEW_ARGUMENT_ORDER.
   *
   * @return int
   *   Its position in the argument list.
   *
   * @throws \InvalidArgumentException
   *   When the name is not a known argument, so a typo fails loudly rather
   *   than silently reading argument 0.
   */
  public static function viewArgumentIndex(string $name): int {
    $index = array_search($name, self::VIEW_ARGUMENT_ORDER, TRUE);
    if ($index === FALSE) {
      throw new \InvalidArgumentException(sprintf('Unknown Views Basic view argument "%s".', $name));
    }
    return $index;
  }

  /**
   * Definition of every listing block content bundle.
   *
   * The bundle id encodes the (content type, display mode) pair. This single
   * source of truth (ADR DR-2/DR-4) is read by the per-content-type widgets to
   * decide which form controls to build (capability flags) and to inject the
   * view mode into the stored JSON, by this manager to resolve render
   * mappings, and by the migration (#1169) to map legacy "view" blocks to
   * their target bundle. It lives on the manager — not a widget — so the
   * formatter and migration can reach it without depending on a form widget
   * plugin (ADR DR-4: "do not push them into widget-only constants").
   *
   * Keys:
   * - content_type: the node bundle the listing queries.
   * - view_mode: the node view mode used to render each result.
   * - supports_thumbnail: whether the "Show Teaser Image" option applies
   *   (card and list_item only).
   * - supports_card_size: whether the "Card size" dial applies (card grid
   *   only).
   *
   * The existing "event_calendar" bundle is intentionally absent: it uses a
   * different field type (event_calendar_basic_params) and its own widget.
   */
  const LISTING_BUNDLES = [
    'post_card' => [
      'content_type' => self::CONTENT_TYPE_POST,
      'view_mode' => 'card',
      'supports_thumbnail' => TRUE,
      'supports_card_size' => TRUE,
    ],
    'post_list_item' => [
      'content_type' => self::CONTENT_TYPE_POST,
      'view_mode' => 'list_item',
      'supports_thumbnail' => TRUE,
      'supports_card_size' => FALSE,
    ],
    'post_condensed' => [
      'content_type' => self::CONTENT_TYPE_POST,
      'view_mode' => 'condensed',
      'supports_thumbnail' => FALSE,
      'supports_card_size' => FALSE,
    ],
    'event_card' => [
      'content_type' => self::CONTENT_TYPE_EVENT,
      'view_mode' => 'card',
      'supports_thumbnail' => TRUE,
      'supports_card_size' => TRUE,
    ],
    'event_list_item' => [
      'content_type' => self::CONTENT_TYPE_EVENT,
      'view_mode' => 'list_item',
      'supports_thumbnail' => TRUE,
      'supports_card_size' => FALSE,
    ],
    'event_condensed' => [
      'content_type' => self::CONTENT_TYPE_EVENT,
      'view_mode' => 'condensed',
      'supports_thumbnail' => FALSE,
      'supports_card_size' => FALSE,
    ],
    'page_card' => [
      'content_type' => self::CONTENT_TYPE_PAGE,
      'view_mode' => 'card',
      'supports_thumbnail' => TRUE,
      'supports_card_size' => TRUE,
    ],
    'page_list_item' => [
      'content_type' => self::CONTENT_TYPE_PAGE,
      'view_mode' => 'list_item',
      'supports_thumbnail' => TRUE,
      'supports_card_size' => FALSE,
    ],
    'page_condensed' => [
      'content_type' => self::CONTENT_TYPE_PAGE,
      'view_mode' => 'condensed',
      'supports_thumbnail' => FALSE,
      'supports_card_size' => FALSE,
    ],
    'profile_card' => [
      'content_type' => self::CONTENT_TYPE_PROFILE,
      'view_mode' => 'card',
      'supports_thumbnail' => TRUE,
      'supports_card_size' => TRUE,
    ],
    'profile_list_item' => [
      'content_type' => self::CONTENT_TYPE_PROFILE,
      'view_mode' => 'list_item',
      'supports_thumbnail' => TRUE,
      'supports_card_size' => FALSE,
    ],
    'profile_condensed' => [
      'content_type' => self::CONTENT_TYPE_PROFILE,
      'view_mode' => 'condensed',
      'supports_thumbnail' => FALSE,
      'supports_card_size' => FALSE,
    ],
    // Resources (#1723): the teaser image on every design but condensed, and
    // no card-size dial, matching what the resource_view widget offered.
    'resource_card' => [
      'content_type' => self::CONTENT_TYPE_RESOURCE,
      'view_mode' => 'card',
      'supports_thumbnail' => TRUE,
      'supports_card_size' => FALSE,
    ],
    'resource_portrait_grid' => [
      'content_type' => self::CONTENT_TYPE_RESOURCE,
      'view_mode' => 'portrait_grid',
      'supports_thumbnail' => TRUE,
      'supports_card_size' => FALSE,
    ],
    'resource_list_item' => [
      'content_type' => self::CONTENT_TYPE_RESOURCE,
      'view_mode' => 'list_item',
      'supports_thumbnail' => TRUE,
      'supports_card_size' => FALSE,
    ],
    'resource_condensed' => [
      'content_type' => self::CONTENT_TYPE_RESOURCE,
      'view_mode' => 'condensed',
      'supports_thumbnail' => FALSE,
      'supports_card_size' => FALSE,
    ],
  ];

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The entity display repository.
   *
   * @var \Drupal\Core\Entity\EntityDisplayRepository
   */
  protected $entityDisplayRepository;

  /**
   * The term storage.
   *
   * @var \Drupal\taxonomy\TermStorageInterface
   */
  protected $termStorage;

  /**
   * The route match service.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected $routeMatch;

  /**
   * The cache tags invalidator.
   *
   * @var \Drupal\Core\Cache\CacheTagsInvalidatorInterface
   */
  protected $cacheTagsInvalidator;

  /**
   * The view executable factory.
   *
   * @var \Drupal\views\ViewExecutableFactory
   */
  protected $viewExecutableFactory;

  /**
   * Constrains exposed taxonomy filter options (parent term, excluded terms).
   *
   * @var \Drupal\ys_views_basic\Service\ExposedTaxonomyFilterOptions
   */
  protected $exposedTaxonomyFilterOptions;

  /**
   * Constructs a new ViewsBasicManager object.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    EntityDisplayRepository $entity_display_repository,
    RouteMatchInterface $route_match,
    CacheTagsInvalidatorInterface $cache_tags_invalidator,
    ViewExecutableFactory $view_executable_factory,
    ExposedTaxonomyFilterOptions $exposed_taxonomy_filter_options,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->entityDisplayRepository = $entity_display_repository;
    $this->termStorage = $this->entityTypeManager->getStorage('taxonomy_term');
    $this->routeMatch = $route_match;
    $this->cacheTagsInvalidator = $cache_tags_invalidator;
    $this->viewExecutableFactory = $view_executable_factory;
    $this->exposedTaxonomyFilterOptions = $exposed_taxonomy_filter_options;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('entity_display.repository'),
      $container->get('current_route_match'),
      $container->get('cache_tags.invalidator'),
      $container->get('views.executable'),
      $container->get('ys_views_basic.exposed_taxonomy_filter_options'),
    );
  }

  /**
   * Initializes an isolated view executable for the content type.
   *
   * Each call builds the executable from a *clone* of the scaffold view config
   * entity. Views display handlers bind references into the storage entity
   * (DisplayPluginCollection::initializePlugin takes the display array by
   * reference, and setOption() writes through it), and the config entity
   * storage caches a single shared instance. Without cloning, one block's
   * setupView() mutations would leak into every other block on the page — the
   * root cause of #906. Cloning gives every block instance its own storage so
   * sort, limit, filters, pinned, and pagination cannot clobber each other.
   *
   * The original cached entity must never be handed to an executable: PHP
   * clones preserve reference-bound array slots, so every consumer clones
   * first to keep the cached original pristine (ADR DR-9 / #1306).
   *
   * @param array $types
   *   An array of content types.
   *
   * @return \Drupal\views\ViewExecutable|null
   *   The isolated view executable, or NULL when the scaffold view is missing.
   */
  public function initView($types) {
    $view_id = match (TRUE) {
      in_array(self::CONTENT_TYPE_EVENT, $types) => 'views_basic_scaffold_events',
      in_array(self::CONTENT_TYPE_RESOURCE, $types) => 'views_basic_scaffold_resources',
      default => 'views_basic_scaffold',
    };

    $view_entity = $this->entityTypeManager->getStorage('view')->load($view_id);
    if (!$view_entity instanceof ViewEntityInterface) {
      return NULL;
    }

    return $this->viewExecutableFactory->get(clone $view_entity);
  }

  /**
   * Configures, runs and renders the view with the parameters.
   *
   * ::configureView() and then the execute and render step. On return $view
   * holds the render array, not the executable. When ::configureView() refuses
   * the params (malformed, or no known content type) the view is marked
   * failed and $view becomes an empty render array. So does a view whose
   * preview renders nothing (for example an argument that fails validation).
   *
   * @param \Drupal\views\ViewExecutable $view
   *   The view object; replaced by its render array.
   * @param string $params
   *   The JSON encoded string of parameters.
   * @param string|null $blockUuid
   *   See ::configureView().
   * @param bool $blockHasHeading
   *   See ::configureView().
   * @param int|null $hostNid
   *   See ::configureView().
   * @param string|null $listingKey
   *   See ::configureView().
   *
   * @return void
   *   No return value.
   */
  public function setupView(&$view, $params, $blockUuid = NULL, $blockHasHeading = FALSE, ?int $hostNid = NULL, ?string $listingKey = NULL) {
    $display = $this->configureView($view, $params, $blockUuid, $blockHasHeading, $hostNid, $listingKey);
    if ($display === NULL) {
      // Nothing to show: mark the view failed so it renders nothing if
      // anything still holds it, and hand back an empty render array.
      if ($view instanceof ViewExecutable) {
        $view->build_info['fail'] = TRUE;
        $view->built = TRUE;
        $view->executed = TRUE;
        $view->result = [];
      }
      $view = [];
      return;
    }

    // ys_views_basic_views_post_execute() drops the pager of a listing that
    // does not page, on this path and on the views/ajax one alike.
    $view->execute();

    $view = $view->preview();
    // preview() returns NULL when the build failed or access was denied, and
    // FALSE when the display could not be set. Either way there is nothing
    // to render, so hand back an empty render array.
    if (!is_array($view)) {
      $view = [];
      return;
    }
    // Add cache keys for each display option.
    // This ensures that if the options for showing categories, tags,
    // or thumbnails change, the cache will be invalidated,
    // and the view will be re-rendered with the new options.
    if (!empty($view['#rows']['#rows'])) {
      foreach ($view['#rows']['#rows'] as &$resultRow) {
        $resultRow['#cache']['keys'][] = $display['field']['show_categories'];
        $resultRow['#cache']['keys'][] = $display['field']['show_tags'];
        $resultRow['#cache']['keys'][] = $display['field']['show_thumbnail'];
        $resultRow['#cache']['keys'][] = $display['field']['block_has_heading'];
        $resultRow['#cache']['keys'][] = $display['event']['hide_add_to_calendar'];
        $resultRow['#cache']['keys'][] = $display['post']['show_eyebrow'];
        // The profile options are row-scoped, so they belong here. The
        // card-size dial deliberately is not: it only affects the
        // collection wrapper the style plugin builds, so keying it per row
        // would split identical row markup into a 3-up and a 4-up copy and
        // cover nothing. The wrapper needs no key of its own either: every
        // result node build gets max-age 0 from ys_views_basic_node_view(),
        // and the post/event/page/profile scaffold views set `cache: type:
        // none`. views_basic_scaffold_resources keeps content_resources'
        // `cache: type: tag`, whose cached output is keyed on the view
        // arguments, and the card size rides in field_display_options.
        foreach ($display['profile'] as $profile_option) {
          $resultRow['#cache']['keys'][] = $profile_option;
        }
        foreach ($display['resource'] as $resource_option) {
          $resultRow['#cache']['keys'][] = $resource_option;
        }
        $resultRow['#cache']['keys'][] = $display['pin']['pinned_to_top'];
        $resultRow['#cache']['keys'][] = $display['pin']['pin_label'];

        $resultRow['#cache']['contexts'][] = 'url.query_args:page';
      }
    }
  }

  /**
   * Configures the view from the parameters, without running it.
   *
   * Sets the display, its filters, sorts and pager, and the view arguments
   * every views plugin and hook in this module reads back. Nothing here
   * executes or renders the view, so hook_views_pre_view() can call it on the
   * views/ajax path and leave the run to the request that is already
   * rendering the view.
   *
   * @param \Drupal\views\ViewExecutable $view
   *   The view object.
   * @param mixed $params
   *   The JSON encoded string of parameters. Anything ::decodeParams()
   *   refuses leaves the view untouched.
   * @param string|null $blockUuid
   *   The host block content UUID, used to derive a per-instance pager element
   *   so multiple paginated listings on one page paginate independently.
   * @param bool $blockHasHeading
   *   Whether the host block renders its own heading (its field_heading is not
   *   empty). When TRUE the component-wrapper renders an H2 above the listing,
   *   so each result card nests one level deeper (H3); when FALSE the cards are
   *   the first heading under the page H1 and render at H2. Carried into the
   *   field-display options so ListingRowDisplay can hand the level to each
   *   result row (mirrors how show_categories flows). Defaults to FALSE
   *   so the AJAX/preview paths, which have no block entity, fall back to H2.
   * @param int|null $hostNid
   *   The node the listing is placed on, for requests whose route has no node
   *   parameter (the views.ajax pager and exposed-filter re-render). The
   *   current route's node, or Layout Builder's overridden page, wins when
   *   there is one.
   * @param string|null $listingKey
   *   The listing key (see ::listingKey()), for the AJAX path, which recovers
   *   the key from the incoming view arguments. Ignored when $blockUuid is
   *   given.
   *
   * @return array|null
   *   The display options written into the view arguments, keyed 'field',
   *   'event', 'post', 'profile', 'resource' and 'pin'; NULL when the params
   *   are invalid and the view was left unconfigured.
   */
  public function configureView(ViewExecutable $view, mixed $params, ?string $blockUuid = NULL, bool $blockHasHeading = FALSE, ?int $hostNid = NULL, ?string $listingKey = NULL): ?array {
    $paramsDecoded = self::decodeParams($params);
    if ($paramsDecoded === NULL) {
      return NULL;
    }
    // Only content types, sorts and view modes listed in ::ALLOWED_ENTITIES
    // are used. With no known content type there is nothing to show. A sort
    // or view mode the selected types do not offer (or none at all) falls
    // back to the first type's default.
    $types = self::getValidatedTypes($paramsDecoded['filters']['types']);
    if (!$types) {
      return NULL;
    }
    $sortBy = self::getValidatedSort($types, $paramsDecoded['sort_by'] ?? NULL);
    $viewMode = self::getValidatedViewMode($types, $paramsDecoded['view_mode'] ?? NULL);
    $listingKey = $blockUuid ? self::listingKey($blockUuid) : $listingKey;
    $routeNode = $this->routeMatch->getParameter('node');
    // Layout Builder's own AJAX routes (add, update, move block) carry the
    // page only through the section storage.
    $sectionStorage = $this->routeMatch->getParameter('section_storage');
    if (!$routeNode instanceof NodeInterface && $sectionStorage instanceof OverridesSectionStorageInterface) {
      $routeNode = $sectionStorage->getContextValue('entity');
    }
    if ($routeNode instanceof NodeInterface) {
      $hostNid = (int) $routeNode->id();
    }
    $pinned_to_top = isset($paramsDecoded['pinned_to_top']) ? (bool) $paramsDecoded['pinned_to_top'] : FALSE;

    /* Events need to have aggregation turned on in the view. Therefore, we
     * retrieve a special event scaffold view and apply sorting here instead of
     * the custom sort plugin.
     */

    if (in_array('event', $types)) {
      $sortArray = [];
      $sortDirection = explode(":", $sortBy);

      if ($pinned_to_top) {
        $sortArray[] = [
          'id' => 'sticky',
          'table' => "node_field_data",
          'field' => 'sticky',
          'order' => 'desc',
        ];
      }

      $sortArray[] =
      [
        'id' => 'field_event_date_value',
        'table' => "node__field_event_date",
        'field' => 'field_event_date_value',
        'order' => $sortDirection[1],
      ];

      $view->getDisplay()->setOption('sorts', $sortArray);
    }

    $view->setDisplay('block_1');
    $filterType = implode('+', $types);

    // Retrieve the current filter options from the view's display settings.
    $filters = $view->getDisplay()->getOption('filters');

    // Mapping content types to their respective category filters.
    $category_filters = [
      self::CONTENT_TYPE_POST => 'field_category_target_id',
      self::CONTENT_TYPE_EVENT => 'field_category_target_id',
      self::CONTENT_TYPE_PAGE => 'field_category_target_id_1',
      self::CONTENT_TYPE_PROFILE => 'field_affiliation_target_id',
      self::CONTENT_TYPE_RESOURCE => 'field_category_target_id',
    ];

    // Determine the category filter name based on the filter type.
    $category_filter_name = $category_filters[$filterType] ?? NULL;

    // Show the exposed filter 'Category' or 'Affiliation'.
    if (!empty($paramsDecoded['exposed_filter_options']['show_category_filter']) && $category_filter_name) {
      // Determine which filters to unset based on the current filter type.
      $filters_to_unset = match ($filterType) {
        self::CONTENT_TYPE_POST, self::CONTENT_TYPE_EVENT => [
          'field_category_target_id_1',
          'field_affiliation_target_id',
        ],
        self::CONTENT_TYPE_PROFILE => [
          'field_category_target_id',
          'field_category_target_id_1',
        ],
        self::CONTENT_TYPE_PAGE => [
          'field_category_target_id',
          'field_affiliation_target_id',
        ],
        default => [],
      };

      // Remove the filters that are not relevant to the current type.
      foreach ($filters_to_unset as $filter) {
        unset($filters[$filter]);
      }

      // Set a custom label for the 'Category' filter if provided.
      if (!empty($paramsDecoded['category_filter_label'])) {
        $filters[$category_filter_name]['expose']['label'] = $paramsDecoded['category_filter_label'];
      }
    }
    else {
      // Remove all category and affiliation filters if 'show_category_filter'
      // is not set or category filter name is not defined.
      foreach ($category_filters as $filter_name) {
        unset($filters[$filter_name]);
      }
    }

    // Custom vocab filter.
    if (!empty($paramsDecoded['exposed_filter_options']['show_custom_vocab_filter'])) {
      // Get the label of the custom vocab.
      $custom_vocab_label = $this->entityTypeManager->getStorage('taxonomy_vocabulary')->load('custom_vocab')->label();
      $filters['field_custom_vocab_target_id']['expose']['label'] = $custom_vocab_label;
    }
    else {
      // Remove filter if 'show filter' field is not set.
      unset($filters['field_custom_vocab_target_id']);
    }

    // Audience filter.
    if (!isset($paramsDecoded['exposed_filter_options']['show_audience_filter'])) {
      unset($filters['field_audience_target_id']);
    }

    if (!isset($paramsDecoded['exposed_filter_options']['show_search_filter'])) {
      // If the 'show_search_filter' option is not set,
      // remove the 'combine' filter.
      // The 'combine' filter is used for full-text search
      // across multiple fields.
      unset($filters['combine']);
    }

    if (!isset($paramsDecoded['exposed_filter_options']['show_year_filter']) || $filterType !== self::CONTENT_TYPE_POST) {
      // Remove the 'Year' filter if the 'show_year_filter' is not set.
      unset($filters['post_year_filter']);
    }

    // The Localist event type filter is event-only (#1967).
    if (empty($paramsDecoded['exposed_filter_options']['show_event_type_filter']) || $filterType !== self::CONTENT_TYPE_EVENT) {
      unset($filters['field_localist_event_type_target_id']);
    }

    if ($filterType === self::CONTENT_TYPE_RESOURCE) {
      $filters = self::applyResourceFilters($filters, $paramsDecoded);
    }
    elseif (isset($filters['combine']) && in_array($filterType, self::SEARCH_SCOPE_TYPES, TRUE)) {
      $filters['combine']['fields'] = $this->applySearchScope($view, $filterType, $viewMode, $paramsDecoded);
      // Category names are matched through a subquery rather than a join, so
      // a post in several categories is never multiplied into several rows.
      // Key and exposed identifier stay the same, so stored blocks and search
      // URLs keep working.
      if (self::searchesCategories($filterType)) {
        $filters['combine']['field'] = 'category_combine';
        $filters['combine']['plugin_id'] = 'ys_views_basic_category_combine';
      }
    }

    // Never offer a term the editor used to exclude content: a visitor
    // picking it would always get zero results. Category and custom
    // vocabulary also stay limited to their included parent's children.
    $parent_params = ['field_custom_vocab_target_id' => 'custom_vocab_included_terms'];
    if ($category_filter_name) {
      $parent_params[$category_filter_name] = 'category_included_terms';
    }
    $excluded_terms = array_map([$this, 'getTermId'], $paramsDecoded['filters']['terms_exclude'] ?? []);
    // Listings migrated from the predecessor blocks list only the terms used
    // on content the listing can show, and drop a filter with none, as those
    // blocks' Views did (BEF "show only used" + "hide when empty", #1967).
    $used_in = empty($paramsDecoded['used_terms_only']) ? NULL : [
      'types' => $paramsDecoded['filters']['types'],
      'event_time_period' => $paramsDecoded['filters']['event_time_period'] ?? NULL,
    ];
    foreach ($filters as $filter_name => $filter) {
      if (($filter['plugin_id'] ?? NULL) === 'taxonomy_index_tid' && !empty($filter['exposed'])) {
        $parent_param = $parent_params[$filter_name] ?? NULL;
        $parent_tid = $parent_param ? (int) ($paramsDecoded[$parent_param] ?? 0) : NULL;
        $constrained = $this->exposedTaxonomyFilterOptions->apply($filters, $filter_name, $excluded_terms, $parent_tid, $used_in);
        if ($used_in && $constrained && empty($filters[$filter_name]['value'])) {
          unset($filters[$filter_name]);
        }
      }
    }

    // Set the modified filters back to the view display options.
    $view->getDisplay()->setOption('filters', $filters);

    // Give each block instance a distinct pager element so that multiple
    // paginated listings on one page do not share the same ?page= query
    // argument (#906). The element is derived deterministically from the block
    // UUID so it is stable across the full-page render and any AJAX pager call.
    // The AJAX call has no block entity, so the UUID also rides the view
    // arguments (field_display_options) and comes back in through
    // ::incomingDisplayContext(); without it the re-render reads ?page= at
    // element 0 and stays on page 1 (#1921).
    if ($blockUuid && ($paramsDecoded['display'] ?? NULL) === 'pager') {
      $pager = $view->getDisplay()->getOption('pager');
      if (is_array($pager)) {
        $pager['options']['id'] = $this->pagerElementId($blockUuid);
        $view->getDisplay()->setOption('pager', $pager);
      }
    }

    /*
     * Sets the arguments that will get passed to contextual filters as well
     * as to the custom sort plugin (ViewsBasicSort), custom style
     * plugin (ViewsBasicDynamicStyle), and custom pager
     * (ViewsBasicFullPager).
     *
     * This is coded this way to work with Ajax pagers specifically as
     * without arguments, the subsequent Ajax calls to load more data do not
     * know what sorting, filters, view modes, or number if items in the pager
     * to use.
     *
     * The order of these arguments is required as follows:
     * 0) Content type machine name (can combine content types with + or ,)
     * 1) Taxonomy term ID to include (can combine with + or ,)
     * 2) Taxonomy term ID to exclude (can combine with + or ,)
     * 3) Sort field and direction (in format field_name:ASC)
     * 4) View mode machine name (i.e. teaser)
     * 5) Items per page (set to 0 for all items)
     * 6) Event time period (future, past, all)
     */

    $termsIncludeArray = [];
    $termsExcludeArray = [];

    // Get terms to include.
    if (isset($paramsDecoded['filters']['terms_include'])) {
      foreach ($paramsDecoded['filters']['terms_include'] as $term) {
        $termsIncludeArray[] = $this->getTermId($term);
      }
    }

    // Get terms to exclude.
    if (isset($paramsDecoded['filters']['terms_exclude'])) {
      foreach ($paramsDecoded['filters']['terms_exclude'] as $term) {
        $termsExcludeArray[] = $this->getTermId($term);
      }
    }

    [$includeOperator, $excludeOperator] = $this->resolveTermOperators($paramsDecoded);

    // Fix for older setting terms for nodes not saved with the new storage.
    if (isset($termsIncludeArray[0]) && is_array($termsIncludeArray[0])) {
      foreach ($termsIncludeArray as $terms) {
        $termsIncludeArrayFixed[] = $terms['target_id'];
      }
      $termsIncludeArray = $termsIncludeArrayFixed;
    }
    if (isset($termsExcludeArray[0]) && is_array($termsExcludeArray[0])) {
      foreach ($termsExcludeArray as $terms) {
        $termsExcludeArrayFixed[] = $terms['target_id'];
      }
      $termsExcludeArray = $termsExcludeArrayFixed;
    }
    // End fix.
    $termsInclude = (count($termsIncludeArray) != 0) ? implode($includeOperator, $termsIncludeArray) : 'all';
    $termsExclude = (count($termsExcludeArray) != 0) ? implode($excludeOperator, $termsExcludeArray) : NULL;

    if (($paramsDecoded['display'] ?? NULL) == 'all') {
      $itemsLimit = 0;
    }
    else {
      $itemsLimit = isset($paramsDecoded['limit']) ? max(0, (int) $paramsDecoded['limit']) : NULL;
    }

    $eventTimePeriod = $paramsDecoded['filters']['event_time_period'] ?? NULL;

    // Determine if we are in a state where field_options doesn't yet exist
    // (pre-existing view)
    $no_field_display_options_saved = !array_key_exists('field_options', $paramsDecoded) || !is_array($paramsDecoded['field_options']);

    $field_display_options = [
      'show_categories' => (int) !empty($paramsDecoded['field_options']['show_categories']),
      'show_tags' => (int) !empty($paramsDecoded['field_options']['show_tags']),
      'show_thumbnail' => (int) $no_field_display_options_saved || !empty($paramsDecoded['field_options']['show_thumbnail']),
      // Whether the host block renders its own heading; drives the per-result
      // card heading level in ListingRowDisplay (H3 when nested under the
      // block heading, H2 when the cards are the first heading on the page).
      'block_has_heading' => (int) $blockHasHeading,
      // The host block, carried for the AJAX re-render, which has no block
      // entity to derive the per-instance pager element from (#1921).
      'block_uuid' => $blockUuid,
      // The host node, carried for the AJAX re-render, whose views.ajax route
      // has no node to leave out of the results (#1921).
      'host_nid' => $hostNid,
      // Card-size dial (#1648). It rides the shared field_display_options
      // bucket rather than taking a positional arg of its own because it
      // applies to every content type's card grid, and because it is read by
      // the style plugin that builds the collection wrapper rather than
      // per result row.
      'card_size' => $this->getDefaultParamValue('card_size', $params),
      // Rides the view arguments so the AJAX re-render of the exposed form
      // keeps its hidden listing field (YaleSites-Internal#1926).
      'listing_key' => $listingKey,
    ];

    $event_field_display_options = [
      'hide_add_to_calendar' => (int) !empty($paramsDecoded['event_field_options']['hide_add_to_calendar']),
    ];

    $post_field_display_options = [
      'show_eyebrow' => (int) !empty($paramsDecoded['post_field_options']['show_eyebrow']),
    ];

    // Profile data pass-throughs (#1648): department, email, phone and
    // pronouns on any profile listing, not just the retired directory card.
    $profile_field_display_options = [
      'show_department' => (int) !empty($paramsDecoded['profile_field_options']['show_department']),
      'show_email' => (int) !empty($paramsDecoded['profile_field_options']['show_email']),
      'show_phone' => (int) !empty($paramsDecoded['profile_field_options']['show_phone']),
      'show_pronouns' => (int) !empty($paramsDecoded['profile_field_options']['show_pronouns']),
    ];

    $resource_field_display_options = self::resourceFieldDisplayOptions($paramsDecoded);

    $pin_label = $paramsDecoded['pin_label'] ?? self::DEFAULT_PIN_LABEL;

    if (!$pinned_to_top) {
      $pin_label = NULL;
    }

    $pin_options = [
      'pinned_to_top' => $pinned_to_top,
      'pin_label' => $pin_label,
    ];

    /*
     * End setting dynamic arguments.
     */

    /*
     * Includes current node, if specified in settings.
     */
    $includeCurrent = $paramsDecoded['show_current_entity'] ?? 0;
    if (!$includeCurrent && $hostNid) {
      /** @var \Drupal\views\Plugin\views\query\Sql $query */
      $query = $view->getQuery();
      $baseTableAlias = $query->ensureTable('node_field_data');
      if ($baseTableAlias) {
        $query->addWhere(0, "$baseTableAlias.nid", $hostNid, '<>');
      }
    }

    /*
     * End include current node.
     */

    $view_arg_values = [
      'type' => $filterType,
      'terms_include' => $termsInclude,
      'terms_exclude' => $termsExclude,
      'sort' => $sortBy,
      'view' => $viewMode,
      'items' => $itemsLimit,
      'event_time_period' => str_contains($filterType, 'event') ? $eventTimePeriod : NULL,
      'offset' => max(0, (int) ($paramsDecoded['offset'] ?? 0)),
      'field_display_options' => json_encode($field_display_options),
      'event_field_display_options' => json_encode($event_field_display_options),
      'post_field_display_options' => json_encode($post_field_display_options),
      'pin_settings' => json_encode($pin_options),
      'original_settings' => $params,
      'profile_field_display_options' => json_encode($profile_field_display_options),
      'resource_field_display_options' => json_encode($resource_field_display_options),
    ];

    // Ordered by ::VIEW_ARGUMENT_ORDER rather than by the literal above, so
    // the positions every reader resolves through ::viewArgumentIndex() cannot
    // drift out of step with how they are written.
    $view_args = [];
    foreach (self::VIEW_ARGUMENT_ORDER as $arg_name) {
      $view_args[$arg_name] = $view_arg_values[$arg_name];
    }

    $view->setArguments($view_args);
    // See ViewsBasicFullPager::query().
    $view->setOffset((int) $view_args['offset']);

    // Filter values in the URL that another listing submitted are not ours:
    // run this listing unfiltered. The input is non-empty on purpose, since an
    // empty array makes Views fall back to the request query. It keeps the
    // URL's own listing value, not this listing's key, because the pager
    // builds its links from the exposed input: our key there would point the
    // other listing's filter at this one on the next page. A URL with no
    // listing argument (a link made before YaleSites-Internal#1926) applies
    // to every listing, as it always did.
    if ($listingKey !== NULL) {
      $urlListing = $view->getRequest()?->query->all()[self::LISTING_QUERY_KEY] ?? NULL;
      if ($urlListing !== NULL && $urlListing !== $listingKey) {
        $view->setExposedInput([self::LISTING_QUERY_KEY => $urlListing]);
      }
    }

    return [
      'field' => $field_display_options,
      'event' => $event_field_display_options,
      'post' => $post_field_display_options,
      'profile' => $profile_field_display_options,
      'resource' => $resource_field_display_options,
      'pin' => $pin_options,
    ];
  }

  /**
   * Returns whether stored params make a listing that pages.
   *
   * @param array $args
   *   The view arguments ::configureView() wrote.
   *
   * @return bool|null
   *   TRUE for a paged listing, FALSE for one that is not, NULL when the
   *   arguments carry no usable params.
   */
  public static function usesPagerFromArgs(array $args): ?bool {
    $params = self::decodeParams($args[self::viewArgumentIndex('original_settings')] ?? NULL);
    return $params === NULL ? NULL : ($params['display'] ?? NULL) === 'pager';
  }

  /**
   * Recovers the host-block facts an AJAX re-render needs from view arguments.
   *
   * The pager and exposed-filter AJAX requests have no block entity, so
   * setupView() writes what it knows about the block into
   * field_display_options and this reads it back. The arguments come from the
   * browser, but the UUID is only ever hashed by ::pagerElementId(), so any
   * string yields an element in its small fixed range, and the host node ID
   * only ever removes that one node from a listing.
   *
   * @param array $args
   *   The incoming positional view arguments.
   *
   * @return array
   *   'block_has_heading' (bool), 'block_uuid' (string|null) and 'host_nid'
   *   (positive int|null).
   */
  public static function incomingDisplayContext(array $args): array {
    $options = self::fieldDisplayOptionsFromArgs($args);
    $uuid = $options['block_uuid'];
    $nid = $options['host_nid'];
    return [
      'block_has_heading' => !empty($options['block_has_heading']),
      'block_uuid' => is_string($uuid) && $uuid !== '' ? $uuid : NULL,
      'host_nid' => is_int($nid) && $nid > 0 ? $nid : NULL,
    ];
  }

  /**
   * Derives a small, stable pager element id from a block UUID.
   *
   * The element id indexes the comma-separated ?page= query argument, so it
   * must be both stable across requests (so an AJAX pager call targets the
   * same element the full-page render assigned) and small (so the query string
   * stays compact). crc32 of the block UUID modulo 100 satisfies both; the
   * collision probability for the handful of paginated blocks realistically
   * placed on one page is negligible.
   *
   * @param string $uuid
   *   The host block content UUID.
   *
   * @return int
   *   A pager element id in the range 0-99.
   */
  protected function pagerElementId(string $uuid): int {
    return abs(crc32($uuid)) % 100;
  }

  /**
   * Applies a resource listing's own exposed filters and search fields.
   *
   * The resource-only half of the filter setup ported from
   * ViewsContentResourcesManager::setupView() (#1723); ::setupView() has
   * already handled the filters every content type shares.
   *
   * @param array $filters
   *   The scaffold view display's filters.
   * @param array $paramsDecoded
   *   The decoded stored params.
   *
   * @return array
   *   The filters, with each resource-only filter the block did not enable
   *   removed and the search narrowed to the block's chosen fields.
   */
  public static function applyResourceFilters(array $filters, array $paramsDecoded): array {
    $exposed = $paramsDecoded['exposed_filter_options'] ?? [];
    foreach (self::RESOURCE_EXPOSED_FILTERS as $option => $filter) {
      if (!isset($exposed[$option])) {
        unset($filters[$filter]);
      }
    }

    // A block saved before search fields existed keeps the view's own set.
    $search_fields = $paramsDecoded['search_fields'] ?? NULL;
    if (isset($exposed['show_search_filter']) && !empty($search_fields) && is_array($search_fields)) {
      $selected = array_filter($search_fields) ?: self::RESOURCE_DEFAULT_SEARCH_FIELDS;
      $fields = array_combine($selected, $selected);
      // 'authors' is not a field: it swaps in a combine filter that matches
      // author names through subqueries, so a resource with several authors
      // is never multiplied into several rows. Key and exposed identifier stay
      // the same, so stored blocks and search URLs keep working.
      if (isset($fields['authors'])) {
        unset($fields['authors']);
        $filters['combine']['field'] = 'resource_author_combine';
        $filters['combine']['plugin_id'] = 'ys_views_basic_resource_author_combine';
      }
      $filters['combine']['fields'] = $fields;
    }

    return $filters;
  }

  /**
   * Returns a stored search scope, or the shown scope if it is not one.
   *
   * @param mixed $scope
   *   The stored or submitted scope.
   *
   * @return string
   *   ::SEARCH_SCOPE_SHOWN or ::SEARCH_SCOPE_ALL.
   */
  public static function normalizeSearchScope(mixed $scope): string {
    return $scope === self::SEARCH_SCOPE_ALL ? self::SEARCH_SCOPE_ALL : self::SEARCH_SCOPE_SHOWN;
  }

  /**
   * Returns the fields a listing's keyword search runs across (#706).
   *
   * @param string $type
   *   The listing's content type.
   * @param string $scope
   *   The search scope; anything but ::SEARCH_SCOPE_ALL searches what is
   *   shown.
   * @param string $viewMode
   *   The listing's validated view mode, which decides what the card shows.
   * @param array $paramsDecoded
   *   The decoded stored params, read for the per-type display options.
   *
   * @return string[]
   *   Field names, the ::SEARCH_FIELDS_ALWAYS set first.
   */
  public static function searchFieldsFor(string $type, string $scope, string $viewMode, array $paramsDecoded): array {
    if (self::normalizeSearchScope($scope) === self::SEARCH_SCOPE_ALL) {
      return array_merge(self::SEARCH_FIELDS_ALWAYS, self::SEARCH_FIELDS_ALL[$type] ?? []);
    }
    $fields = self::SEARCH_FIELDS_ALWAYS;
    foreach (array_keys(self::SEARCH_FIELDS_SHOWN[$type] ?? []) as $field) {
      $shown = self::shownSearchCondition($type, $field, $viewMode);
      if ($shown === TRUE || (is_array($shown) && !empty($paramsDecoded[$shown[0]][$shown[1]]))) {
        $fields[] = $field;
      }
    }
    return $fields;
  }

  /**
   * Returns when a listing's card shows a searchable field (#706).
   *
   * @param string $type
   *   The listing's content type.
   * @param string $field
   *   The field name.
   * @param string $viewMode
   *   The listing's view mode.
   *
   * @return array|bool
   *   TRUE when the card always shows the field, FALSE when it never does,
   *   or the [params key, option] pair of the display option that shows it.
   */
  public static function shownSearchCondition(string $type, string $field, string $viewMode): array|bool {
    if (in_array($field, self::SEARCH_FIELDS_ALWAYS, TRUE)) {
      return TRUE;
    }
    $condition = self::SEARCH_FIELDS_SHOWN[$type][$field] ?? NULL;
    if ($condition === NULL || (isset($condition['view_modes']) && !in_array($viewMode, $condition['view_modes'], TRUE))) {
      return FALSE;
    }
    return $condition['option'] ?? TRUE;
  }

  /**
   * Whether a listing's keyword search matches category names (#706).
   *
   * @param string $type
   *   The listing's content type.
   *
   * @return bool
   *   TRUE for the types in ::SEARCH_CATEGORY_TYPES, in either scope.
   */
  public static function searchesCategories(string $type): bool {
    return in_array($type, self::SEARCH_CATEGORY_TYPES, TRUE);
  }

  /**
   * Whether the two search scopes search different fields for a type (#706).
   *
   * A field the all scope adds differs between the scopes unless every card
   * of the type always shows it. A field behind a display option or limited
   * to some designs can differ, so profiles offer the choice even though
   * their widest card shows every field. Pages search the same fields either
   * way, and resources have their own search-field picker, so neither offers
   * it.
   *
   * @param string $type
   *   The listing's content type.
   *
   * @return bool
   *   TRUE when the all scope can search a field the shown scope does not.
   */
  public static function offersSearchScope(string $type): bool {
    if (!in_array($type, self::SEARCH_SCOPE_TYPES, TRUE)) {
      return FALSE;
    }
    foreach (self::SEARCH_FIELDS_ALL[$type] ?? [] as $field) {
      if ((self::SEARCH_FIELDS_SHOWN[$type][$field] ?? NULL) !== []) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Puts every field the listing's search scope covers on the view display.
   *
   * Core's combine filter only matches fields the display has a handler for,
   * and the scaffold views carry only the ::SEARCH_FIELDS_ALWAYS set. Each
   * missing field is added as an excluded field, so it joins the query for
   * the search without rendering anything (#706).
   *
   * @param \Drupal\views\ViewExecutable $view
   *   The scaffold view, its display already set.
   * @param string $type
   *   The listing's content type.
   * @param string $viewMode
   *   The listing's validated view mode.
   * @param array $paramsDecoded
   *   The decoded stored params.
   *
   * @return array
   *   The combine filter's fields option, keyed by field name.
   */
  protected function applySearchScope(ViewExecutable $view, string $type, string $viewMode, array $paramsDecoded): array {
    $search_fields = self::searchFieldsFor($type, self::normalizeSearchScope($paramsDecoded['search_scope'] ?? NULL), $viewMode, $paramsDecoded);
    $display_fields = $view->getDisplay()->getOption('fields') ?: [];
    foreach ($search_fields as $field) {
      $display_fields[$field] ??= [
        'id' => $field,
        'table' => 'node__' . $field,
        'field' => $field,
        'relationship' => 'none',
        'group_type' => 'group',
        'entity_type' => 'node',
        'entity_field' => $field,
        'plugin_id' => 'field',
        'label' => '',
        'exclude' => TRUE,
      ];
    }
    $view->getDisplay()->setOption('fields', $display_fields);
    return array_combine($search_fields, $search_fields);
  }

  /**
   * Returns a resource listing's per-result display flags as 0/1 values.
   *
   * @param array $paramsDecoded
   *   The decoded stored params.
   *
   * @return array
   *   One flag per ::RESOURCE_FIELD_OPTIONS name, all 0 when none are stored.
   */
  public static function resourceFieldDisplayOptions(array $paramsDecoded): array {
    $options = is_array($paramsDecoded['resource_field_options'] ?? NULL) ? $paramsDecoded['resource_field_options'] : [];
    $flags = [];
    foreach (self::RESOURCE_FIELD_OPTIONS as $option) {
      $flags[$option] = (int) !empty($options[$option]);
    }
    return $flags;
  }

  /**
   * Moves a stored resource_view params blob onto this manager's keys (#1723).
   *
   * The resource_view widget kept every per-result option in one
   * field_options bucket and named the category flag show_category. Here the
   * shared options (thumbnail, category, tags) stay in field_options under
   * the names every listing uses, and the resource-only ones move to
   * resource_field_options. The two legacy mappings the old manager applied
   * on every render are applied once here instead: show_publication expands
   * to the four fields it covered, and the retired journal-name filter
   * becomes a search across that field. Every other key is carried over
   * unchanged, and running this on its own output changes nothing.
   *
   * @param array $params
   *   Decoded params as stored by the resource_view widget.
   *
   * @return array
   *   Params for a resource listing bundle.
   */
  public static function normalizeResourceParams(array $params): array {
    $params['filters']['types'] = [self::CONTENT_TYPE_RESOURCE];

    // Only when options were stored: setupView() shows the teaser image when
    // field_options is absent altogether, and an empty set would hide it.
    if (isset($params['field_options']) && is_array($params['field_options'])) {
      $stored = $params['field_options'] + (is_array($params['resource_field_options'] ?? NULL) ? $params['resource_field_options'] : []);
      if (isset($stored['show_publication'])) {
        foreach (['show_journal_name', 'show_journal_issue', 'show_authors', 'show_publish_date'] as $option) {
          $stored[$option] ??= !empty($stored['show_publication']);
        }
      }
      if (!empty($stored['show_category'])) {
        $stored['show_categories'] = 'show_categories';
      }
      $enabled = array_keys(array_filter($stored));
      $shared = array_intersect(['show_thumbnail', 'show_categories', 'show_tags'], $enabled);
      $resource = array_intersect(self::RESOURCE_FIELD_OPTIONS, $enabled);
      $params['field_options'] = array_combine($shared, $shared);
      $params['resource_field_options'] = array_combine($resource, $resource);
    }

    if (!empty($params['exposed_filter_options']['show_journal_publication_name_filter']) && empty($params['search_fields'])) {
      $params['search_fields'] = self::RESOURCE_DEFAULT_SEARCH_FIELDS + [
        'field_journal_publication_name' => 'field_journal_publication_name',
      ];
    }
    unset($params['exposed_filter_options']['show_journal_publication_name_filter']);

    return $params;
  }

  /**
   * Retrieves an overridden Views Basic Scaffold view.
   *
   * Views Basic Scaffold view is overridden with the data from the parameters.
   *
   * @param string $type
   *   Type of view output: 'rendered' (used to allow 'count').
   * @param string $params
   *   JSON of the parameter settings.
   * @param string|null $blockUuid
   *   The host block content UUID, used to derive a per-instance pager element
   *   so multiple paginated listings on one page paginate independently.
   * @param bool $blockHasHeading
   *   Whether the host block renders its own heading; forwarded to setupView()
   *   to set the per-result card heading level (see setupView()).
   *
   * @return array|int|null
   *   An array of a rendered view or a count of the number of results based
   *   on the parameters specified, or NULL when the scaffold view is missing
   *   or the params are invalid.
   */
  public function getView($type, $params, $blockUuid = NULL, $blockHasHeading = FALSE) {
    // Set up the view and initial decoded parameters. Each call gets its own
    // isolated, cloned view (see initView), so the previous static recursion
    // guards are no longer needed and nested views-basic placements now render
    // instead of being silently skipped (#906 / #1306).
    $paramsDecoded = self::decodeParams($params);
    if ($paramsDecoded === NULL) {
      return NULL;
    }
    // With no known content type there is nothing to show.
    $types = self::getValidatedTypes($paramsDecoded['filters']['types']);
    if (!$types) {
      return NULL;
    }
    $view = $this->initView($types);
    if ($view === NULL) {
      return NULL;
    }
    $this->setupView($view, $params, $blockUuid, $blockHasHeading);

    return $view;
  }

  /**
   * Returns an array of entity type machine names and the human readable name.
   *
   * @return array
   *   An array of human readable entity names, with machine name as the key.
   */
  public function entityTypeList() {
    foreach (self::ALLOWED_ENTITIES as $machine_name => $type) {
      $entityTypes[$machine_name] = $type['label'] . "<img src='{$type['img']}' alt='{$type['img_alt']}'>";
    }

    return $entityTypes;
  }

  /**
   * Returns an array of view mode machine names and the human readable name.
   *
   * @param string $content_type
   *   The entity machine name.
   *
   * @return array
   *   An array of human readable view modes, with machine name as the key.
   */
  public function viewModeList($content_type) {
    foreach (self::ALLOWED_ENTITIES[$content_type]['view_modes'] as $machine_name => $viewMode) {
      $viewModes[$machine_name] = $viewMode['label'] . "<img src='{$viewMode['img']}' alt='{$viewMode['img_alt']}'>";
    }

    return $viewModes;
  }

  /**
   * Returns an array of sort by machine names and the human readable name.
   *
   * @param string $content_type
   *   The entity machine name.
   *
   * @return array
   *   An array of human readable sorts, with machine name as the key.
   */
  public function sortByList($content_type) {
    $sortByList = self::ALLOWED_ENTITIES[$content_type]['sort_by'];
    return $sortByList;
  }

  /**
   * Resolves the listing bundle definition for a block content bundle id.
   *
   * @param string $bundle
   *   The block content bundle id (e.g. "post_card").
   *
   * @return array
   *   The definition row: content_type, view_mode, supports_thumbnail,
   *   supports_card_size.
   *
   * @throws \InvalidArgumentException
   *   When the bundle is not a known listing bundle. A consumer asked about an
   *   unknown bundle throws loudly rather than guessing a default (ADR DR-2).
   */
  public static function getListingBundleDefinition(string $bundle): array {
    if (!isset(self::LISTING_BUNDLES[$bundle])) {
      throw new \InvalidArgumentException(sprintf('Unknown Views Basic listing bundle "%s".', $bundle));
    }
    return self::LISTING_BUNDLES[$bundle];
  }

  /**
   * Returns the content type a listing bundle queries.
   *
   * @param string $bundle
   *   The block content bundle id.
   *
   * @return string
   *   The content type machine name.
   */
  public static function getContentTypeForBundle(string $bundle): string {
    return self::getListingBundleDefinition($bundle)['content_type'];
  }

  /**
   * Returns the node view mode a listing bundle renders results in.
   *
   * @param string $bundle
   *   The block content bundle id.
   *
   * @return string
   *   The view mode machine name.
   */
  public static function getViewModeForBundle(string $bundle): string {
    return self::getListingBundleDefinition($bundle)['view_mode'];
  }

  /**
   * Returns whether a listing bundle offers the "Show Teaser Image" option.
   *
   * @param string $bundle
   *   The block content bundle id.
   *
   * @return bool
   *   TRUE when the bundle supports the thumbnail option (card/list_item).
   */
  public static function bundleSupportsThumbnail(string $bundle): bool {
    return self::getListingBundleDefinition($bundle)['supports_thumbnail'];
  }

  /**
   * Returns whether a listing bundle offers the "Card size" dial (#1648).
   *
   * Only the card grid takes one: list and condensed each lay themselves
   * out, so the control would be clutter that does
   * nothing. Declared per bundle rather than inferred from the view mode at
   * call time, matching supports_thumbnail (ADR DR-2).
   *
   * @param string $bundle
   *   The block content bundle id.
   *
   * @return bool
   *   TRUE when the bundle renders a card grid.
   */
  public static function bundleSupportsCardSize(string $bundle): bool {
    return self::getListingBundleDefinition($bundle)['supports_card_size'];
  }

  /**
   * Coerces a stored card-size value to one this module can render (#1648).
   *
   * Anything that is not one of ::CARD_SIZE_OPTIONS — absent, a stale string,
   * a decoded scalar — falls back to the grid every card listing rendered
   * before the dial existed, rather than emitting a size the SCSS has no rule
   * for.
   *
   * @param mixed $value
   *   A stored card_size string, or NULL.
   *
   * @return string
   *   A member of ::CARD_SIZE_OPTIONS.
   */
  public static function normalizeCardSize(mixed $value): string {
    if (is_string($value) && in_array($value, self::CARD_SIZE_OPTIONS, TRUE)) {
      return $value;
    }
    return self::CARD_SIZE_DEFAULT;
  }

  /**
   * Resolves the target listing bundle for a legacy "view" block (#1169).
   *
   * The migration keys on the stored content type and view mode together: the
   * target bundle id is "{content_type}_{view_mode}" when that pair is a known
   * listing bundle. The retired profile directory (#1682) maps to profile_card;
   * its params need ::directoryToCardParams() as well. Returns NULL for
   * anything that does not map (e.g. the calendar view mode, which
   * deploy_10000 already converted to event_calendar, or a malformed/empty
   * param set) so the migration can skip it loudly rather
   * than guess (ADR DR-9).
   *
   * @param string|null $content_type
   *   The stored content type (filters.types[0]).
   * @param string|null $view_mode
   *   The stored view mode.
   *
   * @return string|null
   *   The target bundle id, or NULL when the pair does not map to a listing
   *   bundle.
   */
  public static function migrationTargetBundle(?string $content_type, ?string $view_mode): ?string {
    if ($content_type === NULL || $view_mode === NULL) {
      return NULL;
    }
    if ($content_type === self::CONTENT_TYPE_PROFILE && $view_mode === 'directory') {
      return 'profile_card';
    }
    $candidate = $content_type . '_' . $view_mode;
    return isset(self::LISTING_BUNDLES[$candidate]) ? $candidate : NULL;
  }

  /**
   * Returns the target bundle and preset params for a predecessor block.
   *
   * The predecessor listing blocks (post_list, event_list, directory) embed a
   * hard-coded Drupal View and carry no
   * field_view_params. Superseding them means swapping each instance to the
   * equivalent new bundle and pre-filling field_view_params with params that
   * reproduce the predecessor View's query (ADR DR-10):
   * - post_list  -> post_list_item: posts, sticky + publish-date DESC, 10/page,
   *   with the "Post Category" filter.
   * - event_list -> event_list_item: future events, event-date ASC, with the
   *   "Event Type" (Localist event type) and "Event Category" filters.
   * - directory  -> profile_card: profiles, last-name A-Z, small cards with
   *   department, email and phone (the retired directory look, #1682), with
   *   the "Affiliation" filter.
   *
   * Each keeps the exposed filter visitors had on the predecessor block, under
   * the same label, and sets used_terms_only: the predecessor Views listed
   * only terms used on the content they showed and hid a filter with none
   * (BEF "show only used" + "hide when empty"), so the migrated filters do
   * too (YaleSites-Internal#1967). That is why a Calendar List shows Event
   * Category only where upcoming events use one. Blocks 10002 migrated
   * before these filters were added get them from deploy_10004.
   *
   * These presets are best-effort reproductions and must be confirmed on
   * staging with a before/after render diff (#1171) before the predecessor
   * bundles and their Views are removed.
   *
   * @param string $legacy_bundle
   *   The predecessor block content bundle id.
   *
   * @return array|null
   *   ['target' => bundle id, 'params' => params array] or NULL if not a
   *   known predecessor bundle.
   */
  public static function predecessorPreset(string $legacy_bundle): ?array {
    $base = [
      'field_options' => ['show_thumbnail' => 'show_thumbnail'],
      'event_field_options' => [],
      'post_field_options' => [],
      'exposed_filter_options' => [],
      'category_filter_label' => NULL,
      'category_included_terms' => NULL,
      'custom_vocab_included_terms' => NULL,
      'operator' => '+',
      'offset' => 0,
      'show_current_entity' => 0,
      'pinned_to_top' => FALSE,
      'pin_label' => self::DEFAULT_PIN_LABEL,
    ];
    $presets = [
      'post_list' => [
        'target' => 'post_list_item',
        'params' => [
          'view_mode' => 'list_item',
          'filters' => ['types' => ['post'], 'terms_include' => NULL, 'terms_exclude' => NULL],
          'sort_by' => 'field_publish_date:DESC',
          'display' => 'pager',
          'limit' => 10,
          'pinned_to_top' => TRUE,
          'exposed_filter_options' => ['show_category_filter' => 'show_category_filter'],
          'category_filter_label' => 'Post Category',
        ],
      ],
      'event_list' => [
        'target' => 'event_list_item',
        'params' => [
          'view_mode' => 'list_item',
          'filters' => [
            'types' => ['event'],
            'terms_include' => NULL,
            'terms_exclude' => NULL,
            'event_time_period' => 'future',
          ],
          'sort_by' => 'field_event_date:ASC',
          'display' => 'all',
          'limit' => 10,
          'exposed_filter_options' => [
            'show_category_filter' => 'show_category_filter',
            'show_event_type_filter' => 'show_event_type_filter',
          ],
          'category_filter_label' => 'Event Category',
        ],
      ],
      'directory' => [
        'target' => 'profile_card',
        'params' => self::directoryToCardParams([
          'filters' => ['types' => ['profile'], 'terms_include' => NULL, 'terms_exclude' => NULL],
          'sort_by' => 'field_last_name:ASC',
          'display' => 'all',
          'limit' => 10,
          'exposed_filter_options' => ['show_category_filter' => 'show_category_filter'],
          'category_filter_label' => 'Affiliation',
        ]),
      ],
    ];
    if (!isset($presets[$legacy_bundle])) {
      return NULL;
    }
    return [
      'target' => $presets[$legacy_bundle]['target'],
      'params' => $presets[$legacy_bundle]['params'] + ['used_terms_only' => TRUE] + $base,
    ];
  }

  /**
   * Returns the params the first deploy_10002 wrote for a predecessor block.
   *
   * That version migrated the blocks without their exposed filter: the
   * current preset with no filters, no filter label and no used_terms_only.
   * deploy_10004 matches blocks still holding these params (#1967).
   *
   * @param string $legacy_bundle
   *   The predecessor block content bundle id.
   *
   * @return array|null
   *   The filterless params, or NULL if not a known predecessor bundle.
   */
  public static function filterlessPredecessorParams(string $legacy_bundle): ?array {
    $params = self::predecessorPreset($legacy_bundle)['params'] ?? NULL;
    if ($params === NULL) {
      return NULL;
    }
    unset($params['used_terms_only']);
    return ['exposed_filter_options' => [], 'category_filter_label' => NULL] + $params;
  }

  /**
   * Converts profile directory params to the equivalent card params (#1682).
   *
   * The directory design option was retired onto the profile card grid. Small
   * cards with department, email and phone switched on reproduce its look;
   * every other stored setting (filters, sort, limit, pins) is kept as is.
   *
   * @param array $params
   *   Decoded field_view_params of a directory listing.
   *
   * @return array
   *   The params for a profile_card listing.
   */
  public static function directoryToCardParams(array $params): array {
    $params['view_mode'] = 'card';
    $params['card_size'] = 'small';
    $params['field_options'] = ['show_thumbnail' => 'show_thumbnail'];
    $params['profile_field_options'] = [
      'show_department' => 'show_department',
      'show_email' => 'show_email',
      'show_phone' => 'show_phone',
    ];
    return $params;
  }

  /**
   * Restricts a list of content types to those in ALLOWED_ENTITIES.
   *
   * @param mixed $types
   *   The content type machine names read from the stored parameters.
   *
   * @return string[]
   *   The known content types, in their original order. Empty when the input
   *   is not an array or contains no known type.
   */
  public static function getValidatedTypes($types): array {
    if (!is_array($types)) {
      return [];
    }
    $validTypes = array_filter($types, function ($type) {
      return is_string($type) && isset(self::ALLOWED_ENTITIES[$type]);
    });
    return array_values($validTypes);
  }

  /**
   * Returns the sort to use for the given content types.
   *
   * @param array $types
   *   The content type machine names. Unknown types are ignored.
   * @param mixed $sortBy
   *   The requested sort, in the format "field_name:DIRECTION".
   *
   * @return string
   *   The requested sort when it is listed in the sort_by options of one of
   *   the content types, otherwise the first sort_by option of the first
   *   known content type. An empty string when no content type is known.
   */
  public static function getValidatedSort(array $types, $sortBy): string {
    $types = self::getValidatedTypes($types);
    if (!$types) {
      return '';
    }
    if (is_string($sortBy)) {
      foreach ($types as $type) {
        if (isset(self::ALLOWED_ENTITIES[$type]['sort_by'][$sortBy])) {
          return $sortBy;
        }
      }
    }
    return array_key_first(self::ALLOWED_ENTITIES[$types[0]]['sort_by']);
  }

  /**
   * Returns the view mode to use for the given content types.
   *
   * @param array $types
   *   The content type machine names. Unknown types are ignored.
   * @param mixed $viewMode
   *   The requested view mode machine name.
   *
   * @return string
   *   The requested view mode when it is listed for one of the content types,
   *   otherwise the first view mode of the first known content type. An empty
   *   string when no content type is known.
   */
  public static function getValidatedViewMode(array $types, $viewMode): string {
    $types = self::getValidatedTypes($types);
    if (!$types) {
      return '';
    }
    if (is_string($viewMode)) {
      foreach ($types as $type) {
        if (isset(self::ALLOWED_ENTITIES[$type]['view_modes'][$viewMode])) {
          return $viewMode;
        }
      }
    }
    return array_key_first(self::ALLOWED_ENTITIES[$types[0]]['view_modes']);
  }

  /**
   * Returns every field name that appears in a sort_by option.
   *
   * @return string[]
   *   Unique field names, such as "title" or "field_publish_date".
   */
  public static function getAllowedSortFields(): array {
    $fields = [];
    foreach (self::ALLOWED_ENTITIES as $entity) {
      foreach (array_keys($entity['sort_by']) as $sortKey) {
        $fields[strstr($sortKey, ':', TRUE)] = TRUE;
      }
    }
    return array_keys($fields);
  }

  /**
   * Returns a label given a content type and optional sub parameter.
   *
   * @param string $content_type
   *   Machine name of an entity type.
   * @param string $label_type
   *   Type of label to get: entity, view_mode, or sort_by.
   * @param string $sub_param
   *   Sub parameter name: view_mode or sort_by.
   *
   * @return string
   *   Human readable view mode label.
   */
  public function getLabel($content_type, $label_type, $sub_param = NULL) {
    $label = '';
    switch ($label_type) {
      case 'entity':
        $label = self::ALLOWED_ENTITIES[$content_type]['label'];
        break;

      default:
        $label = ($sub_param) ? self::ALLOWED_ENTITIES[$content_type][$label_type][$sub_param] : '';
    }
    return $label;
  }

  /**
   * Returns a tag label given a term ID.
   *
   * @param int $tag
   *   The taxonomy term ID.
   *
   * @return string
   *   The label of the taxonomy term or empty string.
   */
  public function getTagLabel($tag) : string {
    $term = $this->termStorage->load($tag);
    return ($term) ? $term->name->value : '';
  }

  /**
   * Returns a default value for a parameter to auto-select one in the list.
   *
   * @param string $type
   *   An internal machine name for the type of default parameter to retrieve.
   * @param string $params
   *   The full stringified JSON encoded list of parameters.
   *
   * @return string
   *   The machine default value.
   */
  public function getDefaultParamValue($type, $params) {
    $paramsDecoded = json_decode($params, TRUE);
    $defaultParam = NULL;

    switch ($type) {
      /* @todo Currently, this only selects the first entity type which is
       * okay since there is only a simple dropdown for now. We should change
       * this to better support multiple entity types.
       */
      case 'types':
        if (!empty($paramsDecoded['filters']['types'][0])) {
          $defaultParam = $paramsDecoded['filters']['types'][0];
        }
        break;

      case 'terms_include':
      case 'terms_exclude':
        if (!empty($paramsDecoded['filters'][$type])) {
          foreach ($paramsDecoded['filters'][$type] as $term) {
            $defaultParam[] = $this->getTermId($term);
          }
        }
        break;

      case 'view_mode':
        $defaultParam = (empty($paramsDecoded['view_mode'])) ? 'card' : $paramsDecoded['view_mode'];
        break;

      case 'operator':
        $defaultParam = (empty($paramsDecoded['operator'])) ? '+' : $paramsDecoded['operator'];
        break;

      // Falls back to the legacy shared 'operator' key, then '+', for a
      // block saved before the include/exclude split (#1316).
      case 'include_operator':
      case 'exclude_operator':
        $defaultParam = $paramsDecoded[$type] ?? ($paramsDecoded['operator'] ?? '+');
        break;

      case 'limit':
        $defaultParam = (empty($paramsDecoded['limit'])) ? 10 : (int) $paramsDecoded['limit'];
        break;

      case 'offset':
        $defaultParam = (empty($paramsDecoded['offset'])) ? 0 : (int) $paramsDecoded['offset'];
        break;

      case 'event_time_period':
        $defaultParam = (empty($paramsDecoded['filters']['event_time_period'])) ? 'future' : $paramsDecoded['filters']['event_time_period'];
        break;

      case 'field_options':
        $defaultParam = (empty($paramsDecoded['field_options'])) ? ['show_thumbnail' => 'show_thumbnail'] : $paramsDecoded['field_options'];
        break;

      case 'event_field_options':
        $defaultParam = (empty($paramsDecoded['event_field_options'])) ? [] : $paramsDecoded['event_field_options'];
        break;

      case 'post_field_options':
        $defaultParam = (empty($paramsDecoded['post_field_options'])) ? [] : $paramsDecoded['post_field_options'];
        break;

      case 'profile_field_options':
        $defaultParam = (empty($paramsDecoded['profile_field_options'])) ? [] : $paramsDecoded['profile_field_options'];
        break;

      case 'resource_field_options':
        $defaultParam = (!empty($paramsDecoded['resource_field_options']) && is_array($paramsDecoded['resource_field_options']))
          ? $paramsDecoded['resource_field_options']
          : [];
        break;

      case 'search_fields':
        $defaultParam = (!empty($paramsDecoded['search_fields']) && is_array($paramsDecoded['search_fields']))
          ? $paramsDecoded['search_fields']
          : self::RESOURCE_DEFAULT_SEARCH_FIELDS;
        break;

      case 'search_scope':
        // Listings saved before the scope existed search what is shown
        // (#706).
        $defaultParam = self::normalizeSearchScope($paramsDecoded['search_scope'] ?? NULL);
        break;

      case 'card_size':
        // Listings saved before the dial existed carry no key and must keep
        // their large (3-up) grid. An unrecognised stored value falls back too,
        // rather than emitting a size the SCSS has no rule for.
        $defaultParam = self::normalizeCardSize($paramsDecoded['card_size'] ?? NULL);
        break;

      case 'exposed_filter_options':
        $defaultParam = (empty($paramsDecoded['exposed_filter_options'])) ? [] : $paramsDecoded['exposed_filter_options'];
        break;

      case 'category_filter_label':
        $defaultParam = (empty($paramsDecoded['category_filter_label'])) ? NULL : $paramsDecoded['category_filter_label'];
        break;

      case 'category_included_terms':
        $defaultParam = (empty($paramsDecoded['category_included_terms'])) ? NULL : $paramsDecoded['category_included_terms'];
        break;

      case 'audience_included_terms':
        $defaultParam = (empty($paramsDecoded['audience_included_terms'])) ? NULL : $paramsDecoded['audience_included_terms'];
        break;

      case 'custom_vocab_included_terms':
        $defaultParam = (empty($paramsDecoded['custom_vocab_included_terms'])) ? NULL : $paramsDecoded['custom_vocab_included_terms'];
        break;

      case 'show_current_entity':
        $defaultParam = (empty($paramsDecoded['show_current_entity'])) ? 0 : $paramsDecoded['show_current_entity'];
        break;

      case 'pinned_to_top':
        $defaultParam = (empty($paramsDecoded['pinned_to_top'])) ? FALSE : (bool) $paramsDecoded['pinned_to_top'];
        break;

      case 'pin_label':
        $defaultParam = (empty($paramsDecoded['pin_label'])) ? self::DEFAULT_PIN_LABEL : $paramsDecoded['pin_label'];
        break;

      default:
        $defaultParam = $paramsDecoded[$type];
        break;
    }
    return $defaultParam;
  }

  /**
   * Resolves the include/exclude term operators from decoded stored params.
   *
   * Split independently (#1316): a single shared operator applied to both
   * lists made "All" stricter for includes but looser for excludes at the
   * same time — implode() joining the exclude list with "," (AND) only
   * excludes a node that carries every excluded term, not any one of them.
   * A block saved before the split carries only the legacy 'operator' key,
   * which this applies to both lists as before (no migration, per #1316).
   *
   * @param array $paramsDecoded
   *   The decoded stored params.
   *
   * @return array
   *   [$includeOperator, $excludeOperator], each "+" (OR) or "," (AND).
   */
  public function resolveTermOperators(array $paramsDecoded): array {
    // Only "+" (OR) and "," (AND) are operators; anything else falls back.
    $valid = fn($operator) => in_array($operator, ['+', ','], TRUE);
    $legacyOperator = $valid($paramsDecoded['operator'] ?? NULL) ? $paramsDecoded['operator'] : '+';
    return [
      $valid($paramsDecoded['include_operator'] ?? NULL) ? $paramsDecoded['include_operator'] : $legacyOperator,
      $valid($paramsDecoded['exclude_operator'] ?? NULL) ? $paramsDecoded['exclude_operator'] : $legacyOperator,
    ];
  }

  /**
   * Returns the vocabulary id for a given term.
   *
   * @param mixed $term
   *   The taxonomy term.
   *
   * @return string
   *   The vocabulary machine name.
   */
  private function getVocabulary($term) : string {
    return $term->bundle();
  }

  /**
   * Returns the vocabulary label for a given term.
   *
   * @param mixed $term
   *   The taxonomy term.
   * @param \Drupal\taxonomy\VocabularyInterface $vocabularyInterface
   *   The vocabulary class to load from.
   *
   * @return string
   *   The vocabulary label.
   */
  private function getVocabularyLabel($term, $vocabularyInterface) : string {
    return $vocabularyInterface->load($this->getVocabulary($term))->label();
  }

  /**
   * Returns the label for a given term with the vocabulary label.
   *
   * @param mixed $term
   *   The taxonomy term.
   *
   * @return string
   *   The label with the vocabulary label.
   */
  private function getLabelWithVocabularyLabel($term) : string {
    return $term->label() . ' (' . $this->getVocabularyLabel($term, $this->entityTypeManager->getStorage('taxonomy_vocabulary')) . ')';
  }

  /**
   * Returns an array of all tags.
   *
   * @return array
   *   An array of all taxonomy term IDs and labels.
   */
  public function getAllTags() : array {
    $terms = $this->termStorage->loadMultiple();
    $tagList = [];

    foreach ($terms as $term) {
      $tagList[$term->id()] = $this->getLabelWithVocabularyLabel($term);
    }

    asort($tagList);

    return $tagList;
  }

  /**
   * Get all taxonomy terms associated with events.
   *
   * @return array
   *   An array of all taxonomy term IDs and labels.
   */
  public function getEventTags(): array {
    $vocabularies = ['event_category', 'audience', 'custom_vocab', 'tags'];
    $tagList = [];
    foreach ($vocabularies as $vid) {
      $terms = $this->termStorage->loadTree($vid);
      foreach ($terms as $term) {
        $tagList[$term->tid] = $term->name . ' (' . $vid . ')';
      }
    }
    asort($tagList);
    return $tagList;
  }

  /**
   * Returns terms from the given vocabularies, grouped by vocabulary.
   *
   * Built for a select's #options as optgroups (#1316): each vocabulary's own
   * label groups its terms, so a per-content-type widget can scope its tag
   * selects to the handful of vocabularies relevant to that content type
   * instead of getAllTags()'s flat, unscoped dump of every vocabulary on the
   * site. Terms are sorted alphabetically within their group; groups appear
   * in the order $vocabulary_ids lists them.
   *
   * @param string[] $vocabulary_ids
   *   The vocabulary machine names to include, in display order.
   *
   * @return array
   *   An array keyed by vocabulary label, each value a tid => term name map.
   */
  public function getTagsForVocabularies(array $vocabulary_ids): array {
    $vocabulary_storage = $this->entityTypeManager->getStorage('taxonomy_vocabulary');
    $grouped = [];

    foreach ($vocabulary_ids as $vid) {
      $vocabulary = $vocabulary_storage->load($vid);
      if (!$vocabulary) {
        continue;
      }
      $tagList = [];
      foreach ($this->termStorage->loadTree($vid) as $term) {
        $tagList[$term->tid] = $term->name;
      }
      asort($tagList);
      $grouped[$vocabulary->label()] = $tagList;
    }

    return $grouped;
  }

  /**
   * Get taxonomy parent terms by vocabulary ID.
   *
   * @param string $vid
   *   The machine name of the vocabulary.
   * @param string|null $allLabel
   *   The label for the "no parent term selected" option (#1481: callers
   *   pass a vocabulary-specific label rather than the generic default).
   *   Defaults to a translated "-- All Items --".
   *
   * @return array
   *   An array of parent terms where the key is the term ID and
   *   the value is the term name.
   */
  public function getTaxonomyParents(string $vid, ?string $allLabel = NULL): array {
    $list = ['' => $allLabel ?? (string) $this->t('-- All Items --')];
    // Load all top-level (parent) terms for the given vocabulary ID.
    $terms = $this->termStorage->loadTree($vid, 0, 1);

    foreach ($terms as $term) {
      $list[$term->tid] = $term->name;
    }

    return $list;
  }

  /**
   * Get child taxonomy terms by parent ID.
   *
   * @param int $parentId
   *   The ID of the parent term.
   * @param string $vid
   *   The machine name of the vocabulary.
   *
   * @return array
   *   An associative array of child terms where the key is the term ID and
   *   the value is the term ID.
   */
  public function getChildTermsByParentId(int $parentId, string $vid): array {
    return $this->exposedTaxonomyFilterOptions->getDescendantTermIds($vid, $parentId);
  }

  /**
   * Returns an integer representation of the term.
   *
   * The term could be either the old Drupal way of an array with a
   * target_id attribute containing a string representation of the id, or the
   * chosen way of a string representation of the id. This ensures that the
   * decision of what should be return is handled here and not elsewhere.
   *
   * @param mixed $term
   *   The taxonomy term.
   *
   * @return int
   *   The term ID.
   */
  private function getTermId($term) : int {
    return (int) (is_array($term) ? $term['target_id'] : $term);
  }

  /**
   * Gets views widget form selectors based on which form is being loaded.
   *
   * Checks if the form is loaded via layout builder and, if so, is the
   * block reusable. This aids in setting the correct arrays and Ajax calls
   * below as the selectors are different depending on what form is being
   * loaded.
   *
   * @see https://www.drupal.org/project/drupal/issues/2758631
   */
  public function getFormSelectors($formState, $form = NULL, $entityValue = NULL) {
    $formSelectors = [];

    $rebuildValues = ($formState->isRebuilding()) ? $formState->getValues() : NULL;

    if ($formState->getCompleteForm() && str_starts_with($formState->getCompleteForm()['#form_id'], 'layout_builder_')) {
      if (isset($formState->getCompleteForm()['block_form']['#block']) && $formState->getCompleteForm()['block_form']['#block']->isReusable()) {
        // Reusable block Layout Builder form.
        $formSelectors = [
          'entity_types' => $rebuildValues['block_form']['group_user_selection']['entity_and_view_mode']['entity_types'] ?? $entityValue,
          'entity_types_ajax' => ':input[name="block_form[group_user_selection][entity_and_view_mode][entity_types]"]',
          'view_mode_input_selector' => ':input[name="block_form[group_user_selection][entity_and_view_mode][view_mode]"]',
          'view_mode_ajax' => ($form) ? $form['block_form']['group_user_selection']['entity_and_view_mode']['view_mode'] : NULL,
          'category_included_terms_ajax' => ($form) ? $form['block_form']['group_user_selection']['entity_and_view_mode']['category_included_terms'] : NULL,
          'show_category_filter_selector' => ':input[name="block_form[group_user_selection][entity_and_view_mode][exposed_filter_options][show_category_filter]"]',
          'show_search_filter_selector' => ':input[name="block_form[group_user_selection][entity_and_view_mode][exposed_filter_options][show_search_filter]"]',
          'show_audience_filter_selector' => ':input[name="block_form[group_user_selection][entity_and_view_mode][exposed_filter_options][show_audience_filter]"]',
          'show_custom_vocab_filter_selector' => ':input[name="block_form[group_user_selection][entity_and_view_mode][exposed_filter_options][show_custom_vocab_filter]"]',
          'search_scope_selector' => ':input[name="block_form[group_user_selection][entity_and_view_mode][search_scope]"]',
          'profile_field_options_show_department_selector' => ':input[name="block_form[group_user_selection][entity_and_view_mode][profile_field_options][show_department]"]',
          'profile_field_options_show_pronouns_selector' => ':input[name="block_form[group_user_selection][entity_and_view_mode][profile_field_options][show_pronouns]"]',
          'profile_field_options_show_email_selector' => ':input[name="block_form[group_user_selection][entity_and_view_mode][profile_field_options][show_email]"]',
          'post_field_options_show_eyebrow_selector' => ':input[name="block_form[group_user_selection][entity_and_view_mode][post_field_options][show_eyebrow]"]',
          'custom_vocab_included_terms_ajax' => ($form) ? $form['block_form']['group_user_selection']['entity_and_view_mode']['custom_vocab_included_terms'] : NULL,
          'massage_terms_include_array' => [
            'block_form',
            'group_user_selection',
            'filter_and_sort',
            'terms_include',
          ],
          'massage_terms_exclude_array' => [
            'block_form',
            'group_user_selection',
            'filter_and_sort',
            'terms_exclude',
          ],
          'sort_by_array' => [
            'block_form',
            'group_user_selection',
            'filter_and_sort',
            'sort_by',
          ],
          'sort_by_ajax' => ($form) ? $form['block_form']['group_user_selection']['filter_and_sort']['sort_by'] : NULL,
          'display_array' => [
            'block_form',
            'group_user_selection',
            'options',
            'display',
          ],
          'display_ajax' => ':input[name="block_form[group_user_selection][options][display]"]',
          'display_value_ajax' => $formState->getValue(
          ['block_form', 'group_user_selection', 'options', 'display']
          ),
          'limit_array' => [
            'block_form',
            'group_user_selection',
            'options',
            'limit',
          ],
          'limit_ajax' => ($form) ? $form['block_form']['group_user_selection']['options']['limit'] : NULL,
          'offset_array' => [
            'block_form',
            'group_user_selection',
            'options',
            'offset',
          ],
          'pinned_to_top' => ($form) ? $form['block_form']['group_user_selection']['filter_and_sort']['pinned_to_top'] : NULL,
          'pinned_to_top_array' => [
            'block_form',
            'group_user_selection',
            'filter_and_sort',
            'pinned_to_top',
          ],
          'pinned_to_top_selector' => ':input[name="block_form[group_user_selection][filter_and_sort][pinned_to_top]"]',
          'pin_label' => ($form) ? $form['block_form']['group_user_selection']['filter_and_sort']['pin_label'] : self::DEFAULT_PIN_LABEL,
          'pin_label_array' => [
            'settings',
            'block_form',
            'group_user_selection',
            'filter_and_sort',
            'pin_label',
          ],
        ];
      }
      else {
        // Regular block Layout Builder form.
        $entityTypesPath = $rebuildValues && isset($rebuildValues['settings']['block_form']['group_user_selection']['entity_and_view_mode']['entity_types'])
          ? $rebuildValues['settings']['block_form']['group_user_selection']['entity_and_view_mode']['entity_types']
          : NULL;
        $filterAndSortPath = $form && isset($form['settings']['block_form']['group_user_selection']['filter_and_sort'])
          ? $form['settings']['block_form']['group_user_selection']['filter_and_sort']
          : NULL;

        $formSelectors = [
          'entity_types' => $entityTypesPath ?? $entityValue,
          'entity_types_ajax' => ':input[name="settings[block_form][group_user_selection][entity_and_view_mode][entity_types]"]',
          'view_mode_input_selector' => ':input[name="settings[block_form][group_user_selection][entity_and_view_mode][view_mode]"]',
          'view_mode_ajax' => ($form) ? $form['settings']['block_form']['group_user_selection']['entity_and_view_mode']['view_mode'] : NULL,
          'category_included_terms_ajax' => ($form) ? $form['settings']['block_form']['group_user_selection']['entity_and_view_mode']['category_included_terms'] : NULL,
          'show_category_filter_selector' => ':input[name="settings[block_form][group_user_selection][entity_and_view_mode][exposed_filter_options][show_category_filter]"]',
          'show_search_filter_selector' => ':input[name="settings[block_form][group_user_selection][entity_and_view_mode][exposed_filter_options][show_search_filter]"]',
          'show_audience_filter_selector' => ':input[name="settings[block_form][group_user_selection][entity_and_view_mode][exposed_filter_options][show_audience_filter]"]',
          'show_custom_vocab_filter_selector' => ':input[name="settings[block_form][group_user_selection][entity_and_view_mode][exposed_filter_options][show_custom_vocab_filter]"]',
          'search_scope_selector' => ':input[name="settings[block_form][group_user_selection][entity_and_view_mode][search_scope]"]',
          'profile_field_options_show_department_selector' => ':input[name="settings[block_form][group_user_selection][entity_and_view_mode][profile_field_options][show_department]"]',
          'profile_field_options_show_pronouns_selector' => ':input[name="settings[block_form][group_user_selection][entity_and_view_mode][profile_field_options][show_pronouns]"]',
          'profile_field_options_show_email_selector' => ':input[name="settings[block_form][group_user_selection][entity_and_view_mode][profile_field_options][show_email]"]',
          'post_field_options_show_eyebrow_selector' => ':input[name="settings[block_form][group_user_selection][entity_and_view_mode][post_field_options][show_eyebrow]"]',
          'custom_vocab_included_terms_ajax' => ($form) ? $form['settings']['block_form']['group_user_selection']['entity_and_view_mode']['custom_vocab_included_terms'] : NULL,
          'massage_terms_include_array' => [
            'settings',
            'block_form',
            'group_user_selection',
            'filter_and_sort',
            'terms_include',
          ],
          'massage_terms_exclude_array' => [
            'settings',
            'block_form',
            'group_user_selection',
            'filter_and_sort',
            'terms_exclude',
          ],
          'sort_by_array' => [
            'settings',
            'block_form',
            'group_user_selection',
            'filter_and_sort',
            'sort_by',
          ],
          'sort_by_ajax' => ($form) ? $form['settings']['block_form']['group_user_selection']['filter_and_sort']['sort_by'] : NULL,
          'display_array' => [
            'settings',
            'block_form',
            'group_user_selection',
            'options',
            'display',
          ],
          'display_ajax' => ':input[name="settings[block_form][group_user_selection][options][display]"]',
          'display_value_ajax' => $formState->getValue(
          [
            'settings',
            'block_form',
            'group_user_selection',
            'options',
            'display',
          ]
          ),
          'limit_array' => [
            'settings',
            'block_form',
            'group_user_selection',
            'options',
            'limit',
          ],
          'limit_ajax' => ($form) ? $form['settings']['block_form']['group_user_selection']['options']['limit'] : NULL,
          'offset_array' => [
            'settings',
            'block_form',
            'group_user_selection',
            'options',
            'offset',
          ],
          'pinned_to_top_ajax' => ($filterAndSortPath && isset($filterAndSortPath['pinned_to_top'])) ? $filterAndSortPath['pinned_to_top'] : NULL,
          'pinned_to_top_array' => [
            'settings',
            'block_form',
            'group_user_selection',
            'filter_and_sort',
            'pinned_to_top',
          ],
          'pinned_to_top_selector' => ':input[name="settings[block_form][group_user_selection][filter_and_sort][pinned_to_top]"]',
          'pin_label_ajax' => ($filterAndSortPath && isset($filterAndSortPath['pin_label'])) ? $filterAndSortPath['pin_label'] : self::DEFAULT_PIN_LABEL,
          'pin_label_array' => [
            'settings',
            'block_form',
            'group_user_selection',
            'filter_and_sort',
            'pin_label',
          ],
        ];
      }
    }
    else {
      // Drupal core block form.
      $formSelectors = [
        'entity_types' => $rebuildValues['entity_types'] ?? $entityValue,
        'entity_types_ajax' => ':input[name="entity_types"]',
        'view_mode_input_selector' => ':input[name="view_mode"]',
        'view_mode_ajax' => ($form) ? $form['group_user_selection']['entity_and_view_mode']['view_mode'] : NULL,
        'category_included_terms_ajax' => ($form) ? $form['group_user_selection']['entity_and_view_mode']['category_included_terms'] : NULL,
        'show_category_filter_selector' => ':input[name="show_category_filter"]',
        'show_search_filter_selector' => ':input[name="show_search_filter"]',
        'show_audience_filter_selector' => ':input[name="show_audience_filter"]',
        'show_custom_vocab_filter_selector' => ':input[name="show_custom_vocab_filter"]',
        'search_scope_selector' => ':input[name="search_scope"]',
        'profile_field_options_show_department_selector' => ':input[name="profile_field_options[show_department]"]',
        'profile_field_options_show_pronouns_selector' => ':input[name="profile_field_options[show_pronouns]"]',
        'profile_field_options_show_email_selector' => ':input[name="profile_field_options[show_email]"]',
        'post_field_options_show_eyebrow_selector' => ':input[name="post_field_options[show_eyebrow]"]',
        'custom_vocab_included_terms_ajax' => ($form) ? $form['group_user_selection']['entity_and_view_mode']['custom_vocab_included_terms'] : NULL,
        'massage_terms_include_array' => ['terms_include'],
        'massage_terms_exclude_array' => ['terms_exclude'],
        'sort_by_array' => ['sort_by'],
        'sort_by_ajax' => ($form) ? $form['group_user_selection']['filter_and_sort']['sort_by'] : NULL,
        'display_array' => ['display'],
        'display_ajax' => ':input[name="display"]',
        'display_value_ajax' => $formState->getValue(
        ['group_user_selection', 'options', 'display']
        ),
        'limit_array' => ['limit'],
        'limit_ajax' => ($form) ? $form['group_user_selection']['options']['limit'] : NULL,
        'offset_array' => ['offset'],
        'pinned_to_top' => ['pinned_to_top'],
        'pinned_to_top_selector' => ':input[name="settings[block_form][group_user_selection][filter_and_sort][pinned_to_top]"]',
        'pinned_to_top_array' => ['pinned_to_top'],
        'pinned_to_top_ajax' => ($form) ? $form['group_user_selection']['filter_and_sort']['pinned_to_top'] : NULL,
        'pin_label_array' => ['pin_label'],
        'pin_label_ajax' => ($form) ? $form['settings']['block_form']['filter_and_sort']['pin_label'] : self::DEFAULT_PIN_LABEL,
      ];
    }

    return $formSelectors;
  }

}
