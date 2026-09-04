<?php

namespace Drupal\Tests\ys_layouts\Unit;

use Drupal\Core\Access\AccessManagerInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Tests\UnitTestCase;
use Drupal\layout_builder\SectionStorageInterface;
use Drupal\ys_layouts\SectionCloneLinkBuilder;

/**
 * Tests the "Clone section" link added to the Layout Builder section toolbar.
 *
 * Layout Builder sections carry no contextual-link group — core emits the
 * Configure and Remove actions as plain links from
 * LayoutBuilder::buildAdministrativeSection() — so the clone action cannot be
 * declared in *.links.contextual.yml the way the block-level "Clone" is. It is
 * injected by a pre-render instead, which is what this test exercises.
 *
 * @group yalesites
 * @group ys_layouts
 *
 * @coversDefaultClass \Drupal\ys_layouts\SectionCloneLinkBuilder
 */
class SectionCloneLinkBuilderTest extends UnitTestCase {

  /**
   * Whether the mocked access manager grants the clone route.
   *
   * @var bool
   */
  protected bool $routeAccess = TRUE;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // The link is titled with t(), which needs the translation service, and
    // its '#access' comes from Url::access(), which needs the access manager.
    $access_manager = $this->createMock(AccessManagerInterface::class);
    $access_manager->method('checkNamedRoute')
      ->willReturnCallback(fn () => $this->routeAccess);

