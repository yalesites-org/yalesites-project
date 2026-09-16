<?php

namespace Drupal\Tests\ys_integrations\Unit;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_integrations\Attribute\Integration;

/**
 * Tests the Integration plugin discovery attribute.
 *
 * The attribute is plain PHP: its constructor stores the plugin id, label and
 * description, and the inherited get() builds the definition array that plugin
 * discovery reads.
 *
 * @coversDefaultClass \Drupal\ys_integrations\Attribute\Integration
 *
 * @group ys_integrations
 * @group yalesites
 */
class IntegrationAttributeTest extends UnitTestCase {

  /**
   * The attribute stores the id, label and description it is constructed with.
   *
   * @covers ::__construct
   */
  public function testStoresConstructorArguments(): void {
    $label = new TranslatableMarkup('Test Label');
    $description = new TranslatableMarkup('Test description.');

    $attribute = new Integration('ys_integration_test', $label, $description);

    $this->assertSame('ys_integration_test', $attribute->id);
    $this->assertSame('ys_integration_test', $attribute->getId());
    $this->assertSame($label, $attribute->label);
    $this->assertSame($description, $attribute->description);
  }

  /**
   * The label and description are optional and default to NULL.
   *
   * @covers ::__construct
   */
  public function testLabelAndDescriptionDefaultToNull(): void {
    $attribute = new Integration('ys_integration_test');

    $this->assertNull($attribute->label);
    $this->assertNull($attribute->description);
  }

  /**
   * The get() method returns the definition array discovery stores.
   *
   * @covers ::get
   */
  public function testGetReturnsDefinitionArray(): void {
    $attribute = new Integration(
      'ys_integration_test',
      new TranslatableMarkup('Test Label'),
      new TranslatableMarkup('Test description.'),
    );
    $attribute->setClass('Drupal\ys_integrations_test\Plugin\ys_integrations\TestIntegrationPlugin');
    $attribute->setProvider('ys_integrations_test');

    $definition = $attribute->get();

    $this->assertIsArray($definition);
    $this->assertSame('ys_integration_test', $definition['id']);
    $this->assertSame('Drupal\ys_integrations_test\Plugin\ys_integrations\TestIntegrationPlugin', $definition['class']);
    $this->assertSame('ys_integrations_test', $definition['provider']);
    $this->assertInstanceOf(TranslatableMarkup::class, $definition['label']);
    $this->assertSame('Test Label', $definition['label']->getUntranslatedString());
    $this->assertInstanceOf(TranslatableMarkup::class, $definition['description']);
    $this->assertSame('Test description.', $definition['description']->getUntranslatedString());
  }

}
