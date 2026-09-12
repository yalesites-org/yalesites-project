<?php

namespace Drupal\Tests\ys_layouts\Unit;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormState;
use Drupal\Tests\UnitTestCase;
use Drupal\Tests\ys_core\Traits\LayoutBuilderEntityContextTestTrait;
use Drupal\node\NodeInterface;
use Drupal\ys_layouts\Plugin\Block\ProfileMetaBlock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Tests the profile meta block.
 *
 * @coversDefaultClass \Drupal\ys_layouts\Plugin\Block\ProfileMetaBlock
 *
 * @group yalesites
 * @group ys_layouts
 */
class ProfileMetaBlockTest extends UnitTestCase {

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
   * @var \Drupal\ys_layouts\Plugin\Block\ProfileMetaBlock
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

    $this->block = new ProfileMetaBlock(
      [],
      'profile_meta_block',
      $this->profileMetaBlockDefinition(),
      $this->requestStack,
      $this->entityTypeManager
    );
  }

  /**
   * The plugin definition the block under test is constructed with.
   *
   * @return array
   *   The plugin definition.
   */
  protected function profileMetaBlockDefinition(): array {
    return [
      'provider' => 'ys_layouts',
      'admin_label' => 'Profile Meta Block',
      'context_definitions' => $this->layoutBuilderEntityContextDefinitions(ProfileMetaBlock::class),
    ];
  }

  /**
   * Builds a mock field item list whose getValue() yields a single value.
   *
   * @param mixed $value
   *   The value keyed under 'value' for the first field item.
   * @param string $key
   *   The key the value is stored under.
   *
   * @return \Drupal\Core\Field\FieldItemListInterface|\PHPUnit\Framework\MockObject\MockObject
   *   The mock field.
   */
  protected function createFieldWithValue($value, string $key = 'value'): FieldItemListInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('getValue')->willReturn([[$key => $value]]);
    return $field;
  }

  /**
   * A profile node's fields populate the render array.
   *
   * @covers ::build
   */
  public function testBuildReadsProfileFields(): void {
    $node = $this->createMock(NodeInterface::class);
    $node->method('bundle')->willReturn('profile');
    $node->method('getTitle')->willReturn('Dr. Jane Smith');
    $node->method('get')->willReturnMap([
      ['field_position', $this->createFieldWithValue('Professor')],
      ['field_subtitle', $this->createFieldWithValue('Department Chair')],
      ['field_department', $this->createFieldWithValue('Computer Science')],
      ['field_pronouns', $this->createFieldWithValue('she/her')],
      ['field_media', $this->createFieldWithValue(7, 'target_id')],
    ]);

    $request = new Request();
    $request->attributes->set('node', $node);
    $this->requestStack->method('getCurrentRequest')->willReturn($request);

    $build = $this->block->build();

    $this->assertSame('ys_profile_meta_block', $build['#theme']);
    $this->assertSame('Dr. Jane Smith', $build['#profile_meta__heading']);
    $this->assertSame('Professor', $build['#profile_meta__title_line']);
    $this->assertSame('Department Chair', $build['#profile_meta__subtitle_line']);
    $this->assertSame('Computer Science', $build['#profile_meta__department']);
    $this->assertSame('she/her', $build['#profile_meta__pronouns']);
    $this->assertSame(7, $build['#media_id']);
    $this->assertSame('portrait', $build['#profile_meta__image_orientation']);
  }

  /**
   * A non-profile node leaves all fields NULL rather than raising an error.
   *
   * @covers ::build
   */
  public function testBuildWithNonProfileBundleLeavesFieldsNull(): void {
    $node = $this->createMock(NodeInterface::class);
    $node->method('bundle')->willReturn('page');

    $request = new Request();
    $request->attributes->set('node', $node);
    $this->requestStack->method('getCurrentRequest')->willReturn($request);

    $build = $this->block->build();

    $this->assertNull($build['#profile_meta__heading']);
    $this->assertNull($build['#media_id']);
  }

  /**
   * With no node attribute, the Layout Builder ajax path is used to load one.
   *
   * @covers ::build
   */
  public function testBuildFallsBackToLoadingNodeFromAjaxPath(): void {
    $node = $this->createMock(NodeInterface::class);
    $node->method('bundle')->willReturn('profile');
    $node->method('getTitle')->willReturn('Dr. Jane Smith');
    $node->method('get')->willReturn($this->createFieldWithValue('Professor'));

    $path = '/admin/config/content/layout_builder/update/overrides/node.42.default.en/0/content';
    $request = Request::create($path);
    $this->requestStack->method('getCurrentRequest')->willReturn($request);

    $nodeStorage = $this->createMock(EntityStorageInterface::class);
    $nodeStorage->method('load')->with('42')->willReturn($node);
    $this->entityTypeManager->method('getStorage')->with('node')->willReturn($nodeStorage);

    $build = $this->block->build();

    $this->assertSame('Dr. Jane Smith', $build['#profile_meta__heading']);
  }

  /**
   * The image configuration selects carry their configured default values.
   *
   * @covers ::blockForm
   */
  public function testBlockFormUsesConfiguredImageSettings(): void {
    $block = new ProfileMetaBlock(
      [
        'image_orientation' => 'landscape',
        'image_style' => 'outdent',
        'image_alignment' => 'right',
      ],
      'profile_meta_block',
      $this->profileMetaBlockDefinition(),
      $this->requestStack,
      $this->entityTypeManager
    );
    $block->setStringTranslation($this->getStringTranslationStub());

    $form = $block->blockForm([], new FormState());

    $this->assertSame('landscape', $form['image_orientation']['#default_value']);
    $this->assertSame('outdent', $form['image_style']['#default_value']);
    $this->assertSame('right', $form['image_alignment']['#default_value']);
  }

  /**
   * Submitting the form stores the selected image configuration.
   *
   * @covers ::blockSubmit
   */
  public function testBlockSubmitStoresImageSettings(): void {
    $form_state = new FormState();
    $form_state->setValue('image_orientation', 'landscape');
    $form_state->setValue('image_style', 'outdent');
    $form_state->setValue('image_alignment', 'right');

    $this->block->blockSubmit([], $form_state);

    $configuration = $this->block->getConfiguration();
    $this->assertSame('landscape', $configuration['image_orientation']);
    $this->assertSame('outdent', $configuration['image_style']);
    $this->assertSame('right', $configuration['image_alignment']);
  }

  /**
   * Builds a profile node mock whose fields resolve.
   *
   * @param string $title
   *   The node title.
   *
   * @return \Drupal\node\NodeInterface|\PHPUnit\Framework\MockObject\MockObject
   *   The node mock.
   */
  protected function mockProfileNode(string $title) {
    $node = $this->createMock(NodeInterface::class);
    $node->method('bundle')->willReturn('profile');
    $node->method('getTitle')->willReturn($title);
    $node->method('get')->willReturn($this->createFieldWithValue('Professor'));

    return $node;
  }

  /**
   * The entity being rendered beats a request that names no node at all.
   *
   * This is the reported bug: /node/add/profile (every new profile's first
   * save) and a bulk operation from /admin/content carry no node on the
   * request, so the block rendered an EMPTY <h1> -- a WCAG 2.1 AA problem as
   * well as a blank heading in what Beacon indexes.
   *
   * There is no route object at all when Search API indexes from cron or from
   * drush, and the block used to gate every field read -- including the title
   * -- on one, so the heading was blank on the path that does most of the
   * indexing.
   *
   * @covers ::build
   */
  public function testBuildUsesRenderedEntityWhenRequestHasNoNode(): void {
    $request = Request::create('/node/add/profile');
    $this->requestStack->method('getCurrentRequest')->willReturn($request);
    $this->entityTypeManager->expects($this->never())->method('getStorage');

    $this->setRenderedEntity($this->block, $this->mockProfileNode('Dr. Jane Smith'));

    $build = $this->block->build();

    $this->assertSame('ys_profile_meta_block', $build['#theme']);
    $this->assertSame('Dr. Jane Smith', $build['#profile_meta__heading']);
  }

  /**
   * The entity being rendered beats a DIFFERENT node named by the request.
   *
   * @covers ::build
   */
  public function testBuildPrefersRenderedEntityOverRequestNode(): void {
    $request = new Request();
    $request->attributes->set('node', $this->mockProfileNode('Someone Else'));
    $this->requestStack->method('getCurrentRequest')->willReturn($request);

    $this->setRenderedEntity($this->block, $this->mockProfileNode('Dr. Jane Smith'));

    $this->assertSame('Dr. Jane Smith', $this->block->build()['#profile_meta__heading']);
  }

  /**
   * A context holding something other than a node falls through to the request.
   *
   * Layout Builder hands over whatever entity the display belongs to, so the
   * context is not guaranteed to hold a node -- the defaults layout screen
   * passes a generated sample entity of the display's own type.
   *
   * @covers ::build
   *
   * @dataProvider providerNonNodeContextValues
   */
  public function testBuildIgnoresNonNodeEntityContext($value): void {
    $request = new Request();
    $request->attributes->set('node', $this->mockProfileNode('Someone Else'));
    $this->requestStack->method('getCurrentRequest')->willReturn($request);

    $this->setRenderedEntity($this->block, $value);

    $this->assertSame('Someone Else', $this->block->build()['#profile_meta__heading']);
  }

  /**
   * The ANNOTATION declares the slot Layout Builder actually publishes.
   */
  public function testAnnotationDeclaresTheLayoutBuilderEntitySlot(): void {
    $this->assertDeclaresLayoutBuilderEntitySlot(ProfileMetaBlock::class);
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
   * An unsaved sample entity does not stand in for real content.
   *
   * Layout Builder's Defaults layout screen offers a generated sample entity
   * that core never saves (LayoutBuilderSampleEntityGenerator::get() calls
   * createWithSampleValues() and stashes it in a tempstore). Before this block
   * declared a context it left every field NULL there.
   *
   * @covers ::build
   */
  public function testBuildIgnoresUnsavedSampleEntity(): void {
    $this->requestStack->method('getCurrentRequest')->willReturn(new Request());

    $sample = $this->mockProfileNode('Sample Profile');
    $sample->method('isNew')->willReturn(TRUE);
    $this->setRenderedEntity($this->block, $sample);

    $this->assertNull($this->block->build()['#profile_meta__heading']);
  }

}
