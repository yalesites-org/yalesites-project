<?php

declare(strict_types=1);

namespace Drupal\Tests\ys_portkey\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\ys_portkey\Form\PortkeyConfigForm;

/**
 * Tests the Portkey config form structure.
 *
 * @coversDefaultClass \Drupal\ys_portkey\Form\PortkeyConfigForm
 * @group ys_portkey
 */
class PortkeyConfigFormTest extends UnitTestCase {

  /**
   * The form under test.
   *
   * @var \Drupal\ys_portkey\Form\PortkeyConfigForm
   */
  protected PortkeyConfigForm $form;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $reflection = new \ReflectionClass(PortkeyConfigForm::class);
    $this->form = $reflection->newInstanceWithoutConstructor();
  }

  /**
   * @covers ::getFormId
   */
  public function testGetFormId(): void {
    $this->assertEquals('ys_portkey_settings', $this->form->getFormId());
  }

  /**
   * @covers ::getEditableConfigNames
   */
  public function testGetEditableConfigNames(): void {
    $method = new \ReflectionMethod($this->form, 'getEditableConfigNames');
    $result = $method->invoke($this->form);
    $this->assertEquals(['ys_portkey.settings'], $result);
  }

}
