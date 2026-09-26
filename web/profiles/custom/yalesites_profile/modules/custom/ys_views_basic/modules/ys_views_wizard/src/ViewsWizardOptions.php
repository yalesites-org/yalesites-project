<?php

namespace Drupal\ys_views_wizard;

use Drupal\Core\Block\BlockManagerInterface;
use Drupal\Core\Plugin\Context\ContextInterface;
use Drupal\Core\Plugin\Context\ContextRepositoryInterface;
use Drupal\layout_builder\SectionStorageInterface;
use Drupal\ys_views_basic\ViewsBasicManager;

/**
 * Answers the two wizard questions for a specific Layout Builder region.
 *
 * This class exists so the form does not have to know anything about block
 * plugin filtering.
 *
 * Three things it deliberately does NOT do:
 * - It does not restate the content-type / display-mode map. Options come from
 *   ViewsBasicManager::entityTypeList() and ::viewModeList(), which is also
 *   what the existing authoring widget uses, so labels and icons stay
 *   identical to the form editors already know.
 * - It does not restate the (content type, display mode) -> bundle map either.
 *   That map is published by ViewsBasicManager::LISTING_BUNDLES, and this
 *   class reads it rather than concatenating bundle ids by convention.
 * - It does not reimplement Layout Builder's placement restrictions. It asks
 *   the block manager for the same filtered definition list the block browser
 *   asks for, so layout_builder_restrictions'
 *   entity_view_mode_restriction_by_region plugin is honoured for free.
 */
class ViewsWizardOptions {

  /**
   * ID of the block browser category that hosts the single wizard entry.
   *
   * The matching layout_builder_browser_blockcat config entity ships in this
   * module's config/install, so the category's label, weight and open state
   * stay editable in the block browser UI instead of being frozen here. That
   * entity declares an enforced module dependency on this module, so
   * uninstalling the wizard takes its category with it.
   */
  const CATEGORY_ID = 'content_listings';

  /**
   * Label shown on the single wizard entry.
   */
  const LINK_LABEL = 'Content Listing';

  /**
   * The block plugin manager.
   *
   * @var \Drupal\Core\Block\BlockManagerInterface
   */
  protected $blockManager;

  /**
   * The context repository.
   *
   * @var \Drupal\Core\Plugin\Context\ContextRepositoryInterface
   */
  protected $contextRepository;

  /**
   * The views basic manager.
   *
   * @var \Drupal\ys_views_basic\ViewsBasicManager
   */
  protected $viewsBasicManager;

  /**
   * Constructs a ViewsWizardOptions.
   *
   * @param \Drupal\Core\Block\BlockManagerInterface $block_manager
   *   The block plugin manager.
   * @param \Drupal\Core\Plugin\Context\ContextRepositoryInterface $context_repository
   *   The context repository.
   * @param \Drupal\ys_views_basic\ViewsBasicManager $views_basic_manager
   *   The views basic manager.
   */
  public function __construct(BlockManagerInterface $block_manager, ContextRepositoryInterface $context_repository, ViewsBasicManager $views_basic_manager) {
    $this->blockManager = $block_manager;
    $this->contextRepository = $context_repository;
    $this->viewsBasicManager = $views_basic_manager;
  }

  /**
   * Returns every listing block plugin ID, keyed by plugin ID.
   *
   * Derived from ViewsBasicManager::LISTING_BUNDLES so the set of tiles the
   * wizard collapses can never drift from the set of bundles it can hand off
   * to. event_calendar is correctly absent: it is not a listing bundle.
   *
   * @return array
   *   Bundle machine names keyed by block plugin ID.
   */
  public static function listingPluginIds(): array {
    $plugin_ids = [];
    foreach (array_keys(ViewsBasicManager::LISTING_BUNDLES) as $bundle) {
      $plugin_ids['inline_block:' . $bundle] = $bundle;
    }
    return $plugin_ids;
  }

  /**
   * Returns the content types that have at least one placeable display mode.
   *
   * @param \Drupal\layout_builder\SectionStorageInterface $section_storage
   *   The section storage the editor is working in.
   * @param int $delta
   *   The section delta.
   * @param string $region
   *   The region machine name.
   *
   * @return array
   *   Radio options, keyed by content type machine name.
   */
  public function getContentTypeOptions(SectionStorageInterface $section_storage, int $delta, string $region): array {
    $allowed = $this->allowedPluginIds($section_storage, $delta, $region);
    $options = [];
    foreach ($this->viewsBasicManager->entityTypeList() as $type => $label) {
      if ($this->filterByAllowed($this->viewsBasicManager->viewModeList($type), $type, $allowed)) {
        $options[$type] = $label;
      }
    }
    return $options;
  }

