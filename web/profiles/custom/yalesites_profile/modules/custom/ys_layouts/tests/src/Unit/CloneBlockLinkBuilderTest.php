<?php

namespace Drupal\Tests\ys_layouts\Unit;

use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Tests\UnitTestCase;
use Drupal\layout_builder\Plugin\Block\InlineBlock;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\layout_builder\SectionStorageInterface;
use Drupal\ys_layouts\CloneBlockLinkBuilder;

/**
 * Tests the "Clone" contextual link group attached to inline blocks.
 *
 * The clone route requires a CSRF token, and the contextual module caches each
 * menu's HTML, token included, in sessionStorage under the contextual id. The
 * id therefore has to change whenever a cached menu would carry a token the
 * server no longer accepts: once when the token was introduced, and again
 * whenever the session (and so its CSRF seed) changes.
 *
 * @group yalesites
 * @group ys_layouts
 *
 * @coversDefaultClass \Drupal\ys_layouts\CloneBlockLinkBuilder
 */
class CloneBlockLinkBuilderTest extends UnitTestCase {

  /**
   * The token the mocked CSRF generator hands out for the current session.
   *
   * @var string
   */
  protected string $sessionToken = 'token-for-session-one';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $csrf_token = $this->createMock(CsrfTokenGenerator::class);
    $csrf_token->method('get')->willReturnCallback(fn () => $this->sessionToken);

    $container = new ContainerBuilder();
    $container->set('csrf_token', $csrf_token);
    \Drupal::setContainer($container);
  }

  /**
   * Builds a layout_builder element holding one inline block.
   *
   * @return array
   *   A render array shaped like the built layout_builder element.
   */
  protected function element(): array {
    $component = $this->createMock(SectionComponent::class);
    $component->method('getPlugin')->willReturn($this->createMock(InlineBlock::class));

    $section = $this->createMock(Section::class);
    $section->method('getComponent')->with('uuid-1')->willReturn($component);

    $section_storage = $this->createMock(SectionStorageInterface::class);
    $section_storage->method('getSection')->with(0)->willReturn($section);

    return [
      '#section_storage' => $section_storage,
      'layout_builder' => [
        0 => [
          'content' => [
            'uuid-1' => [
              '#contextual_links' => [
                'layout_builder_block' => [
                  'route_parameters' => [
                    'section_storage_type' => 'overrides',
                    'section_storage' => 'node.1',
                    'delta' => 0,
                    'region' => 'content',
                    'uuid' => 'uuid-1',
                  ],
                  'metadata' => ['operations' => 'move:update:remove'],
                ],
              ],
            ],
          ],
        ],
      ],
    ];
  }

  /**
   * Returns the clone group the pre-render attached to the inline block.
   *
   * @return array
   *   The layout_builder_block_clone contextual link group.
   */
  protected function cloneGroup(): array {
    $element = CloneBlockLinkBuilder::preRender($this->element());
    return $element['layout_builder'][0]['content']['uuid-1']['#contextual_links']['layout_builder_block_clone'];
  }

  /**
   * The clone group carries session metadata so its contextual id changes.
   *
   * @covers ::preRender
   */
  public function testCloneGroupCarriesSessionMetadata(): void {
    $group = $this->cloneGroup();

    $this->assertSame('uuid-1', $group['route_parameters']['uuid']);
    $this->assertNotEmpty($group['metadata']['csrf_session'] ?? NULL);
  }

  /**
   * A new session gives a new id, so a menu cached by the old one is not used.
   *
   * @covers ::preRender
   */
  public function testSessionMetadataChangesWithTheSession(): void {
    $first = $this->cloneGroup()['metadata']['csrf_session'];
    $this->assertSame($first, $this->cloneGroup()['metadata']['csrf_session'], 'Stable within one session.');

    $this->sessionToken = 'token-for-session-two';
    $this->assertNotSame($first, $this->cloneGroup()['metadata']['csrf_session']);
  }

  /**
   * The metadata is a short one-way digest, never the token itself.
   *
   * It ends up in the page markup as part of the contextual id, so it must not
   * hand anyone a usable token.
   *
   * @covers ::preRender
   */
  public function testSessionMetadataDoesNotExposeTheToken(): void {
    $value = $this->cloneGroup()['metadata']['csrf_session'];

    $this->assertStringNotContainsString($this->sessionToken, $value);
    $this->assertLessThanOrEqual(12, strlen($value));
  }

  /**
   * The element varies by session, so no cache serves one id to another user.
   *
   * @covers ::preRender
   */
  public function testElementVariesBySession(): void {
    $element = CloneBlockLinkBuilder::preRender($this->element());

    $this->assertContains('session', $element['#cache']['contexts']);
  }

}
