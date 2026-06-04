<?php

namespace Drupal\Tests\ys_contoso_chat\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\ys_contoso_chat\Service\CitationStore;

/**
 * @coversDefaultClass \Drupal\ys_contoso_chat\Service\CitationStore
 * @group yalesites
 */
class CitationStoreTest extends UnitTestCase {

  /**
   * @covers ::getCitations
   * @covers ::hasCitations
   */
  public function testEmptyByDefault(): void {
    $store = new CitationStore();
    $this->assertSame([], $store->getCitations());
    $this->assertFalse($store->hasCitations());
  }

  /**
   * @covers ::setCitations
   * @covers ::getCitations
   * @covers ::hasCitations
   */
  public function testSetAndGetRoundTrip(): void {
    $store = new CitationStore();
    $rows = [
      ['id' => '1', 'content' => 'a'],
      ['id' => '2', 'content' => 'b'],
    ];
    $store->setCitations($rows);
    $this->assertTrue($store->hasCitations());
    $this->assertSame($rows, $store->getCitations());
  }

  /**
   * @covers ::setCitations
   */
  public function testSetReindexesKeys(): void {
    $store = new CitationStore();
    $store->setCitations([5 => ['id' => '1'], 9 => ['id' => '2']]);
    $this->assertSame([['id' => '1'], ['id' => '2']], $store->getCitations());
  }

  /**
   * @covers ::reset
   */
  public function testResetClearsCitations(): void {
    $store = new CitationStore();
    $store->setCitations([['id' => '1']]);
    $store->reset();
    $this->assertSame([], $store->getCitations());
    $this->assertFalse($store->hasCitations());
  }

}
