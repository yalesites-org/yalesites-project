<?php

namespace Drupal\Tests\ys_layouts\Kernel;

use Drupal\Core\Config\FileStorage;
use Drupal\Core\Plugin\Context\Context;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Plugin\Context\EntityContext;
use Drupal\Core\Routing\RouteMatch;
use Drupal\KernelTests\KernelTestBase;
use Drupal\layout_builder\Plugin\SectionStorage\OverridesSectionStorage;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionStorageInterface;
use Drupal\layout_builder_lock\LayoutBuilderLock;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\User;
use Drupal\ys_layouts\Access\CloneSectionAccessCheck;
use Symfony\Component\Routing\Route;

/**
 * Tests "Clone section" access against the layouts the platform really ships.
 *
 * The companion unit test drives CloneSectionAccessCheck over hand-written
 * lock integers. That is necessary but was not sufficient: it agreed with the
 * rule as written while a real Post's "Title and Metadata" section — the one
 * holding the Post Meta Block — cloned successfully anyway, producing a page
 * with two titles and two publish dates. The rule was right and the *data* it
 * read was not.
 *
 * A node override does not read its locks from the default layout at runtime.
 * Core copies the default's sections into the override's layout_builder__layout
 * field when the override is created, so the override holds a point-in-time
 * *snapshot*, and nothing propagates a later change into overrides that already
 * exist. On this platform the two are known to diverge — see
 * CloneSectionAccessCheck's docblock for the mechanism — so a Post saved before
 * the current lock set was configured kept the old one, and that is the node
 * the reviewer cloned.
 *
 * So this test drives the check against the shipped
 * core.entity_view_display.node.*.default configuration, read from the
 * profile's config/sync directory, through core's real
 * OverridesSectionStorage — once with an override that matches the default, and
 * once with an override whose locks have gone stale in exactly that way. The
 * second case is the regression test: it fails on every locked section without
 * the fix.
 *
 * Only the sections' lock settings are taken verbatim from the shipped config.
 * Components are dropped, layout settings are reduced to the label, and a
 * layout_id the test environment cannot resolve falls back to layout_onecol:
 * saving a display whose components, layouts and layout settings reference ys_*
 * plugins and schema would mean booting the whole profile. The access check
 * reads none of them — it reads third_party_settings, which is verbatim.
 *
 * @group ys_layouts
 * @group yalesites
 *
 * @coversDefaultClass \Drupal\ys_layouts\Access\CloneSectionAccessCheck
 */
class CloneSectionAccessCheckTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'node',
    'layout_discovery',
    'layout_builder',
    'layout_builder_lock',
  ];

  /**
   * The access check under test.
   *
   * @var \Drupal\ys_layouts\Access\CloneSectionAccessCheck
   */
  protected CloneSectionAccessCheck $accessCheck;

  /**
   * An editor holding none of layout_builder_lock's four permissions.
   *
   * Created after a throwaway account so it is not user 1, which bypasses every
   * permission check and would make each assertion below vacuously "allowed".
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $editor;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installConfig(['user', 'node', 'field']);

    User::create(['name' => 'user1', 'status' => 1])->save();
    $this->editor = User::create(['name' => 'editor', 'status' => 1]);
    $this->editor->save();

    $this->accessCheck = new CloneSectionAccessCheck();
  }

  /**
   * Whether each shipped section may be cloned.
   *
   * Written out rather than derived from the lock settings on purpose. An
   * expectation computed by re-running the rule under test agrees with the
   * implementation by construction — invert the rule in both places and the
   * test still passes — which is exactly the kind of agreement that let the
   * original defect through. These twelve values are the independent statement
   * of what the platform's editors should be able to do.
   *
   * A new content type, or a change to a section's locks, fails this test until
   * someone states what the new expectation is. That is the point: "should this
   * section be cloneable?" is a product question, and the answer belongs here
   * in writing.
   */
  protected const EXPECTED_CLONEABILITY = [
    'event: Title and Metadata' => FALSE,
    'event: Content Section' => TRUE,
    'page: Banner Section' => FALSE,
    'page: Title and Metadata' => FALSE,
    'page: Content Section' => TRUE,
    'post: Title and Metadata' => FALSE,
    'post: Content Section' => TRUE,
    'profile: Banner Section' => FALSE,
    'profile: Content Section' => TRUE,
    // The label's capitalisation typo is in the shipped config.
    'resource: TItle and Metadata' => FALSE,
    'resource: Content Section' => TRUE,
    'resource: Related Content' => TRUE,
  ];

  /**
   * Runs every shipped section through the access check.
   *
   * Every section is evaluated and the results compared as one map rather than
   * asserted one at a time, so a failure names every section that is wrong
   * instead of stopping at the first.
   *
   * @param callable $prepare_override
   *   Receives the shipped sections of a bundle and returns the sections to
   *   store on the node's override.
   *
   * @return bool[]
   *   Whether the check allowed each section, keyed as EXPECTED_CLONEABILITY.
   */
  protected function cloneabilityOfShippedSections(callable $prepare_override): array {
    $actual = [];

    foreach (array_keys(self::shippedDisplays()) as $bundle) {
      $default_sections = $this->shippedSectionsFor($bundle);
      $storage = $this->overrideStorage($bundle, $default_sections, $prepare_override($default_sections));

      foreach ($default_sections as $delta => $section) {
        $key = $bundle . ': ' . ($section['layout_settings']['label'] ?? "delta $delta");
        $actual[$key] = $this->accessCheck
          ->access($storage, $this->editor, $this->routeMatch($delta))
          ->isAllowed();
      }
    }

    return $actual;
  }

  /**
   * Reads the shipped node default displays that use Layout Builder.
   *
   * Read straight off disk rather than through the config factory so the data
   * under test is the YAML the profile ships, not whatever a prior test or a
   * local database happens to hold.
   *
   * @return array[]
   *   The Layout Builder sections of each display, keyed by node bundle.
   */
  protected static function shippedDisplays(): array {
    static $displays;
    if ($displays !== NULL) {
      return $displays;
    }

    // From <profile>/modules/custom/ys_layouts/tests/src/Kernel up to the
    // profile root, which is where config/sync lives.
    $storage = new FileStorage(dirname(__DIR__, 6) . '/config/sync');
    $displays = [];

    foreach ($storage->listAll('core.entity_view_display.node.') as $name) {
      if (!str_ends_with($name, '.default')) {
        continue;
      }
      $sections = $storage->read($name)['third_party_settings']['layout_builder']['sections'] ?? [];
      if (!$sections) {
        continue;
      }
      $displays[explode('.', $name)[3]] = $sections;
    }

    return $displays;
  }

  /**
   * A section carrying content locks in the shipped config refuses to clone.
   *
   * The baseline: the override matches the default, which is the state core
   * leaves an override in the moment it is created.
   *
   * @covers ::access
   */
  public function testShippedSectionCloneability(): void {
    $this->assertSame(
      self::EXPECTED_CLONEABILITY,
      $this->cloneabilityOfShippedSections(fn(array $sections) => $sections),
      'Each shipped section should be cloneable exactly when its locks are positional-only.',
    );
  }

  /**
   * A stale override cannot make a locked skeleton section cloneable.
   *
   * The regression test for the defect review found: the override's locks are
   * reduced to the positional pair — exactly what LayoutUpdater's layout_id
   * collision writes onto every Post and Event — while the default layout
   * still carries the real lock set. A section the default says is locked must
   * stay refused however the override reads. Before the fix, every locked
   * section here reported cloneable: the two-titles-two-publish-dates Post.
   *
   * @covers ::access
   */
  public function testStaleOverrideCannotUnlockShippedSections(): void {
    $positional_only = function (array $sections): array {
      foreach ($sections as &$section) {
        $section['third_party_settings']['layout_builder_lock'] = [
          'lock' => [
            LayoutBuilderLock::LOCKED_SECTION_BEFORE => LayoutBuilderLock::LOCKED_SECTION_BEFORE,
            LayoutBuilderLock::LOCKED_SECTION_AFTER => LayoutBuilderLock::LOCKED_SECTION_AFTER,
          ],
          'regions' => [],
        ];
      }
      return $sections;
    };

    $this->assertSame(
      self::EXPECTED_CLONEABILITY,
      $this->cloneabilityOfShippedSections($positional_only),
      "A section locked in the default layout must stay refused when the override's own locks have gone stale.",
    );
  }

  /**
   * No locked section can be pushed to a different delta by an editor.
   *
   * Pairing an override's section with the default layout's section at the same
   * delta is only safe while the two stay aligned, and what keeps them aligned
   * is that every locked section carries LOCKED_SECTION_BEFORE: that is what
   * removes the "add section" link above it, so an editor cannot insert one and
   * shift it down. Drop that lock from a skeleton section and the pairing can
   * be walked out of alignment — the locked section lands at a delta whose
   * default counterpart is unlocked, and the clone is allowed again.
   *
   * Nothing in the running site enforces this, so it is asserted here rather
   * than left as a property someone has to know. The set of sections checked is
   * driven by EXPECTED_CLONEABILITY, so it follows the same independent
   * statement of intent as the tests above.
   *
   * This reads config only and needs none of the fixtures the other cases do.
   */
  public function testLockedSectionsAreFencedAgainstBeingShifted(): void {
    $unfenced = [];

    foreach (self::shippedDisplays() as $bundle => $sections) {
      foreach ($sections as $delta => $section) {
        $key = $bundle . ': ' . ($section['layout_settings']['label'] ?? "delta $delta");
        if (self::EXPECTED_CLONEABILITY[$key] ?? TRUE) {
          continue;
        }

        $locks = array_filter($section['third_party_settings']['layout_builder_lock']['lock'] ?? []);
        if (!in_array(LayoutBuilderLock::LOCKED_SECTION_BEFORE, $locks)) {
          $unfenced[] = $key;
        }
      }
    }

    $this->assertSame(
      [],
      $unfenced,
      'A section that must never be cloneable also needs LOCKED_SECTION_BEFORE, or an editor can insert a section above it and shift it to a delta the default layout does not lock.'
    );
  }

  /**
   * An editor-added section beyond the default's last delta is cloneable.
   *
   * Guards the other side of pairing an override section with the default
   * section at the same delta: a section with no default counterpart has no
   * locks to inherit and must not be refused for want of one.
   *
   * @covers ::access
   */
  public function testSectionAddedInTheOverrideIsCloneable(): void {
    $default_sections = $this->shippedSectionsFor('page');
    $override_sections = $default_sections;
    $override_sections[] = [
      'layout_id' => 'layout_onecol',
      'layout_settings' => ['label' => 'Editor added'],
      'components' => [],
      'third_party_settings' => [],
    ];

    $storage = $this->overrideStorage('page', $default_sections, $override_sections);
    $delta = count($override_sections) - 1;

    $this->assertTrue(
      $this->accessCheck->access($storage, $this->editor, $this->routeMatch($delta))->isAllowed(),
      'A section an editor added in the override has no default counterpart and should be cloneable.',
    );
  }

  /**
   * The shipped sections of one bundle, reduced to what the check reads.
   *
   * @param string $bundle
   *   The node bundle.
   *
   * @return array[]
   *   Section arrays carrying the shipped lock settings and nothing else.
   */
  protected function shippedSectionsFor(string $bundle): array {
    $layouts = $this->container->get('plugin.manager.core.layout');
    $sections = [];

    foreach (self::shippedDisplays()[$bundle] as $section) {
      $layout_id = $section['layout_id'];
      $sections[] = [
        'layout_id' => $layouts->hasDefinition($layout_id) ? $layout_id : 'layout_onecol',
        // Only the label survives: the shipped layout settings also carry
        // ys_layouts_sections_config, whose schema ships with ys_layouts, and
        // saving a display with settings the active schema does not describe
        // raises SchemaIncompleteException. The label is kept because the
        // failure messages read off it.
        'layout_settings' => array_intersect_key($section['layout_settings'] ?? [], ['label' => TRUE]),
        'components' => [],
        'third_party_settings' => $section['third_party_settings'] ?? [],
      ];
    }

    return $sections;
  }

  /**
   * Builds core's real override storage over a default/override section pair.
   *
   * @param string $bundle
   *   The node bundle to create, along with its overridable default display.
   * @param array[] $default_sections
   *   The sections to store on the default display.
   * @param array[] $override_sections
   *   The sections to store on the node's layout_builder__layout field.
   *
   * @return \Drupal\layout_builder\SectionStorageInterface
   *   The override storage for a node of that bundle.
   */
  protected function overrideStorage(string $bundle, array $default_sections, array $override_sections): SectionStorageInterface {
    if (!NodeType::load($bundle)) {
      NodeType::create(['type' => $bundle, 'name' => ucfirst($bundle)])->save();
    }

    $display = $this->container->get('entity_display.repository')
      ->getViewDisplay('node', $bundle, 'default');
    $display->setOverridable()
      ->setThirdPartySetting('layout_builder', 'sections', array_map(
        [Section::class, 'fromArray'],
        $default_sections,
      ))
      ->save();

    $node = Node::create([
      'type' => $bundle,
      'title' => 'Clone access fixture',
      OverridesSectionStorage::FIELD_NAME => array_map(
        [Section::class, 'fromArray'],
        $override_sections,
      ),
    ]);
    $node->save();

    return $this->container->get('plugin.manager.layout_builder.section_storage')
      ->load('overrides', [
        'entity' => EntityContext::fromEntity($node),
        'view_mode' => new Context(new ContextDefinition('string'), 'default'),
      ]);
  }

  /**
   * A route match for the clone route at the given delta.
   *
   * @param int $delta
   *   The delta of the section being cloned.
   *
   * @return \Drupal\Core\Routing\RouteMatch
   *   A route match carrying that delta as a raw parameter, which is what the
   *   access check reads.
   */
  protected function routeMatch(int $delta): RouteMatch {
    $route = new Route('/layout_builder/clone/section/{section_storage_type}/{section_storage}/{delta}');
    return new RouteMatch('ys_layouts.clone_section', $route, ['delta' => $delta], ['delta' => $delta]);
  }

}
