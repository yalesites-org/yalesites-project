<?php

namespace Drupal\Tests\ys_views_basic\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\ys_views_basic\ViewsBasicManager;

/**
 * Tests how the scaffold view arguments and stored params are read back.
 *
 * On the views/ajax path both come from the browser, so anything malformed
 * must be refused or fall back rather than reach the view as a TypeError.
 *
 * @coversDefaultClass \Drupal\ys_views_basic\ViewsBasicManager
 *
 * @group yalesites
 */
class ListingArgumentsTest extends UnitTestCase {

  /**
   * Minimal valid params for a post listing.
   */
  protected function validParams(): array {
    return [
      'filters' => [
        'types' => ['post'],
        'terms_include' => [5, ['target_id' => '7']],
        'terms_exclude' => [],
      ],
      'sort_by' => 'field_publish_date:DESC',
      'view_mode' => 'card',
      'display' => 'pager',
      'limit' => 10,
      'exposed_filter_options' => ['show_search_filter' => 'show_search_filter'],
    ];
  }

  /**
   * Valid params decode to the same array.
   *
   * @covers ::decodeParams
   */
  public function testDecodeParamsAcceptsValidParams(): void {
    $params = $this->validParams();
    $this->assertSame($params, ViewsBasicManager::decodeParams(json_encode($params)));
  }

  /**
   * Malformed params are refused.
   *
   * @covers ::decodeParams
   *
   * @dataProvider malformedParamsProvider
   */
  public function testDecodeParamsRefusesMalformedParams(mixed $params): void {
    $this->assertNull(ViewsBasicManager::decodeParams($params));
  }

  /**
   * Data provider for ::testDecodeParamsRefusesMalformedParams().
   */
  public static function malformedParamsProvider(): array {
    $valid = [
      'filters' => ['types' => ['post']],
      'sort_by' => 'field_publish_date:DESC',
    ];
    $with = function (array $change) use ($valid): string {
      return json_encode(array_replace_recursive($valid, $change));
    };
    $cases = [
      'not a string' => [['filters' => ['types' => ['post']]]],
      'NULL' => [NULL],
      'invalid JSON' => ['{not json'],
      'JSON scalar' => ['5'],
      'JSON string' => ['"post"'],
      'no filters' => [json_encode(['sort_by' => 'x'])],
      'filters is a string' => [json_encode(['filters' => 'post'])],
      'no types' => [json_encode(['filters' => []])],
      'types is a string' => [json_encode(['filters' => ['types' => 'post']])],
      'types is empty' => [json_encode(['filters' => ['types' => []]])],
      'types holds an array' => [json_encode(['filters' => ['types' => [['post']]]])],
      'types holds a number' => [json_encode(['filters' => ['types' => [5]]])],
      'sort_by is an array' => [$with(['sort_by' => ['a']])],
      'view_mode is an array' => [$with(['view_mode' => ['card']])],
      'display is an object' => [$with(['display' => ['x' => 1]])],
      'operator is an array' => [$with(['operator' => ['+']])],
      'event_time_period is an array' => [$with(['filters' => ['event_time_period' => ['future']]])],
      'terms_include is a string' => [$with(['filters' => ['terms_include' => '5']])],
      'terms_exclude holds a nested array' => [$with(['filters' => ['terms_exclude' => [['target_id' => ['5']]]]])],
      'exposed_filter_options is a string' => [$with(['exposed_filter_options' => 'show_search_filter'])],
    ];
    return $cases;
  }

  /**
   * Every sort the widgets offer is accepted, for every content type.
   *
   * @covers ::decodeParams
   * @covers ::isAllowedSort
   */
  public function testEveryOfferedSortIsAccepted(): void {
    $count = 0;
    foreach (ViewsBasicManager::ALLOWED_ENTITIES as $type => $definition) {
      foreach (array_keys($definition['sort_by']) as $sort) {
        $params = ['filters' => ['types' => [$type]], 'sort_by' => $sort];
        $this->assertSame($params, ViewsBasicManager::decodeParams(json_encode($params)), $sort);
        $count++;
      }
    }
    $this->assertGreaterThanOrEqual(10, $count);
    // What the preview formatter writes, and a listing with no sort at all.
    $unsorted = ['filters' => ['types' => ['post']], 'sort_by' => ''];
    $this->assertNotNull(ViewsBasicManager::decodeParams(json_encode($unsorted)));
    $this->assertNotNull(ViewsBasicManager::decodeParams(json_encode(['filters' => ['types' => ['post']]])));
  }

  /**
   * A sort that is not offered, such as an injected expression, is refused.
   *
   * @covers ::decodeParams
   * @covers ::isAllowedSort
   *
   * @dataProvider refusedSortProvider
   */
  public function testUnofferedSortIsRefused(string $sort): void {
    $params = ['filters' => ['types' => ['post']], 'sort_by' => $sort];
    $this->assertNull(ViewsBasicManager::decodeParams(json_encode($params)));
    $this->assertFalse(ViewsBasicManager::isAllowedSort($sort));
  }

