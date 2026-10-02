<?php

namespace Drupal\Tests\ys_views_basic\Unit;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_views_basic\Form\EventCalendarFilterForm;
use Drupal\ys_views_basic\Service\EventsCalendarInterface;
use Drupal\ys_views_basic\ViewsBasicManager;

/**
 * Unit tests for the event calendar exposed filter form.
 *
 * @coversDefaultClass \Drupal\ys_views_basic\Form\EventCalendarFilterForm
 * @group ys_views_basic
 * @group yalesites
 */
class EventCalendarFilterFormTest extends UnitTestCase {

  /**
   * The views basic manager mock.
   *
   * @var \Drupal\ys_views_basic\ViewsBasicManager|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $manager;

  /**
   * The form under test.
   *
   * @var \Drupal\ys_views_basic\Form\EventCalendarFilterForm
   */
  protected $form;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->manager = $this->createMock(ViewsBasicManager::class);
    $this->form = new EventCalendarFilterForm(
      $this->manager,
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(EventsCalendarInterface::class)
    );
  }

  /**
   * Calls the private getTaxonomyOptions() on the form.
   */
  protected function options(string $vocabulary, $parent, array $excluded): array {
    $method = new \ReflectionMethod($this->form, 'getTaxonomyOptions');
    $method->setAccessible(TRUE);
    return $method->invoke($this->form, $vocabulary, $parent, $excluded);
  }

  /**
   * Excluded terms, plain or legacy-shaped, are not offered.
   *
   * @covers ::getTaxonomyOptions
   */
  public function testExcludedTermsAreRemovedFromOptions() {
    $this->manager->method('getTaxonomyParents')
      ->willReturn(['' => '- Any -', 5 => 'Five', 6 => 'Six', 7 => 'Seven']);

    $this->assertSame(
      [6 => 'Six'],
      $this->options('audience', NULL, ['5', ['target_id' => '7']])
    );
  }

  /**
   * Without exclusions the full list is still offered.
   *
   * @covers ::getTaxonomyOptions
   */
  public function testOptionsUnchangedWithoutExclusions() {
    $this->manager->method('getTaxonomyParents')
      ->willReturn(['' => '- Any -', 5 => 'Five', 6 => 'Six']);

    $this->assertSame([5 => 'Five', 6 => 'Six'], $this->options('audience', NULL, []));
  }

  /**
   * Calls the private createFilterElement() on the form.
   */
  protected function element(array $options): array {
    $container = new ContainerBuilder();
    $container->set('string_translation', $this->getStringTranslationStub());
    \Drupal::setContainer($container);
    $method = new \ReflectionMethod($this->form, 'createFilterElement');
    $method->setAccessible(TRUE);
    return $method->invoke($this->form, 'Category', $options, []);
  }

  /**
   * A filter with no choices shows the message under its label.
   *
   * @covers ::createFilterElement
   */
  public function testEmptyOptionsRenderNoTermsMessage() {
    $element = $this->element([]);
    $this->assertSame('select', $element['#type']);
    $this->assertSame('Category', (string) $element['#title']);
    $this->assertTrue($element['#multiple']);
    $this->assertSame([], $element['#options']);
    $this->assertTrue($element['#disabled']);
    $this->assertTrue($element['#chosen']);
    $this->assertSame('No options available.', (string) $element['#attributes']['data-placeholder']);
    $this->assertSame('No options available.', (string) $element['#attributes']['title']);
  }

  /**
   * A filter with choices is still the Chosen multi-select.
   *
   * @covers ::createFilterElement
   */
  public function testNonEmptyOptionsStillRenderSelect() {
    $element = $this->element([5 => 'Five']);
    $this->assertSame('select', $element['#type']);
    $this->assertTrue($element['#multiple']);
    $this->assertTrue($element['#chosen']);
  }

}
