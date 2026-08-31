<?php

namespace Drupal\Tests\ys_layouts\Unit;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\node\NodeInterface;
use Drupal\ys_layouts\Plugin\Block\ProfileContactBlock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Route;

/**
 * Tests the profile contact block.
 *
 * @coversDefaultClass \Drupal\ys_layouts\Plugin\Block\ProfileContactBlock
 *
 * @group yalesites
 * @group ys_layouts
 */
class ProfileContactBlockTest extends UnitTestCase {

  /**
   * The route match mock.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $routeMatch;

  /**
   * The request stack mock.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $requestStack;

  /**
   * The entity type manager mock.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $entityTypeManager;

  /**
   * The block plugin under test.
   *
   * @var \Drupal\ys_layouts\Plugin\Block\ProfileContactBlock
   */
  protected $block;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->routeMatch = $this->createMock(RouteMatchInterface::class);
    $this->requestStack = $this->createMock(RequestStack::class);
    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);

    $this->block = new ProfileContactBlock(
      [],
      'profile_contact_block',
      ['provider' => 'ys_layouts'],
      $this->routeMatch,
      $this->requestStack,
      $this->entityTypeManager
    );
  }

  /**
   * Builds a mock field item list whose getValue() yields a single value.
   *
   * @param string $value
   *   The field value.
   *
   * @return \Drupal\Core\Field\FieldItemListInterface|\PHPUnit\Framework\MockObject\MockObject
   *   The mock field.
   */
  protected function createFieldWithValue(string $value): FieldItemListInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('getValue')->willReturn([['value' => $value]]);
    return $field;
  }

  /**
   * Contact fields are read from the route's current node.
   *
   * @covers ::build
   */
  public function testBuildReadsContactFieldsFromNode(): void {
    $node = $this->createMock(NodeInterface::class);
    $node->method('get')->willReturnMap([
      ['field_email', $this->createFieldWithValue('jane@yale.edu')],
      ['field_telephone', $this->createFieldWithValue('203-555-0100')],
      ['field_address', $this->createFieldWithValue('New Haven, CT')],
    ]);

    $request = new Request();
    $request->attributes->set('node', $node);
    $this->requestStack->method('getCurrentRequest')->willReturn($request);
    $this->routeMatch->method('getRouteObject')->willReturn(new Route('/profiles/jane'));

    $build = $this->block->build();

    $this->assertSame('ys_profile_contact_block', $build['#theme']);
    $this->assertSame('jane@yale.edu', $build['#email']);
    $this->assertSame('203-555-0100', $build['#phone']);
    $this->assertSame('New Haven, CT', $build['#address']);
    $this->assertSame('default', $build['#padding_options']);
  }

  /**
   * With no node on the request, all contact fields stay NULL.
   *
   * @covers ::build
   */
  public function testBuildWithNoNodeLeavesFieldsNull(): void {
    $request = new Request();
    $this->requestStack->method('getCurrentRequest')->willReturn($request);
    $this->routeMatch->method('getRouteObject')->willReturn(new Route('/profiles/jane'));

    $build = $this->block->build();

    $this->assertNull($build['#email']);
    $this->assertNull($build['#phone']);
    $this->assertNull($build['#address']);
  }

  /**
   * With no node attribute, the Layout Builder ajax path is used to load one.
   *
   * @covers ::build
   */
  public function testBuildFallsBackToLoadingNodeFromAjaxPath(): void {
    $node = $this->createMock(NodeInterface::class);
    $node->method('get')->willReturn($this->createFieldWithValue('jane@yale.edu'));

    $path = '/admin/config/content/layout_builder/update/overrides/node.42.default.en/0/content';
    $request = Request::create($path);
    $this->requestStack->method('getCurrentRequest')->willReturn($request);
    $this->routeMatch->method('getRouteObject')->willReturn(new Route($path));

    $nodeStorage = $this->createMock(EntityStorageInterface::class);
    $nodeStorage->method('load')->with('42')->willReturn($node);
    $this->entityTypeManager->method('getStorage')->with('node')->willReturn($nodeStorage);

    $build = $this->block->build();

    $this->assertSame('jane@yale.edu', $build['#email']);
  }

  /**
   * An exception while reading a field is treated as an empty value.
   *
   * @covers ::getValueFor
   */
  public function testGetValueForReturnsNullWhenGetThrows(): void {
    $node = $this->createMock(NodeInterface::class);
    $node->method('get')->willThrowException(new \Exception('No such field'));

    $reflection = new \ReflectionClass($this->block);
    $method = $reflection->getMethod('getValueFor');
    $method->setAccessible(TRUE);

    $result = $method->invoke($this->block, $node, 'field_missing');

    $this->assertNull($result);
  }

  /**
   * The padding options select carries the configured default value.
   *
   * @covers ::blockForm
   */
  public function testBlockFormUsesConfiguredPadding(): void {
    $block = new ProfileContactBlock(
      ['padding_options' => 'no_top'],
      'profile_contact_block',
      ['provider' => 'ys_layouts'],
      $this->routeMatch,
      $this->requestStack,
      $this->entityTypeManager
    );
    $block->setStringTranslation($this->getStringTranslationStub());

    $form = $block->blockForm([], new FormState());

    $this->assertSame('no_top', $form['padding_options']['#default_value']);
  }

  /**
   * Submitting the form stores the selected padding option.
   *
   * @covers ::blockSubmit
   */
  public function testBlockSubmitStoresPaddingOption(): void {
    $form_state = new FormState();
    $form_state->setValue('padding_options', 'no_bottom');

    $this->block->blockSubmit([], $form_state);

    $this->assertSame('no_bottom', $this->block->getConfiguration()['padding_options']);
  }

}
