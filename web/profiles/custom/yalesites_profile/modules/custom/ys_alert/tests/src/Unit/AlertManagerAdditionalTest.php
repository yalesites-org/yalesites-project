<?php

namespace Drupal\Tests\ys_alert\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\ys_alert\AlertManager;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Additional unit tests for AlertManager methods not covered elsewhere.
 *
 * @coversDefaultClass \Drupal\ys_alert\AlertManager
 *
 * @group yalesites
 * @group ys_alert
 */
class AlertManagerAdditionalTest extends UnitTestCase {

  /**
   * Mock data for ys_alert.settings config.
   */
  const CONFIG = [
    'ys_alert.settings' => [
      'alert_types' => [
        [
          'id' => 'announcement',
          'label' => 'Announcement',
          'description' => 'This is an announcement.',
        ],
        [
          'id' => 'marketing',
          'label' => 'Marketing',
          'description' => 'This is a marketing alert.',
        ],
      ],
      'alert' => [
        'id' => 1660263375,
        'headline' => 'Optional banner for displaying an announcement',
        'message' => 'Additional text goes here',
        'status' => 1,
        'type' => 'announcement',
        'link_title' => 'Yale',
        'link_url' => 'https://www.yale.edu',
      ],
    ],
  ];

  /**
   * Alert manager service.
   *
   * @var \Drupal\ys_alert\AlertManager
   */
  protected $alertManager;

  /**
   * {@inheritdoc}
   */
  public function setUp(): void {
    // Use the config factory stub to mock alert settings config data.
    $configFactory = $this->getConfigFactoryStub(self::CONFIG);
    $container = new ContainerBuilder();
    $container->set('config.factory', $configFactory);
    \Drupal::setContainer($container);
    $this->alertManager = new AlertManager($configFactory);
  }

  /**
   * @covers ::getTypeLabel
   */
  public function testGetTypeLabel() {
    // Returns the label for a given alert type id.
    $this->assertEquals('Marketing', $this->alertManager->getTypeLabel('marketing'));
    // Returns an empty string if the given id is not found.
    $this->assertEquals('', $this->alertManager->getTypeLabel('random'));
  }

}
