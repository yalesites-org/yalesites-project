<?php

namespace Drupal\Tests\ys_views_basic\Unit;

use Drupal\Tests\UnitTestCase;

/**
 * Pins the admin-only scoping of the Chosen rules in views-basic.css.
 *
 * This stylesheet is NOT admin-only. Two front-end surfaces attach
 * ys_views_basic/ys_views_basic for its JavaScript and drag this stylesheet
 * onto public pages with it: atomic's listing scaffold template
 * (templates/views/views-view--views-basic-scaffold.html.twig) and
 * EventCalendarFilterForm::buildForm(). Better Exposed Filters renders the
 * same Chosen widgets on those public pages, so any Chosen rule here that is
 * not qualified with the block-configuration scope restyles a visitor-facing
 * exposed filter.
 *
 * .gin--dark-mode alone does NOT qualify a rule: gin_toolbar injects that
 * class on the front end too for any user who can see the toolbar, which is
 * why every .gin--dark-mode .chosen-* rule in the file is additionally
 * qualified with .layout-builder-configure-block.
 *
 * @group ys_views_basic
 * @group yalesites
 */
class AdminCssScopingTest extends UnitTestCase {

  /**
   * Scopes that only ever exist inside the block-configuration UI.
   */
  private const ADMIN_SCOPES = [
    '.layout-builder-configure-block',
    '#layout-builder-modal',
  ];

  /**
   * Every Chosen rule in views-basic.css is scoped to the admin UI.
   */
  public function testChosenRulesAreScopedToTheBlockConfigurationForm(): void {
    $path = dirname(__DIR__, 3) . '/assets/css/views-basic.css';
    $css = file_get_contents($path);
    $this->assertIsString($css, "Could not read $path");

    $unscoped = $this->unscopedChosenSelectors($css);

    $this->assertSame([], $unscoped, sprintf(
      'These Chosen rules in views-basic.css are not scoped to %s, so they '
      . 'restyle Better Exposed Filters widgets on public pages: %s',
      implode(' or ', self::ADMIN_SCOPES),
      implode('; ', $unscoped)
    ));
  }

  /**
   * The guard sees leaks written in the shapes this file actually uses.
   *
   * A guard that cannot fail is worse than no guard, because a green run
   * reads as proof. An earlier version of this parser silently dropped every
   * rule nested in an at-rule, so a leak inside @media would have passed.
   *
   * @dataProvider provideLeakShapes
   */
  public function testTheGuardCatchesEachLeakShape(string $css, array $expected): void {
    $this->assertSame($expected, $this->unscopedChosenSelectors($css));
  }

  /**
   * Supplies CSS snippets alongside the selectors that should be flagged.
   */
  public static function provideLeakShapes(): array {
    $scope = '.layout-builder-configure-block';

    return [
      'bare rule' => [
        '.chosen-container-multi .chosen-choices { border: unset; }',
        ['.chosen-container-multi .chosen-choices'],
      ],
      'rule nested in @media' => [
        '@media (min-width: 48em) { .chosen-drop { color: red; } }',
        ['.chosen-drop'],
      ],
      'rule nested in @supports' => [
        '@supports (display: grid) { .chosen-results { color: red; } }',
        ['.chosen-results'],
      ],
      'grouped selector with one unscoped member' => [
        "$scope .chosen-drop,\n.chosen-results { color: red; }",
        ['.chosen-results'],
      ],
      'scoped rule nested in @media' => [
        "@media (min-width: 48em) { $scope .chosen-drop { color: red; } }",
        [],
      ],
      'gin dark mode is not an admin scope' => [
        '.gin--dark-mode .chosen-drop { color: red; }',
        ['.gin--dark-mode .chosen-drop'],
      ],
      'leak inside a comment is not a rule' => [
        '/* .chosen-drop { color: red; } */',
        [],
      ],
      'rule with no Chosen selector' => [
        '.views-basic--params { display: none; }',
        [],
      ],
    ];
  }

  /**
   * Returns the Chosen selectors in the given CSS that lack an admin scope.
   */
  private function unscopedChosenSelectors(string $css): array {
    $unscoped = [];
    foreach ($this->selectors($css) as $selector) {
      if (!str_contains($selector, 'chosen')) {
        continue;
      }
      foreach (self::ADMIN_SCOPES as $scope) {
        if (str_contains($selector, $scope)) {
          continue 2;
        }
      }
      $unscoped[] = $selector;
    }

    return $unscoped;
  }

  /**
   * Returns every individual selector in a stylesheet.
   *
   * Comments are stripped first, then at-rule preludes, so a rule nested in
   * an @media or @supports block is judged on its own selector rather than
   * being masked by the prelude. Each rule's selector list is then split on
   * commas so a grouped selector is judged one member at a time.
   *
   * The parser assumes flat, un-nested CSS, which is what this file is:
   * native CSS nesting would hide a selector from it.
   */
  private function selectors(string $css): array {
    $css = preg_replace('#/\*.*?\*/#s', '', $css);
    // At-rule preludes are transparent for scoping purposes. Dropping them
    // keeps a nested rule's own selector visible: without this, the prelude
    // is the first match in its chunk and the selector is never examined.
    $css = preg_replace('/@[a-z-]+[^;{}]*\{/i', '', $css);

    $selectors = [];
    foreach (explode('}', $css) as $block) {
      if (!preg_match('/([^;{}]*)\{/s', $block, $matches)) {
        continue;
      }
      foreach (explode(',', $matches[1]) as $selector) {
        $selector = trim(preg_replace('/\s+/', ' ', $selector));
        if ($selector !== '') {
          $selectors[] = $selector;
        }
      }
    }

    return $selectors;
  }

}
