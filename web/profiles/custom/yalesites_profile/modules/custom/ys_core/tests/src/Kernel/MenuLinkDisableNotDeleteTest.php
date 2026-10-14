<?php

namespace Drupal\Tests\ys_core\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\menu_link_content\Entity\MenuLinkContent;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;

/**
 * Tests that unchecking "Provide a menu link" disables the link.
 *
 * Core menu_ui deletes the node's menu link when the box is unchecked, which
 * drops the page's breadcrumb trail and flattens its alias. ys_core swaps
 * core's submit handler for one that disables the link instead.
 *
 * @see https://github.com/yalesites-org/YaleSites-Internal/issues/1406
 *
 * @group ys_core
 */
class MenuLinkDisableNotDeleteTest extends YsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'link',
    'node',
    'menu_link_content',
    'menu_ui',
    'ys_core',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('menu_link_content');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'filter', 'node']);
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
  }

  /**
   * Runs ys_core's node form menu submit handler with the given menu values.
   */
  protected function submitMenu(NodeInterface $node, array $menu): void {
    $form_object = $this->container->get('entity_type.manager')->getFormObject('node', 'default');
    $form_object->setEntity($node);
    $form_state = (new FormState())->setFormObject($form_object);
    $form_state->setValue('menu', $menu + [
      'entity_id' => 0,
      'title' => 'Child',
      'description' => '',
      'menu_parent' => 'main:',
      'weight' => 0,
    ]);
    $form = [];
    ys_core_menu_ui_node_form_submit($form, $form_state);
  }

  /**
   * Returns the menu links that point at a node.
   *
   * @return \Drupal\menu_link_content\MenuLinkContentInterface[]
   *   The links, keyed by id.
   */
  protected function linksFor(NodeInterface $node): array {
    $storage = $this->container->get('entity_type.manager')->getStorage('menu_link_content');
    $storage->resetCache();
    return $storage->loadByProperties(['link.uri' => 'entity:node/' . $node->id()]);
  }

  /**
   * Covers create, uncheck (disable), re-check (re-enable) and no-op paths.
   */
  public function testUncheckDisablesAndRecheckReenables(): void {
    $node = Node::create(['type' => 'page', 'title' => 'Child']);
    $node->save();

    // Unchecked with no link: nothing is created.
    $this->submitMenu($node, ['enabled' => 0]);
    $this->assertCount(0, $this->linksFor($node));

    // Checked with no link: core creates an enabled link.
    $this->submitMenu($node, ['enabled' => 1]);
    $links = $this->linksFor($node);
    $this->assertCount(1, $links);
    $link = reset($links);
    $this->assertTrue($link->isEnabled());
    $id = $link->id();

    // Unchecked with a link: the link is kept, disabled, not edited.
    $this->submitMenu($node, [
      'enabled' => 0,
      'entity_id' => $id,
      'title' => 'Ignored',
    ]);
    $links = $this->linksFor($node);
    $this->assertCount(1, $links);
    $this->assertArrayHasKey($id, $links);
    $this->assertFalse($links[$id]->isEnabled());
    $this->assertSame('Child', $links[$id]->getTitle());

    // Unchecked again: still one disabled link.
    $this->submitMenu($node, ['enabled' => 0, 'entity_id' => $id]);
    $links = $this->linksFor($node);
    $this->assertCount(1, $links);
    $this->assertFalse($links[$id]->isEnabled());

    // Re-checked: the same link is re-enabled and form edits apply.
    $this->submitMenu($node, [
      'enabled' => 1,
      'entity_id' => $id,
      'title' => 'Renamed',
      'weight' => 5,
    ]);
    $links = $this->linksFor($node);
    $this->assertCount(1, $links);
    $this->assertArrayHasKey($id, $links);
    $this->assertTrue($links[$id]->isEnabled());
    $this->assertSame('Renamed', $links[$id]->getTitle());
    $this->assertEquals(5, $links[$id]->getWeight());
  }

  /**
   * The checkbox reflects the link state and the submit handler is swapped.
   */
  public function testFormAlter(): void {
    $node = Node::create(['type' => 'page', 'title' => 'Child']);
    $node->save();
    $link = MenuLinkContent::create([
      'title' => 'Child',
      'link' => ['uri' => 'entity:node/' . $node->id()],
      'menu_name' => 'main',
      'enabled' => 0,
    ]);
    $link->save();

    $build = function () use ($link) {
      return [
        'menu' => [
          'enabled' => ['#type' => 'checkbox', '#default_value' => 1],
          'link' => ['entity_id' => ['#type' => 'value', '#value' => $link->id()]],
        ],
        'actions' => [
          'submit' => [
            '#type' => 'submit',
            '#submit' => ['::submitForm', '::save', 'menu_ui_form_node_form_submit'],
          ],
        ],
      ];
    };

    $form = $build();
    ys_core_form_node_form_alter($form, new FormState());
    $this->assertSame(0, $form['menu']['enabled']['#default_value']);
    $this->assertContains('ys_core_node_menu_builder', $form['#entity_builders']);
    $this->assertNotEmpty($form['menu']['enabled']['#description']);
    $this->assertSame(
      ['::submitForm', '::save', 'ys_core_menu_ui_node_form_submit'],
      $form['actions']['submit']['#submit'],
    );

    $link->set('enabled', TRUE)->save();
    $form = $build();
    ys_core_form_node_form_alter($form, new FormState());
    $this->assertSame(1, $form['menu']['enabled']['#default_value']);
  }

  /**
   * Returns a copy of the node set up as a pending (draft) revision.
   */
  protected function draft(NodeInterface $node): NodeInterface {
    // Fresh copy: flags set on a statically cached node would leak into the
    // next load.
    $draft = $this->container->get('entity_type.manager')->getStorage('node')->loadUnchanged($node->id());
    $draft->setNewRevision(TRUE);
    $draft->isDefaultRevision(FALSE);
    return $draft;
  }

  /**
   * Returns the menu violations for a draft carrying the given menu values.
   */
  protected function draftMenuViolations(NodeInterface $node, array $menu, bool $run_builder): int {
    $draft = $this->draft($node);
    // What menu_ui_node_builder() puts on the node.
    $draft->menu = $menu + [
      'title' => 'Child',
      'description' => '',
      'menu_parent' => 'main:',
      'weight' => 0,
    ];
    if ($run_builder) {
      $form = [];
      ys_core_node_menu_builder('node', $draft, $form, new FormState());
    }
    $count = 0;
    foreach ($draft->validate() as $violation) {
      $count += (int) str_starts_with($violation->getPropertyPath(), 'menu');
    }
    return $count;
  }

  /**
   * Menu changes on a draft stay out of the live menu until published.
   */
  public function testDraftRevisions(): void {
    $node = Node::create(['type' => 'page', 'title' => 'Child', 'status' => 1]);
    $node->save();
    $link = MenuLinkContent::create([
      'title' => 'Child',
      'link' => ['uri' => 'entity:node/' . $node->id()],
      'menu_name' => 'main',
      'enabled' => 0,
    ]);
    $link->save();
    $id = $link->id();
    $storage = $this->container->get('entity_type.manager')->getStorage('menu_link_content');
    $menu_link_manager = $this->container->get('plugin.manager.menu.link');

    // A draft that leaves an already-disabled link unchecked must validate.
    // Without the builder core reads it as removing the link in a draft.
    $unchecked = ['enabled' => 0, 'entity_id' => $id];
    $this->assertSame(1, $this->draftMenuViolations($node, $unchecked, FALSE));
    $this->assertSame(0, $this->draftMenuViolations($node, $unchecked, TRUE));

    // Re-checking on a draft does not enable the live link.
    $this->submitMenu($this->draft($node), ['enabled' => 1, 'entity_id' => $id]);
    $storage->resetCache();
    $this->assertFalse($storage->load($id)->isEnabled());
    $this->assertFalse((bool) $menu_link_manager->getDefinition($link->getPluginId())['enabled']);
    $this->assertCount(1, $this->linksFor($node));

    // Publishing with the box checked enables it.
    $published = $this->container->get('entity_type.manager')->getStorage('node')->loadUnchanged($node->id());
    $this->submitMenu($published, ['enabled' => 1, 'entity_id' => $id]);
    $storage->resetCache();
    $this->assertTrue($storage->load($id)->isEnabled());
    $this->assertTrue((bool) $menu_link_manager->getDefinition($link->getPluginId())['enabled']);
    // The published link is also the latest revision, so the form (which
    // reads the latest revision) agrees with the live menu.
    $repository = $this->container->get('entity.repository');
    $this->assertSame($storage->load($id)->getRevisionId(), $repository->getActive('menu_link_content', $id)->getRevisionId());

    // Unchecking an enabled link on a draft never disables the live link.
    $this->submitMenu($this->draft($node), ['enabled' => 0, 'entity_id' => $id]);
    $storage->resetCache();
    $this->assertTrue($storage->load($id)->isEnabled());

    // Unchecking on a published save disables it, in the latest revision too.
    $published = $this->container->get('entity_type.manager')->getStorage('node')->loadUnchanged($node->id());
    $this->submitMenu($published, ['enabled' => 0, 'entity_id' => $id]);
    $storage->resetCache();
    $this->assertFalse($storage->load($id)->isEnabled());
    $this->assertFalse($repository->getActive('menu_link_content', $id)->isEnabled());

    // A draft re-enables it, then the editor unchecks and publishes. The
    // draft's pending enabled revision must not stay the latest one.
    $this->submitMenu($this->draft($node), ['enabled' => 1, 'entity_id' => $id]);
    $storage->resetCache();
    $this->assertTrue($repository->getActive('menu_link_content', $id)->isEnabled());
    $published = $this->container->get('entity_type.manager')->getStorage('node')->loadUnchanged($node->id());
    $this->submitMenu($published, ['enabled' => 0, 'entity_id' => $id]);
    $storage->resetCache();
    $this->assertFalse($storage->load($id)->isEnabled());
    $this->assertFalse($repository->getActive('menu_link_content', $id)->isEnabled());
  }

}