    $container = new ContainerBuilder();
    $container->set('string_translation', $this->getStringTranslationStub());
    $container->set('access_manager', $access_manager);
    \Drupal::setContainer($container);
  }

  /**
   * Builds a section storage matching the fixtures' route parameters.
   *
   * @return \Drupal\layout_builder\SectionStorageInterface|\PHPUnit\Framework\MockObject\MockObject
   *   The mocked section storage carried on the element.
   */
  protected function sectionStorage() {
    $section_storage = $this->createMock(SectionStorageInterface::class);
    $section_storage->method('getStorageType')->willReturn('overrides');
    $section_storage->method('getStorageId')->willReturn('node.6');

    return $section_storage;
  }

  /**
   * Builds a built section child, mirroring core's render array.
   *
   * @param int $delta
   *   The section delta.
   * @param string|null $label
   *   The section label core would have computed, defaulting to "Section N".
   *
   * @return array
   *   A render array shaped like a built Layout Builder section.
   *
   * @see \Drupal\layout_builder\Element\LayoutBuilder::buildAdministrativeSection()
   */
  protected function builtSection(int $delta, ?string $label = NULL): array {
    $label = $label ?? 'Section ' . ($delta + 1);

    return [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['layout-builder__section'],
        'role' => 'group',
        // Core labels the section container with the same string it titles the
        // Configure and Remove actions with.
        'aria-label' => $label,
      ],
      'remove' => ['#type' => 'link', '#title' => 'Remove ' . $label],
      'configure' => ['#type' => 'link', '#title' => 'Configure ' . $label],
      'layout-builder__section' => [
        '#markup' => 'section body',
        // Core stamps the delta on the section body.
        '#attributes' => [
          'class' => ['layout-builder__layout'],
          'data-layout-delta' => $delta,
        ],
      ],
    ];
  }

  /**
   * Builds an "add section" child, mirroring core's render array.
   *
   * @return array
   *   A render array shaped like a Layout Builder add-section placeholder.
   *
   * @see \Drupal\layout_builder\Element\LayoutBuilder::buildAddSectionLink()
   */
  protected function addSectionLink(): array {
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['layout-builder__add-section']],
      'link' => ['#type' => 'link', '#title' => 'Add section'],
    ];
  }

  /**
   * Builds a whole layout_builder element with two sections.
   *
   * @return array
   *   The element passed to the pre-render callback.
   */
  protected function element(): array {
    return [
      '#section_storage' => $this->sectionStorage(),
      'layout_builder' => [
        0 => $this->addSectionLink(),
        1 => $this->builtSection(0),
        2 => $this->addSectionLink(),
        3 => $this->builtSection(1),
        4 => $this->addSectionLink(),
      ],
    ];
  }

  /**
   * Every built section gains a clone link pointing at its own delta.
   *
   * @covers ::preRender
   */
  public function testEveryBuiltSectionGainsCloneLink(): void {
    $element = SectionCloneLinkBuilder::preRender($this->element());

    foreach ([1 => 0, 3 => 1] as $key => $delta) {
      $this->assertArrayHasKey('clone', $element['layout_builder'][$key], "Section at key $key has a clone link.");

      $clone = $element['layout_builder'][$key]['clone'];
      $this->assertSame('link', $clone['#type'], 'The clone action is a link element.');
      $this->assertSame('ys_layouts.clone_section', $clone['#url']->getRouteName(), 'The clone link targets the section clone route.');
      $this->assertSame(
        [
          'section_storage_type' => 'overrides',
          'section_storage' => 'node.6',
          'delta' => $delta,
        ],
        $clone['#url']->getRouteParameters(),
        'The clone link resolves its parameters from the section storage and its own delta.'
      );
    }
  }

  /**
   * The clone link carries the classes the section toolbar styles.
   *
   * `use-ajax` is what makes the action rebuild the layout in place instead of
   * navigating away; the modifier class is what the toolbar icon hangs off.
   *
   * @covers ::preRender
   */
  public function testCloneLinkIsAjaxEnabledAndStyledAsSectionAction(): void {
    $element = SectionCloneLinkBuilder::preRender($this->element());
    $classes = $element['layout_builder'][1]['clone']['#url']->getOption('attributes')['class'];

    $this->assertContains('use-ajax', $classes, 'The clone link rebuilds the layout over AJAX.');
    $this->assertContains('layout-builder__link', $classes, 'The clone link is a section toolbar link.');
    $this->assertContains('layout-builder__link--clone', $classes, 'The clone link carries its own modifier class.');
  }

  /**
   * The link is named per section, matching Configure and Remove.
   *
   * Core gives each section action a per-section accessible name ("Configure
   * Section 1"), which is what a screen reader announces. A bare "Clone" would
   * be ambiguous when several sections are on the page.
   *
   * @covers ::preRender
   */
  public function testCloneLinkIsNamedPerSection(): void {
    $element = SectionCloneLinkBuilder::preRender($this->element());

    $this->assertSame('Clone Section 1', (string) $element['layout_builder'][1]['clone']['#title'], 'The first section clone link names its section.');
    $this->assertSame('Clone Section 2', (string) $element['layout_builder'][3]['clone']['#title'], 'The second section clone link names its section.');
  }

  /**
   * A section with its own label is named by that label, as core does.
   *
   * @covers ::preRender
   * @covers ::sectionLabel
   */
  public function testCloneLinkUsesTheSectionsOwnLabelWhenSet(): void {
    $element = [
      '#section_storage' => $this->sectionStorage(),
      'layout_builder' => [0 => $this->builtSection(0, 'Hero banner')],
    ];

    $element = SectionCloneLinkBuilder::preRender($element);

    $this->assertSame(
      'Clone Hero banner',
      (string) $element['layout_builder'][0]['clone']['#title'],
      'A labelled section is cloned by name.'
    );
  }

  /**
   * A section with no label falls back to its number.
   *
   * @covers ::preRender
   * @covers ::sectionLabel
   */
  public function testCloneLinkFallsBackToTheSectionNumber(): void {
    $section = $this->builtSection(2);
    unset($section['#attributes']['aria-label']);
    $element = [
      '#section_storage' => $this->sectionStorage(),
      'layout_builder' => [0 => $section],
    ];

    $element = SectionCloneLinkBuilder::preRender($element);

    $this->assertSame(
      'Clone Section 3',
      (string) $element['layout_builder'][0]['clone']['#title'],
      'An unlabelled section is cloned by number.'
    );
  }

  /**
   * The action does not depend on the other section actions being present.
   *
   * Core gates `configure` on the layout having a settings form, and
   * layout_builder_lock unsets `remove` on any locked section for users without
   * `remove sections with lock settings`. Deriving identity from either would
   * make the clone action vanish for reasons unrelated to whether cloning is
   * allowed, so this asserts it survives both being gone.
   *
   * @covers ::preRender
   */
  public function testCloneLinkDoesNotDependOnSiblingSectionActions(): void {
    $section = $this->builtSection(0);
    unset($section['configure'], $section['remove']);
    $element = [
      '#section_storage' => $this->sectionStorage(),
      'layout_builder' => [0 => $section],
    ];

    $element = SectionCloneLinkBuilder::preRender($element);

    $this->assertArrayHasKey('clone', $element['layout_builder'][0], 'The section still gains a clone link.');
    $this->assertSame(
      [
        'section_storage_type' => 'overrides',
        'section_storage' => 'node.6',
        'delta' => 0,
      ],
      $element['layout_builder'][0]['clone']['#url']->getRouteParameters(),
      'The clone link still resolves its section route parameters.'
    );
  }

  /**
   * The link is hidden when the clone route refuses access.
   *
   * A locked section is the real case: CloneSectionAccessCheck forbids the
   * route, and the toolbar must not advertise an action that would be refused.
   *
   * @covers ::preRender
   */
  public function testCloneLinkIsHiddenWhenTheRouteForbidsAccess(): void {
    $this->routeAccess = FALSE;

    $element = SectionCloneLinkBuilder::preRender($this->element());

    $this->assertFalse($element['layout_builder'][1]['clone']['#access'], 'The clone link is hidden on a section the user may not clone.');
    $this->assertNotContains(
      'ys-has-clone-action',
      $element['layout_builder'][1]['#attributes']['class'],
      'A section that may not be cloned is not marked, so the stylesheet leaves Configure where it is.'
    );
  }

  /**
   * The link is shown when the clone route grants access.
   *
   * @covers ::preRender
   */
  public function testCloneLinkIsShownWhenTheRouteAllowsAccess(): void {
    $element = SectionCloneLinkBuilder::preRender($this->element());

    $this->assertTrue($element['layout_builder'][1]['clone']['#access'], 'The clone link is shown on a section the user may clone.');
    $this->assertContains(
      'ys-has-clone-action',
      $element['layout_builder'][1]['#attributes']['class'],
      'A cloneable section is marked so the stylesheet clears a slot for the action.'
    );
  }

  /**
   * The toolbar styling for the new action is attached with it.
   *
   * @covers ::preRender
   */
  public function testCloneStylingLibraryIsAttached(): void {
    $element = SectionCloneLinkBuilder::preRender($this->element());

    $this->assertContains(
      'ys_layouts/clone_section',
      $element['#attached']['library'],
      'The clone action attaches its own icon styling.'
    );
  }

  /**
   * The section body stays last so the toolbar links render above it.
   *
   * @covers ::preRender
   */
  public function testSectionBodyRemainsTheLastChild(): void {
    $element = SectionCloneLinkBuilder::preRender($this->element());

    foreach ([1, 3] as $key) {
      $keys = array_keys($element['layout_builder'][$key]);
      $this->assertSame(
        'layout-builder__section',
        end($keys),
        "The section body is still the last child of the section at key $key."
      );
    }
  }

  /**
   * Clone is ordered between Remove and Configure.
   *
   * The stylesheet lays the actions out Remove, Clone, Configure across the top
   * of the section, so the markup has to agree: a control that reads second but
   * takes focus third is a focus-order defect (WCAG 2.4.3).
   *
   * @covers ::preRender
   */
  public function testCloneLinkIsOrderedBetweenRemoveAndConfigure(): void {
    $element = SectionCloneLinkBuilder::preRender($this->element());

    foreach ([1, 3] as $key) {
      $actions = array_values(array_filter(
        array_keys($element['layout_builder'][$key]),
        fn ($child_key) => in_array($child_key, ['remove', 'clone', 'configure'], TRUE)
      ));
      $this->assertSame(
        ['remove', 'clone', 'configure'],
        $actions,
        "Clone sits between Remove and Configure in the section at key $key."
      );
    }
  }

  /**
   * With no Configure action, Clone still precedes the section body.
   *
   * Not a hypothetical: layout_builder_lock unsets `configure` on any section
   * carrying LOCKED_SECTION_CONFIGURE and its pre-render runs before this one,
   * so for an editor this is the normal path on every content-locked section
   * the platform ships. (Core itself always emits the key and gates it with
   * `#access`, which is why the fixture has to unset it explicitly.)
   *
   * @covers ::preRender
   */
  public function testCloneLinkPrecedesTheBodyWhenConfigureIsAbsent(): void {
    $element = $this->element();
    unset($element['layout_builder'][1]['configure']);

    $element = SectionCloneLinkBuilder::preRender($element);
    $keys = array_keys($element['layout_builder'][1]);

    $this->assertContains('clone', $keys, 'The clone link is still added.');
    $this->assertLessThan(
      array_search('layout-builder__section', $keys, TRUE),
      array_search('clone', $keys, TRUE),
      'The clone link is ordered before the section body.'
    );
  }

  /**
   * Placeholder "add section" rows do not get a clone link.
   *
   * @covers ::preRender
   */
  public function testAddSectionPlaceholdersAreLeftAlone(): void {
    $element = SectionCloneLinkBuilder::preRender($this->element());

    foreach ([0, 2, 4] as $key) {
      $this->assertArrayNotHasKey('clone', $element['layout_builder'][$key], "The add-section placeholder at key $key is untouched.");
    }
  }

  /**
   * An element without a built layout is returned unchanged.
   *
   * @covers ::preRender
   */
  public function testElementWithoutSectionsIsUnchanged(): void {
    $element = ['#type' => 'layout_builder', '#section_storage' => $this->sectionStorage()];

    $this->assertSame($element, SectionCloneLinkBuilder::preRender($element), 'An element with no built sections is untouched.');
  }

  /**
   * An element without section storage is returned unchanged.
   *
   * @covers ::preRender
   */
  public function testElementWithoutSectionStorageIsUnchanged(): void {
    $element = ['layout_builder' => [0 => $this->builtSection(0)]];

    $this->assertSame($element, SectionCloneLinkBuilder::preRender($element), 'An element with no section storage is untouched.');
  }

  /**
   * The callback is registered as trusted so the render system may call it.
   *
   * @covers ::trustedCallbacks
   */
  public function testPreRenderIsTrustedCallback(): void {
    $this->assertContains('preRender', SectionCloneLinkBuilder::trustedCallbacks(), 'preRender is declared as a trusted callback.');
  }

}
