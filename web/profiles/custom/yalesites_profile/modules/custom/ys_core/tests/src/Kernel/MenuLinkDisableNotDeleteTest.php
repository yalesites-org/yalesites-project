<?php

namespace Drupal\Tests\ys_core\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\menu_link_content\Entity\MenuLinkContent;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;

/**
 * Tests the node form's "Hide from menu" option.
 *
 * Hiding disables the node's menu link instead of deleting it, so the page
 * keeps its breadcrumb trail and nested alias. Unchecking "Provide a menu
 * link" still deletes it, as core does.
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
   * Covers create, hide, show, and uncheck (delete) on published saves.
   */
  public function testHideShowAndRemove(): void {
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

    // Hide on: the same link is kept, disabled, and form edits apply.
    $this->submitMenu($node, [
      'enabled' => 1,
      'hidden' => 1,
      'entity_id' => $id,
      'title' => 'Renamed',
    ]);
    $links = $this->linksFor($node);
    $this->assertCount(1, $links);
    $this->assertArrayHasKey($id, $links);
    $this->assertFalse($links[$id]->isEnabled());
    $this->assertSame('Renamed', $links[$id]->getTitle());

    // Hide off: the same link is re-enabled.
    $this->submitMenu($node, [
      'enabled' => 1,
      'hidden' => 0,
      'entity_id' => $id,
      'weight' => 5,
    ]);
    $links = $this->linksFor($node);
    $this->assertCount(1, $links);
    $this->assertTrue($links[$id]->isEnabled());
    $this->assertEquals(5, $links[$id]->getWeight());

    // Unchecking "Provide a menu link" deletes it, as core does, even when
    // the link is hidden.
    $this->submitMenu($node, ['enabled' => 1, 'hidden' => 1, 'entity_id' => $id]);
    $this->submitMenu($node, ['enabled' => 0, 'hidden' => 1, 'entity_id' => $id]);
    $this->assertCount(0, $this->linksFor($node));
  }

  /**
   * The hide box reflects the link state and the submit handler is swapped.
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

    $build = function ($entity_id) {
      return [
        'menu' => [
          'enabled' => ['#type' => 'checkbox', '#default_value' => (int) (bool) $entity_id],
          'link' => ['entity_id' => ['#type' => 'value', '#value' => $entity_id]],
        ],
        'actions' => [
          'submit' => [
            '#type' => 'submit',
            '#submit' => ['::submitForm', '::save', 'menu_ui_form_node_form_submit'],
          ],
        ],
      ];
    };

    // A link disabled elsewhere loads as provided and hidden.
    $form = $build($link->id());
    ys_core_form_node_form_alter($form, new FormState());
    $this->assertSame(1, $form['menu']['enabled']['#default_value']);
    $this->assertNotEmpty($form['menu']['enabled']['#description']);
    $this->assertSame(1, $form['menu']['link']['hidden']['#default_value']);
    $this->assertContains('ys_core/menu_link_form', $form['menu']['#attached']['library']);
    $this->assertSame(
      ['::submitForm', '::save', 'ys_core_menu_ui_node_form_submit'],
      $form['actions']['submit']['#submit'],
    );

    $link->set('enabled', TRUE)->save();
    $form = $build($link->id());
    ys_core_form_node_form_alter($form, new FormState());
    $this->assertSame(0, $form['menu']['link']['hidden']['#default_value']);

    // No link yet: no hide box.
    $form = $build(0);
    ys_core_form_node_form_alter($form, new FormState());
    $this->assertArrayNotHasKey('hidden', $form['menu']['link']);
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
  protected function draftMenuViolations(NodeInterface $node, array $menu): int {
    $draft = $this->draft($node);
    // What menu_ui_node_builder() puts on the node.
    $draft->menu = $menu + [
      'title' => 'Child',
      'description' => '',
      'menu_parent' => 'main:',
      'weight' => 0,
    ];
    $count = 0;
    foreach ($draft->validate() as $violation) {
      $count += (int) str_starts_with($violation->getPropertyPath(), 'menu');
    }
    return $count;
  }

  /**
   * Hide changes on a draft stay out of the live menu until published.
   */
  public function testDraftRevisions(): void {
    $node = Node::create(['type' => 'page', 'title' => 'Child', 'status' => 1]);
    $node->save();
    $link = MenuLinkContent::create([
      'title' => 'Child',
      'link' => ['uri' => 'entity:node/' . $node->id()],
      'menu_name' => 'main',
      'enabled' => 1,
    ]);
    $link->save();
    $id = $link->id();
    $storage = $this->container->get('entity_type.manager')->getStorage('menu_link_content');
    $node_storage = $this->container->get('entity_type.manager')->getStorage('node');
    $repository = $this->container->get('entity.repository');
    $menu_link_manager = $this->container->get('plugin.manager.menu.link');
    $live = function () use ($storage, $id) {
      $storage->resetCache();
      return $storage->load($id)->isEnabled();
    };
    $latest = function () use ($storage, $repository, $id) {
      $storage->resetCache();
      return $repository->getActive('menu_link_content', $id)->isEnabled();
    };

    // Hiding or keeping a link on a draft validates; removing it does not
    // (core refuses removal in a pending revision).
    $this->assertSame(0, $this->draftMenuViolations($node, ['enabled' => 1, 'hidden' => 1, 'entity_id' => $id]));
    $this->assertSame(1, $this->draftMenuViolations($node, ['enabled' => 0, 'hidden' => 0, 'entity_id' => $id]));

    // Hiding on a draft does not disable the live link, but the form (which
    // reads the latest revision) shows it hidden.
    $this->submitMenu($this->draft($node), ['enabled' => 1, 'hidden' => 1, 'entity_id' => $id]);
    $this->assertTrue($live());
    $this->assertTrue((bool) $menu_link_manager->getDefinition($link->getPluginId())['enabled']);
    $this->assertFalse($latest());
    $this->assertCount(1, $this->linksFor($node));

    // Publishing with hide on disables it, live and latest agree.
    $this->submitMenu($node_storage->loadUnchanged($node->id()), ['enabled' => 1, 'hidden' => 1, 'entity_id' => $id]);
    $this->assertFalse($live());
    $this->assertFalse($latest());
    $this->assertFalse((bool) $menu_link_manager->getDefinition($link->getPluginId())['enabled']);
    $this->assertSame($storage->load($id)->getRevisionId(), $repository->getActive('menu_link_content', $id)->getRevisionId());

    // A draft that keeps the already-hidden link hidden validates.
    $this->assertSame(0, $this->draftMenuViolations($node, ['enabled' => 1, 'hidden' => 1, 'entity_id' => $id]));

    // Showing on a draft does not enable the live link.
    $this->submitMenu($this->draft($node), ['enabled' => 1, 'hidden' => 0, 'entity_id' => $id]);
    $this->assertFalse($live());
    $this->assertTrue($latest());

    // A published save that keeps it hidden supersedes the draft's pending
    // enabled revision.
    $this->submitMenu($node_storage->loadUnchanged($node->id()), ['enabled' => 1, 'hidden' => 1, 'entity_id' => $id]);
    $this->assertFalse($live());
    $this->assertFalse($latest());

    // Publishing with hide off enables it.
    $this->submitMenu($node_storage->loadUnchanged($node->id()), ['enabled' => 1, 'hidden' => 0, 'entity_id' => $id]);
    $this->assertTrue($live());
    $this->assertTrue($latest());
  }

}