  /**
   * Data provider for ::testUnofferedSortIsRefused().
   */
  public static function refusedSortProvider(): array {
    return [
      'blind SQL injection' => [
        '(SELECT IF(ASCII(SUBSTRING((SELECT pass FROM users_field_data WHERE uid=1),1,1))>64,SLEEP(5),0)):ASC',
      ],
      'injected direction' => ['title:ASC, (SELECT SLEEP(5))'],
      'unknown column' => ['nid:ASC'],
      'lower case direction' => ['title:asc'],
      'no direction' => ['title'],
    ];
  }

  /**
   * A short argument list from a crafted request has no usable params.
   *
   * @covers ::decodeParams
   * @covers ::usesPagerFromArgs
   */
  public function testShortArgumentListHasNoParams(): void {
    $this->assertNull(ViewsBasicManager::usesPagerFromArgs(['a', 'b']));
    $this->assertNull(ViewsBasicManager::decodeParams(['a', 'b'][ViewsBasicManager::viewArgumentIndex('original_settings')] ?? NULL));
  }

  /**
   * Builds a scaffold argument list with the given arguments set by name.
   */
  protected function args(array $named): array {
    $args = array_fill(0, count(ViewsBasicManager::VIEW_ARGUMENT_ORDER), '');
    foreach ($named as $name => $value) {
      $args[ViewsBasicManager::viewArgumentIndex($name)] = $value;
    }
    return $args;
  }

  /**
   * The pager flag is read from the stored params in the arguments.
   *
   * @covers ::usesPagerFromArgs
   */
  public function testUsesPagerFromArgs(): void {
    $params = $this->validParams();
    $this->assertTrue(ViewsBasicManager::usesPagerFromArgs($this->args(['original_settings' => json_encode($params)])));
    $params['display'] = 'all';
    $this->assertFalse(ViewsBasicManager::usesPagerFromArgs($this->args(['original_settings' => json_encode($params)])));
    unset($params['display']);
    $this->assertFalse(ViewsBasicManager::usesPagerFromArgs($this->args(['original_settings' => json_encode($params)])));
    $this->assertNull(ViewsBasicManager::usesPagerFromArgs($this->args(['original_settings' => '{bad'])));
  }

  /**
   * Field display options fill every missing key from the defaults.
   *
   * @covers ::fieldDisplayOptionsFromArgs
   */
  public function testFieldDisplayOptionsFillsDefaults(): void {
    $options = ViewsBasicManager::fieldDisplayOptionsFromArgs($this->args([
      'field_display_options' => json_encode(['show_tags' => 1, 'card_size' => 'small']),
    ]));
    $this->assertSame(1, $options['show_tags']);
    $this->assertSame('small', $options['card_size']);
    $this->assertSame(0, $options['show_categories']);
    $this->assertNull($options['listing_key']);
    $this->assertSame([], array_diff_key(ViewsBasicManager::FIELD_DISPLAY_OPTION_DEFAULTS, $options), 'Every default key is present.');
  }

  /**
   * Anything that is not a JSON object yields the defaults alone.
   *
   * @covers ::fieldDisplayOptionsFromArgs
   *
   * @dataProvider garbageDisplayOptionsProvider
   */
  public function testFieldDisplayOptionsGarbage(?array $args): void {
    $this->assertSame(ViewsBasicManager::FIELD_DISPLAY_OPTION_DEFAULTS, ViewsBasicManager::fieldDisplayOptionsFromArgs($args ?? []));
  }

  /**
   * Data provider for ::testFieldDisplayOptionsGarbage().
   */
  public static function garbageDisplayOptionsProvider(): array {
    $index = ViewsBasicManager::viewArgumentIndex('field_display_options');
    $at = function ($value) use ($index): array {
      $args = array_fill(0, $index + 1, '');
      $args[$index] = $value;
      return $args;
    };
    return [
      'no arguments' => [NULL],
      'argument missing' => [array_fill(0, $index, '')],
      'invalid JSON' => [$at('{nope')],
      'JSON scalar' => [$at('5')],
      'JSON null' => [$at('null')],
      'not a string' => [$at(['show_tags' => 1])],
    ];
  }

  /**
   * The other readers share the helper's fallback.
   *
   * @covers ::incomingDisplayContext
   * @covers ::listingKeyFromArgs
   */
  public function testReadersFallBackOnGarbage(): void {
    $args = $this->args(['field_display_options' => '7']);
    $this->assertSame([
      'block_has_heading' => FALSE,
      'block_uuid' => NULL,
      'host_nid' => NULL,
    ], ViewsBasicManager::incomingDisplayContext($args));
    $this->assertNull(ViewsBasicManager::listingKeyFromArgs($args));
  }

}
