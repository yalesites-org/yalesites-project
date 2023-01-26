<?php

namespace Drupal\Tests\ys_alert\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\ys_alert\AlertManager;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * @coversDefaultClass \Drupal\ys_alert\AlertManager
 *
 * @group yalesites
 */
class AlertManagerTest extends UnitTestCase {

  /**
   * Mock data for ys_alert.settings.yml config.
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
        [
          'id' => 'emergency',
          'label' => 'Emergency',
          'description' => 'This is an emergency alert.',
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
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The test container.
   *
   * @var \Drupal\Core\DependencyInjection\ContainerBuilder
   */
  protected $container;

  /**
   * {@inheritdoc}
   */
  public function setUp() : void {
    // Use the config factory stub to mock alert settings config data.
    $this->configFactory = $this->getConfigFactoryStub(self::CONFIG);
    $this->container = new ContainerBuilder();
    $this->container->set('config.factory', $this->configFactory);
    \Drupal::setContainer($this->container);
    // Instantiate an AlertManager for testing.
    $this->alertManager = new AlertManager($this->configFactory);
  }

  /**
   * @covers ::getAlertTypes
   */
  public function testGetAlertTypes() {
    $options = [
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
      [
        'id' => 'emergency',
        'label' => 'Emergency',
        'description' => 'This is an emergency alert.',
      ],
    ];
    // Returns an array of alert types to display as form options.
    $this->assertEquals($options, $this->alertManager->getAlertTypes());
  }

  /**
   * @covers ::getTypeOptions
   */
  public function testGetTypeOptions() {
    $options = [
      'announcement' => 'Announcement',
      'marketing' => 'Marketing',
      'emergency' => 'Emergency',
    ];
    // Returns an array of alert types to display as form options.
    $this->assertEquals($options, $this->alertManager->getTypeOptions());
  }

  /**
   * @covers ::getTypeById
   */
  public function testGetTypeById() {
    $type = [
      'id' => 'marketing',
      'label' => 'Marketing',
      'description' => 'This is a marketing alert.',
    ];
    // Returns an array of type data matching the given id.
    $this->assertEquals($type, $this->alertManager->getTypeById('marketing'));
    // Returns an empty array if the given id is not found.
    $this->assertEquals([], $this->alertManager->getTypeById('random'));
  }

  /**
   * @covers ::getTypeDescription
   */
  public function testGetTypeDescription() {
    // Returns an alert description matching the given id.
    $this->assertEquals(
      'This is a marketing alert.',
      $this->alertManager->getTypeDescription('marketing')
    );
    // Returns an empty string if the given id is not found.
    $this->assertEquals('', $this->alertManager->getTypeDescription('random'));
  }

  /**
   * @covers ::getAlert
   */
  public function testGetAlert() {
    $alert = [
      'id' => 1660263375,
      'headline' => 'Optional banner for displaying an announcement',
      'message' => 'Additional text goes here',
      'status' => 1,
      'type' => 'announcement',
      'link_title' => 'Yale',
      'link_url' => 'https://www.yale.edu',
    ];
    // Returns the data for the current alert.
    $this->assertEquals($alert, $this->alertManager->getAlert());
  }

  /**
   * @covers ::showAlert
   */
  public function testShowAlert() {
    // Returns true since the test data has an enabled alert.
    $this->assertEquals(TRUE, $this->alertManager->showAlert());
  }

}
