<?php

namespace Drupal\ys_layouts\Controller;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Pager\PagerManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\ys_layouts\Service\OrphanedInlineBlockCleanerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Reports Layout Builder inline blocks no layout references any more.
 *
 * The sweep itself is OrphanedInlineBlockCleaner and predates this screen; it
 * was reachable only as `drush ys-layouts:orphaned-blocks`, which meant someone
 * with terminal access had to run it site by site. This controller is the route
 * onto an individual site for whoever administers it, and it deliberately keeps
 * the command's posture: it only ever reports. Deleting is a separate,
 * explicitly confirmed step in OrphanedInlineBlockDeleteForm.
 *
 * @see \Drupal\ys_layouts\Service\OrphanedInlineBlockCleaner
 * @see \Drupal\ys_layouts\Form\OrphanedInlineBlockDeleteForm
 */
class OrphanedInlineBlockReportController implements ContainerInjectionInterface {

  use StringTranslationTrait;

  /**
   * How many blocks each table lists per page.
   */
  const ITEMS_PER_PAGE = 50;

  /**
   * Pager element index for the deletable orphans table.
   */
  const ORPHANS_PAGER = 0;

  /**
   * Pager element index for the revision-only table.
   *
   * Distinct from ORPHANS_PAGER because both tables render on one page: sharing
   * an element would make paging either table move both.
   */
  const REVISION_ONLY_PAGER = 1;

