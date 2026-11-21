<?php

namespace Drupal\Tests\ys_layouts\Unit;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormState;
use Drupal\Tests\UnitTestCase;
use Drupal\Tests\ys_core\Traits\LayoutBuilderEntityContextTestTrait;
use Drupal\node\NodeInterface;
use Drupal\ys_layouts\Plugin\Block\ProfileContactBlock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Tests the profile contact block.
 *
 * @coversDefaultClass \Drupal\ys_layouts\Plugin\Block\ProfileContactBlock
 *
 * @group yalesites
 * @group ys_layouts
 */
class ProfileContactBlockTest extends UnitTestCase {

  use LayoutBuilderEntityContextTestTrait;

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

    $this->requestStack = $this->createMock(RequestStack::class);
    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);

    $this->setUpLayoutBuilderEntityContextContainer();

    $this->block = new ProfileContactBlock(
      [],
      'profile_contact_block',
      $this->blockDefinition(),
      $this->requestStack,
      $this->entityTypeManager
    );
  }

  /**
   * The plugin definition, matching the keys the real annotation supplies.
   *
   * @return array
   *   The plugin definition.
   */
  protected function blockDefinition(): array {
    return [
      'provider' => 'ys_layouts',
      'admin_label' => 'Profile Contact Block',
      'context_definitions' => $this->layoutBuilderEntityContextDefinitions(ProfileContactBlock::class),
    ];
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
   * Contact fields are read from the node the request names.
   *
   * No route object is set up here on purpose. The block used to gate every
   * field read on routeMatch->getRouteObject() being non-NULL, and never read
   * the route inside that gate; there is no route object when Search API
   * indexes from cron or drush -- the path that does most of the indexing --
   * so the details stayed blank there.
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
   * The entity being rendered is used when the request names no node.
   *
   * A node is rendered on routes other than its canonical one, and Search API
   * renders it in the request that saves it. On /node/add/profile (every new
   * profile's first save) and on a bulk operation from /admin/content the
   * request named no node, so the contact details were missing from what
   * Beacon indexes.
   *
   * @covers ::getCurrentNode
   */
  public function testBuildUsesRenderedEntityWhenRequestHasNoNode(): void {
    $node = $this->createMock(NodeInterface::class);
    $node->method('get')->willReturn($this->createFieldWithValue('jane@yale.edu'));
    $this->requestStack->method('getCurrentRequest')->willReturn(Request::create('/node/add/profile'));
    $this->entityTypeManager->expects($this->never())->method('getStorage');

    $this->setRenderedEntity($this->block, $node);

    $this->assertSame('jane@yale.edu', $this->block->build()['#email']);
  }

  /**
   * The entity being rendered beats a DIFFERENT node named by the request.
   *
   * @covers ::getCurrentNode
   */
  public function testBuildPrefersRenderedEntityOverRequestNode(): void {
    $rendered = $this->createMock(NodeInterface::class);
    $rendered->method('get')->willReturn($this->createFieldWithValue('jane@yale.edu'));
    $other = $this->createMock(NodeInterface::class);
    $other->method('get')->willReturn($this->createFieldWithValue('other@yale.edu'));

    $request = new Request();
    $request->attributes->set('node', $other);
    $this->requestStack->method('getCurrentRequest')->willReturn($request);

    $this->setRenderedEntity($this->block, $rendered);

    $this->assertSame('jane@yale.edu', $this->block->build()['#email']);
  }

  /**
   * A context holding something other than a node falls through to the request.
   *
   * @covers ::getCurrentNode
   *
   * @dataProvider providerNonNodeContextValues
   */
  public function testBuildIgnoresNonNodeEntityContext($value): void {
    $other = $this->createMock(NodeInterface::class);
    $other->method('get')->willReturn($this->createFieldWithValue('other@yale.edu'));
    $request = new Request();
    $request->attributes->set('node', $other);
    $this->requestStack->method('getCurrentRequest')->willReturn($request);

    $this->setRenderedEntity($this->block, $value);

    $this->assertSame('other@yale.edu', $this->block->build()['#email']);
  }

  /**
   * An unsaved sample entity does not stand in for real content.
   *
   * Layout Builder's Defaults layout screen offers a generated sample entity
   * that core never saves. Before this block declared a context it rendered
   * nothing there, and it deliberately still does.
   *
   * @covers ::getCurrentNode
   */
  public function testBuildIgnoresUnsavedSampleEntity(): void {
    $sample = $this->createMock(NodeInterface::class);
    $sample->method('isNew')->willReturn(TRUE);
    $sample->method('get')->willReturn($this->createFieldWithValue('jane@yale.edu'));
    $this->requestStack->method('getCurrentRequest')->willReturn(new Request());

    $this->setRenderedEntity($this->block, $sample);

    $this->assertNull($this->block->build()['#email']);
  }

  /**
   * With no current request at all, the block resolves no node.
   *
   * There is no HTTP request in a drush or cron process, so the request tier
   * has nothing to read. The guard is what keeps that path from calling
   * attributes->get() on NULL.
   *
   * @covers ::getCurrentNode
   */
  public function testBuildWithNoCurrentRequestResolvesNoNode(): void {
    $this->requestStack->method('getCurrentRequest')->willReturn(NULL);

    $this->assertNull($this->block->build()['#email']);
  }

  /**
   * The ANNOTATION declares the slot Layout Builder actually publishes.
   */
  public function testAnnotationDeclaresTheLayoutBuilderEntitySlot(): void {
    $this->assertDeclaresLayoutBuilderEntitySlot(ProfileContactBlock::class);
  }

  /**
   * The context-assignment select is kept off the editor's block form.
   *
   * @covers ::buildConfigurationForm
   */
  public function testConfigurationFormHasNoContextAssignmentSelect(): void {
    $this->assertNoContextAssignmentSelect($this->block);
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
      $this->blockDefinition(),
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