  /**
   * Returns the display modes placeable for one content type in this region.
   *
   * @param string $content_type
   *   The content type machine name.
   * @param \Drupal\layout_builder\SectionStorageInterface $section_storage
   *   The section storage the editor is working in.
   * @param int $delta
   *   The section delta.
   * @param string $region
   *   The region machine name.
   *
   * @return array
   *   Radio options, keyed by view mode machine name.
   */
  public function getViewModeOptions(string $content_type, SectionStorageInterface $section_storage, int $delta, string $region): array {
    if (!isset(ViewsBasicManager::ALLOWED_ENTITIES[$content_type])) {
      return [];
    }
    return $this->filterByAllowed(
      $this->viewsBasicManager->viewModeList($content_type),
      $content_type,
      $this->allowedPluginIds($section_storage, $delta, $region)
    );
  }

  /**
   * Resolves a (content type, display mode) pair to a block plugin ID.
   *
   * @param string $content_type
   *   The content type machine name.
   * @param string $view_mode
   *   The display mode machine name.
   *
   * @return string|null
   *   A block plugin ID suitable for layout_builder.add_block, or NULL when
   *   the pair has no listing bundle.
   */
  public function resolvePluginId(string $content_type, string $view_mode): ?string {
    $bundle = $this->resolveBundle($content_type, $view_mode);
    return $bundle ? 'inline_block:' . $bundle : NULL;
  }

  /**
   * Resolves a (content type, display mode) pair to a block_content bundle.
   *
   * Reverse lookup over ViewsBasicManager::LISTING_BUNDLES, which is the
   * single source of truth for that mapping.
   *
   * @param string $content_type
   *   The content type machine name.
   * @param string $view_mode
   *   The display mode machine name.
   *
   * @return string|null
   *   A block_content bundle machine name, or NULL when the pair has none.
   */
  public function resolveBundle(string $content_type, string $view_mode): ?string {
    foreach (ViewsBasicManager::LISTING_BUNDLES as $bundle => $definition) {
      if ($definition['content_type'] === $content_type && $definition['view_mode'] === $view_mode) {
        return $bundle;
      }
    }
    return NULL;
  }

  /**
   * Filters a view mode option list down to placeable combinations.
   *
   * @param array $view_modes
   *   View mode options keyed by machine name.
   * @param string $content_type
   *   The content type machine name.
   * @param array $allowed
   *   Block plugin IDs placeable in the target region, keyed by plugin ID.
   *
   * @return array
   *   The subset of $view_modes that resolves to a placeable plugin.
   */
  protected function filterByAllowed(array $view_modes, string $content_type, array $allowed): array {
    return array_filter($view_modes, function ($label, $view_mode) use ($content_type, $allowed) {
      $plugin_id = $this->resolvePluginId($content_type, $view_mode);
      return $plugin_id !== NULL && isset($allowed[$plugin_id]);
    }, ARRAY_FILTER_USE_BOTH);
  }

  /**
   * Returns the block plugin IDs placeable in one region.
   *
   * Mirrors layout_builder_browser's BrowserController::browse() so every
   * restriction module that hooks plugin filtering applies to the wizard as
   * well.
   *
   * The `list` and `browse` extras are load bearing and must match the
   * browser, not core's ChooseBlockController.
   *
   * @param \Drupal\layout_builder\SectionStorageInterface $section_storage
   *   The section storage the editor is working in.
   * @param int $delta
   *   The section delta.
   * @param string $region
   *   The region machine name.
   *
   * @return array
   *   Block plugin definitions, keyed by plugin ID.
   */
  protected function allowedPluginIds(SectionStorageInterface $section_storage, int $delta, string $region): array {
    return $this->blockManager->getFilteredDefinitions('layout_builder', $this->populatedContexts($section_storage), [
      'section_storage' => $section_storage,
      'delta' => $delta,
      'region' => $region,
      'list' => 'inline_blocks',
      'browse' => TRUE,
    ]);
  }

  /**
   * Returns the populated contexts for a section storage.
   *
   * Copied from LayoutBuilderContextTrait, which is @internal and cannot be
   * used from a service that injects its dependencies.
   *
   * @param \Drupal\layout_builder\SectionStorageInterface $section_storage
   *   The section storage.
   *
   * @return \Drupal\Core\Plugin\Context\ContextInterface[]
   *   The populated contexts.
   */
  protected function populatedContexts(SectionStorageInterface $section_storage): array {
    $available_context_ids = array_keys($this->contextRepository->getAvailableContexts());
    $contexts = array_filter($this->contextRepository->getRuntimeContexts($available_context_ids), function (ContextInterface $context) {
      return $context->hasContextValue();
    });
    return $contexts + $section_storage->getContextsDuringPreview();
  }

}
