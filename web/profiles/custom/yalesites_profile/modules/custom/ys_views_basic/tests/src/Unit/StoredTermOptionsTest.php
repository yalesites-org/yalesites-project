<?php

namespace Drupal\Tests\ys_views_basic\Unit;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\taxonomy\TermInterface;
use Drupal\taxonomy\VocabularyInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_views_basic\ViewsBasicManager;

/**
 * Tests that stored terms outside the scoped option list stay selectable.
 *
 * @group ys_views_basic
 * @covers \Drupal\ys_views_basic\ViewsBasicManager::addStoredTermOptions
 */
class StoredTermOptionsTest extends UnitTestCase {

  /**
   * Builds a manager whose storages return the given terms and vocabulary.
   */
  protected function buildManager(array $terms): ViewsBasicManager {
    $vocab = $this->createMock(VocabularyInterface::class);
    $vocab->method('label')->willReturn('Affiliation');
    $vocab_storage = $this->createMock(EntityStorageInterface::class);
    $vocab_storage->method('load')->willReturn($vocab);
    $term_storage = $this->createMock(EntityStorageInterface::class);
    $term_storage->method('loadMultiple')->willReturn($terms);
    $etm = $this->createMock(EntityTypeManagerInterface::class);
    $etm->method('getStorage')->willReturnMap([['taxonomy_vocabulary', $vocab_storage]]);

    $manager = (new \ReflectionClass(ViewsBasicManager::class))->newInstanceWithoutConstructor();
    foreach (['termStorage' => $term_storage, 'entityTypeManager' => $etm] as $prop => $value) {
      $ref = new \ReflectionProperty(ViewsBasicManager::class, $prop);
      $ref->setAccessible(TRUE);
      $ref->setValue($manager, $value);
    }
    return $manager;
  }

  /**
   * A stored term missing from the options is added under its vocabulary.
   */
  public function testMissingStoredTermIsAdded(): void {
    $term = $this->createMock(TermInterface::class);
    $term->method('id')->willReturn('42');
    $term->method('bundle')->willReturn('affiliation');
    $term->method('label')->willReturn('Faculty and Staff');
    $manager = $this->buildManager([42 => $term]);

    $result = $manager->addStoredTermOptions(['Tags' => [1 => 'One']], ['1', '42']);

    $this->assertSame('Faculty and Staff', $result['Affiliation']['42']);
    $this->assertSame('One', $result['Tags'][1]);
  }

  /**
   * Terms already offered, and empty values, change nothing.
   */
  public function testOfferedTermsLeaveOptionsUnchanged(): void {
    $manager = $this->buildManager([]);
    $options = ['Tags' => [1 => 'One']];

    $this->assertSame($options, $manager->addStoredTermOptions($options, ['1', '', NULL]));
  }

}