  /**
   * Constructs an OrphanedInlineBlockReportController.
   *
   * @param \Drupal\ys_layouts\Service\OrphanedInlineBlockCleanerInterface $cleaner
   *   The orphaned inline block cleaner.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Pager\PagerManagerInterface $pagerManager
   *   The pager manager.
   */
  public function __construct(
    protected OrphanedInlineBlockCleanerInterface $cleaner,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected PagerManagerInterface $pagerManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('ys_layouts.orphaned_inline_block_cleaner'),
      $container->get('entity_type.manager'),
      $container->get('pager.manager'),
    );
  }

  /**
   * Builds the orphaned inline block report.
   *
   * @return array
   *   A render array.
   */
  public function build(): array {
    $report = $this->cleaner->analyze();

    $build = [
      // The report is a live read of the database that any layout save can
      // invalidate, and it is the basis for a destructive action, so a cached
      // copy would show counts that are no longer true.
      '#cache' => ['max-age' => 0],
    ];

    $build['description'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Removing a non-reusable block from a page leaves its content behind in the database, where no other admin screen can reach it. Drupal cannot clean these up on its own for a page that still exists. Nothing on this screen changes anything until you confirm a deletion.'),
    ];

    // Header cells carry an explicit scope: template_preprocess_table() hands a
    // plain string header an empty Attribute bag, so a bare string renders as a
    // <th> with no scope at all. These are two real data tables, so the column
    // association is worth stating rather than left to inference.
    $build['orphans'] = [
      '#type' => 'table',
      // Both captions carry the full total because the tables are paged: the
      // rows on screen are only a window onto the set.
      '#caption' => $this->t('Unreferenced inline blocks (@count total)', [
        '@count' => count($report['orphans']),
      ]),
      '#header' => [
        ['data' => $this->t('Block ID'), 'scope' => 'col'],
        ['data' => $this->t('Type'), 'scope' => 'col'],
        ['data' => $this->t('Label'), 'scope' => 'col'],
      ],
      '#rows' => $this->describe($this->currentPageOf($report['orphans'], self::ORPHANS_PAGER)),
      '#empty' => $this->t('No orphaned inline blocks found.'),
    ];
    $build['orphans_pager'] = [
      '#type' => 'pager',
      '#element' => self::ORPHANS_PAGER,
    ];

    // The delete action stays with the table it acts on, below that table and
    // its pager. Built before the revision-only table for that reason:
    // rendering it last would put a delete button immediately below the one
    // table it must never touch. Its label counts every orphan, not the page on
    // screen, because the confirm step re-derives and removes the whole set.
    if ($report['orphans']) {
      $build['actions'] = [
        '#type' => 'container',
        'delete' => [
          '#type' => 'link',
          '#title' => $this->formatPlural(
            count($report['orphans']),
            'Delete 1 orphaned inline block',
            'Delete @count orphaned inline blocks'
          ),
          '#url' => Url::fromRoute('ys_layouts.orphaned_blocks_delete'),
          '#attributes' => ['class' => ['button', 'button--danger']],
        ],
      ];
    }

    if ($report['revision_only']) {
      $build['revision_only_description'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('These blocks are not on any current page, but an older revision of a page still points at them. Reverting a page to such a revision would put the block back on it, so deletion leaves them alone.'),
      ];
      $build['revision_only'] = [
        '#type' => 'table',
        // This table has no delete button to state its magnitude, so the total
        // in the caption is the only place an operator can read it.
        '#caption' => $this->t('Referenced only by an older revision — never deleted (@count total)', [
          '@count' => count($report['revision_only']),
        ]),
        '#header' => [
          ['data' => $this->t('Block ID'), 'scope' => 'col'],
          ['data' => $this->t('Type'), 'scope' => 'col'],
          ['data' => $this->t('Label'), 'scope' => 'col'],
        ],
        '#rows' => $this->describe($this->currentPageOf($report['revision_only'], self::REVISION_ONLY_PAGER)),
      ];
      $build['revision_only_pager'] = [
        '#type' => 'pager',
        '#element' => self::REVISION_ONLY_PAGER,
      ];
    }

    return $build;
  }

  /**
   * Narrows a set of block IDs to the page currently being viewed.
   *
   * Paging cannot make the sweep itself cheaper: analyze() has to walk every
   * layout revision to decide what is orphaned, so it necessarily returns the
   * whole ID set. What this bounds is the per-ID work downstream. describe()
   * loads a block_content entity for every ID it is handed, so on a site
   * carrying thousands of orphans an unpaged report loads and renders thousands
   * of entities to fill a table nobody scrolls. Sites where analyze() itself is
   * the problem still have the drush command as the escape hatch.
   *
   * The Pager returned by createPager() clamps its own current page into range,
   * so a ?page= beyond the end lands on the last page rather than an empty
   * table.
   *
   * @param int[] $block_ids
   *   The full set of block content entity IDs.
   * @param int $element
   *   The pager element index to page this set on.
   *
   * @return int[]
   *   The IDs on the current page, in the order the cleaner sorted them.
   */
  protected function currentPageOf(array $block_ids, int $element): array {
    $pager = $this->pagerManager->createPager(count($block_ids), self::ITEMS_PER_PAGE, $element);
    return array_slice($block_ids, $pager->getCurrentPage() * self::ITEMS_PER_PAGE, self::ITEMS_PER_PAGE);
  }

  /**
   * Builds table rows describing the given blocks.
   *
   * A row is emitted for every ID passed in rather than only for the ones that
   * still load; dropping one would understate what the operator is about to act
   * on. In practice every reported ID had a block_content row when the sweep
   * queried, so the fallback row only shows up if something deleted the block
   * between that query and this load.
   *
   * Callers hand this the current page's IDs, not the whole reported set - see
   * currentPageOf().
   *
   * Rows are built from $block_ids rather than from the loaded entities so the
   * order the cleaner sorted them into survives; loadMultiple() makes no
   * ordering promise.
   *
   * Deliberately not shared with YaleSitesLayoutsCommands::describe(): two
   * occurrences of three-column row building do not yet justify pulling a
   * presentation concern into the cleaner service, whose return value is a pure
   * ID set that a text table and a render array can each shape for themselves.
   * A third caller would justify extracting it.
   *
   * The two are not byte-identical, and should not be: the fallback cells here
   * are translated for an admin table ('missing', 'n/a') where the command
   * emits bare strings to a terminal. That divergence argues for extracting
   * carefully rather than eagerly — a shared helper would have to parameterise
   * the fallbacks to serve both media.
   *
   * @param int[] $block_ids
   *   The block content entity IDs.
   *
   * @return array[]
   *   Rows of block ID, block type and label.
   */
  protected function describe(array $block_ids): array {
    $blocks = $this->entityTypeManager->getStorage('block_content')->loadMultiple($block_ids);
    $rows = [];
    foreach ($block_ids as $block_id) {
      $block = $blocks[$block_id] ?? NULL;
      $rows[] = [
        $block_id,
        $block ? $block->bundle() : $this->t('missing'),
        $block ? (string) $block->label() : $this->t('n/a'),
      ];
    }
    return $rows;
  }

}
