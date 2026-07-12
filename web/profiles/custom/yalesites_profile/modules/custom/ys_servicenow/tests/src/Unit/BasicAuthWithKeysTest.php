<?php

namespace Drupal\Tests\ys_servicenow\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\key\KeyInterface;
use Drupal\key\KeyRepositoryInterface;
use Drupal\ys_servicenow\BasicAuthWithKeys;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Unit tests for BasicAuthWithKeys.
 *
 * @coversDefaultClass \Drupal\ys_servicenow\BasicAuthWithKeys
 *
 * @group yalesites
 * @group ys_servicenow
 */
class BasicAuthWithKeysTest extends UnitTestCase {

  /**
   * The mocked key repository.
   *
   * @var \Drupal\key\KeyRepositoryInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $keyRepository;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->keyRepository = $this->createMock(KeyRepositoryInterface::class);
    $container = new ContainerBuilder();
    $container->set('key.repository', $this->keyRepository);
    \Drupal::setContainer($container);
  }

  /**
   * Builds a mock key entity returning the given raw key value.
   */
  protected function mockKey(?string $key_value): KeyInterface {
    $key = $this->createMock(KeyInterface::class);
    $key->method('getKeyValue')->willReturn($key_value);
    return $key;
  }

  /**
   * @covers ::getAuthenticationOptions
   */
  public function testGetAuthenticationOptionsThrowsWithoutKeyId() {
    $auth = new BasicAuthWithKeys(NULL, '');

    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('Key not set');
    $auth->getAuthenticationOptions();
  }

  /**
   * @covers ::getAuthenticationOptions
   */
  public function testGetAuthenticationOptionsThrowsWhenKeyNotFound() {
    $this->keyRepository->method('getKey')->with('missing_key')->willReturn(NULL);

    $auth = new BasicAuthWithKeys(NULL, 'missing_key');

    $this->expectException(\Exception::class);
    $this->expectExceptionMessage("Key 'missing_key' not found");
    $auth->getAuthenticationOptions();
  }

  /**
   * @covers ::getAuthenticationOptions
   */
  public function testGetAuthenticationOptionsThrowsWhenKeyHasNoValue() {
    $this->keyRepository->method('getKey')->willReturn($this->mockKey(''));

    $auth = new BasicAuthWithKeys(NULL, 'empty_key');

    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('Key has no value');
    $auth->getAuthenticationOptions();
  }

  /**
   * @covers ::getAuthenticationOptions
   */
  public function testGetAuthenticationOptionsThrowsWhenKeyValueIsNotJson() {
    $this->keyRepository->method('getKey')->willReturn($this->mockKey('not valid json'));

    $auth = new BasicAuthWithKeys(NULL, 'bad_key');

    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('Key value is not valid JSON.  Could you have accidentally used single quotes vs double?');
    $auth->getAuthenticationOptions();
  }

  /**
   * @covers ::getAuthenticationOptions
   */
  public function testGetAuthenticationOptionsReturnsCredentialsFromKeyValue() {
    $this->keyRepository->method('getKey')
      ->with('servicenow_key')
      ->willReturn($this->mockKey('{"username":"svcnow","password":"secret"}'));

    $auth = new BasicAuthWithKeys(NULL, 'servicenow_key');

    $this->assertEquals(
      ['auth' => ['svcnow', 'secret']],
      $auth->getAuthenticationOptions()
    );
  }

}
