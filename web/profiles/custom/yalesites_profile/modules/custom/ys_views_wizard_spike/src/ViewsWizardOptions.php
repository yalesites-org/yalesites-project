<?php

namespace Drupal\ys_views_wizard_spike;

use Drupal\Core\Block\BlockManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\Context\ContextInterface;
use Drupal\Core\Plugin\Context\ContextRepositoryInterface;
use Drupal\layout_builder\SectionStorageInterface;
use Drupal\ys_views_basic\ViewsBasicManager;

/**
 * Answers the two wizard questions for a specific Layout Builder region.
 *
 * SPIKE ONLY - see the module README. This class exists so the form does not
 * have to know anything about block plugin filtering, and so the same
 * region-aware option list can be reused by a second prototype.
 *
 * Two things it deliberately does NOT do:
 * - It does not restate the content-type / display-mode map. Options come from
 *   ViewsBasicManager::entityTypeList() and ::viewModeList(), which is also
 *   what the existing authoring widget uses, so labels and icons stay
 *   identical to the form editors already know.
 * - It does not reimplement Layout Builder's placement restrictions. It asks
 *   the block manager for the same filtered definition list the block browser
 *   asks for, so layout_builder_restrictions'
 *   entity_view_mode_restriction_by_region plugin is honoured for free.
 */
class ViewsWizardOptions {

  /**
   * Bundle used when the per-(type, display mode) bundle does not exist yet.
   *
   * The 13 bundles from issues #1164-#1167 are not built yet, so today every
   * combination resolves to the single legacy `view` bundle and the choice is
   * carried across the handoff as a seed instead of as the plugin ID. Once
   * those bundles land this constant becomes dead and resolvePluginId()
   * returns a real per-combination plugin ID with no seeding at all.
   */
  const LEGACY_BUNDLE = 'view';

  /**
   * Block plugin ID of the picker tile the wizard takes over.
   */
  const TARGET_PLUGIN = 'inline_block:' . self::LEGACY_BUNDLE;

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
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

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
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\ys_views_basic\ViewsBasicManager $views_basic_manager
   *   The views basic manager.
   */
  public function __construct(BlockManagerInterface $block_manager, ContextRepositoryInterface $context_repository, EntityTypeManagerInterface $entity_type_manager, ViewsBasicManager $views_basic_manager) {
    $this->blockManager = $block_manager;
    $this->contextRepository = $context_repository;
    $this->entityTypeManager = $entity_type_manager;
    $this->viewsBasicManager = $views_basic_manager;
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
   * @return string
   *   A block plugin ID suitable for layout_builder.add_block.
   */
  public function resolvePluginId(string $content_type, string $view_mode): string {
    return 'inline_block:' . $this->resolveBundle($content_type, $view_mode);
  }

  /**
   * Resolves a (content type, display mode) pair to a block_content bundle.
   *
   * Uses the `{content_type}_{view_mode}` convention from epic #1161, falling
   * back to the single legacy bundle while those bundles do not exist.
   *
   * @param string $content_type
   *   The content type machine name.
   * @param string $view_mode
   *   The display mode machine name.
   *
   * @return string
   *   A block_content bundle machine name.
   */
  public function resolveBundle(string $content_type, string $view_mode): string {
    $bundle = $content_type . '_' . $view_mode;
    $storage = $this->entityTypeManager->getStorage('block_content_type');
    if ($storage->load($bundle) && $this->blockManager->hasDefinition('inline_block:' . $bundle)) {
      return $bundle;
    }
    return self::LEGACY_BUNDLE;
  }

  /**
   * Whether a pair still needs its answers seeded into the configure form.
   *
   * True only while the per-combination bundle is missing, i.e. exactly until
   * #1164-#1167 land.
   *
   * @param string $content_type
   *   The content type machine name.
   * @param string $view_mode
   *   The display mode machine name.
   *
   * @return bool
   *   TRUE when the handoff resolved to the legacy catch-all bundle.
   */
  public function needsSeed(string $content_type, string $view_mode): bool {
    return $this->resolveBundle($content_type, $view_mode) === self::LEGACY_BUNDLE;
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
      return isset($allowed[$this->resolvePluginId($content_type, $view_mode)]);
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
   * browser, not core's ChooseBlockController. Measured on node 1 delta 2
   * region content: core's argument set returns 9 definitions and omits
   * inline_block:view entirely, the browser's returns 46 and includes it.
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
