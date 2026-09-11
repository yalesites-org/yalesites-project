<?php

namespace Drupal\Tests\ys_localist\Unit\Plugin\migrate\process;

use Drupal\Tests\UnitTestCase;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\Row;
use Drupal\ys_localist\LocalistManager;
use Drupal\ys_localist\Plugin\migrate\process\GetLocalistImageDesc;
use GuzzleHttp\Client;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Unit tests for the GetLocalistImageDesc migrate process plugin.
 *
 * The Localist endpoint is never hit for real here: the Guzzle client is
 * mocked and returns canned JSON response bodies.
 *
 * @coversDefaultClass \Drupal\ys_localist\Plugin\migrate\process\GetLocalistImageDesc
 *
 * @group yalesites
 * @group ys_localist
 */
class GetLocalistImageDescTest extends UnitTestCase {

  /**
   * The mocked Localist manager.
   *
   * @var \Drupal\ys_localist\LocalistManager|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $localistManager;

  /**
   * The mocked Guzzle client.
   *
   * @var \GuzzleHttp\Client|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $httpClient;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->localistManager = $this->createMock(LocalistManager::class);
    $this->localistManager->method('getEndpointUrls')
      ->with('photos')
      ->willReturn(['https://events.yale.edu/api/2/photos']);

    $this->httpClient = $this->createMock(Client::class);
  }

  /**
   * Builds the plugin under test.
   */
  protected function createPlugin(): GetLocalistImageDesc {
    return new GetLocalistImageDesc([], 'get_localist_image_desc', [], $this->localistManager, $this->httpClient);
  }

  /**
   * Builds a mocked Guzzle response with the given JSON body.
   */
  protected function jsonResponse(string $json): ResponseInterface {
    $stream = $this->createMock(StreamInterface::class);
    $stream->method('getContents')->willReturn($json);

    $response = $this->createMock(ResponseInterface::class);
    $response->method('getBody')->willReturn($stream);

    return $response;
  }

  /**
   * @covers ::transform
   */
  public function testTransformReturnsPhotoCaption() {
    $this->httpClient->method('get')
      ->with('https://events.yale.edu/api/2/photos/456')
      ->willReturn($this->jsonResponse('{"photo": {"caption": "A scenic view of campus"}}'));

    $plugin = $this->createPlugin();
    $result = $plugin->transform(
      456,
      $this->createMock(MigrateExecutableInterface::class),
      $this->createMock(Row::class),
      'field_image_desc'
    );

    $this->assertSame('A scenic view of campus', $result);
  }

  /**
   * @covers ::transform
   */
  public function testTransformReturnsNullWhenCaptionMissing() {
    $this->httpClient->method('get')
      ->willReturn($this->jsonResponse('{"photo": {}}'));

    $plugin = $this->createPlugin();
    $result = $plugin->transform(
      789,
      $this->createMock(MigrateExecutableInterface::class),
      $this->createMock(Row::class),
      'field_image_desc'
    );

    $this->assertNull($result);
  }

  /**
   * @covers ::create
   */
  public function testCreateReturnsGetLocalistImageDescInstance() {
    $services = [
      'ys_localist.manager' => $this->localistManager,
      'http_client' => $this->httpClient,
    ];
    $container = $this->createMock(ContainerInterface::class);
    $container->method('get')->willReturnCallback(fn (string $id) => $services[$id]);

    $plugin = GetLocalistImageDesc::create($container, [], 'get_localist_image_desc', []);
    $this->assertInstanceOf(GetLocalistImageDesc::class, $plugin);
  }

}
