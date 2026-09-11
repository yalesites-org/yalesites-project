<?php

namespace Drupal\Tests\ys_layouts\Unit;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\node\NodeInterface;
use Drupal\ys_layouts\Plugin\Block\ProfileMetaBlock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Route;

/**
 * Tests the profile meta block.
 *
 * @coversDefaultClass \Drupal\ys_layouts\Plugin\Block\ProfileMetaBlock
 *
 * @group yalesites
 * @group ys_layouts
 */
class ProfileMetaBlockTest extends UnitTestCase {

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
   * @var \Drupal\ys_layouts\Plugin\Block\ProfileMetaBlock
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

    $this->block = new ProfileMetaBlock(
      [],
      'profile_meta_block',
      ['provider' => 'ys_layouts'],
      $this->routeMatch,
      $this->requestStack,
      $this->entityTypeManager
    );
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
    $this->routeMatch->method('getRouteObject')->willReturn(new Route('/profiles/jane'));

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
    $this->routeMatch->method('getRouteObject')->willReturn(new Route('/about'));

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
    $this->routeMatch->method('getRouteObject')->willReturn(new Route($path));

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
      ['provider' => 'ys_layouts'],
      $this->routeMatch,
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

}
