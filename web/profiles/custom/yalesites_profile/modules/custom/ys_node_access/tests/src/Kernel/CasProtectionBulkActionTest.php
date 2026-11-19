<?php

namespace Drupal\Tests\ys_node_access\Kernel;

use Drupal\Core\Form\EnforcedResponseException;
use Drupal\Core\Form\FormState;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\Tests\ys_core\Traits\ReadsProfileConfigTrait;
use Drupal\system\Entity\Action;
use Drupal\ys_node_access\CasProtectionBatch;
use Drupal\ys_node_access\Controller\CasUnprotectController;
use Drupal\ys_node_access\Form\CasProtectConfirmForm;
use Drupal\ys_node_access\Plugin\Action\CasProtect;
use Drupal\ys_node_access\Plugin\Action\CasUnprotect;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the CAS protect and Remove CAS protection bulk actions end to end.
 *
 * Everything from what the Manage views offer, through the selection the
 * actions record, to the confirmation form and the removal route that start
 * the batch. Core's node_bulk_form itself is not driven here: what it does
 * with an action -- check access(), call execute(), then follow the plugin's
 * confirm_form_route_name -- is core's contract, so these tests check each
 * side of it instead.
 *
 * @group yalesites
 * @group ys_node_access
 */
class CasProtectionBulkActionTest extends CasProtectionKernelTestBase {

  use ReadsProfileConfigTrait;

  /**
   * The Manage views the actions are offered on, one per content type.
   */
  protected const MANAGE_VIEWS = [
    'manage_events',
    'manage_pages',
    'manage_posts',
    'manage_profiles',
    'manage_resources',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->container->get('router.builder')->rebuild();
    foreach (['node_cas_protect_action', 'node_cas_unprotect_action'] as $id) {
      Action::create($this->readConfig('system.action.' . $id))->save();
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    // batch_get() is a plain PHP static, and kernel tests here share a process.
    $batch = &batch_get();
    $batch = [];
    parent::tearDown();
  }

  /**
   * Both actions are on every Manage view, as the platform ships them.
   */
  public function testEveryManageViewOffersBothActions(): void {
    foreach (self::MANAGE_VIEWS as $view_id) {
      $view = $this->readConfig('views.view.' . $view_id);
      $bulk_form = $view['display']['default']['display_options']['fields']['node_bulk_form'];
      $this->assertSame('include', $bulk_form['include_exclude'], $view_id);
      $this->assertContains('node_cas_protect_action', $bulk_form['selected_actions'], $view_id);
      $this->assertContains('node_cas_unprotect_action', $bulk_form['selected_actions'], $view_id);
    }
  }

  /**
   * The shipped action config uses the editor-facing labels and plugins.
   */
  public function testActionConfigUsesTheExpectedLabelsAndPlugins(): void {
    $protect = Action::load('node_cas_protect_action');
    $this->assertSame('CAS protect', $protect->label());
    $this->assertSame('node', $protect->getType());
    $this->assertInstanceOf(CasProtect::class, $protect->getPlugin());

    $unprotect = Action::load('node_cas_unprotect_action');
    $this->assertSame('Remove CAS protection', $unprotect->label());
    $this->assertSame('node', $unprotect->getType());
    $this->assertInstanceOf(CasUnprotect::class, $unprotect->getPlugin());
  }

  /**
   * Running an action only records the selection; nothing is saved yet.
   */
  public function testActionsRecordTheSelectionWithoutSaving(): void {
    $this->setCurrentUser($this->editor);
    $first = $this->createCasNode('published', FALSE, 'First');
    $second = $this->createCasNode('published', FALSE, 'Second');

    Action::load('node_cas_protect_action')->execute([$first, $second]);

    $store = $this->container->get('tempstore.private')->get(CasProtectionBatch::TEMPSTORE);
    $this->assertSame([(int) $first->id(), (int) $second->id()], $store->get(CasProtectionBatch::PROTECT));
    $this->assertNull($store->get(CasProtectionBatch::UNPROTECT), 'A protect selection is never visible to the removal route.');
    $this->assertEquals($first->getRevisionId(), $this->reloadNode($first->id())->getRevisionId(), 'Nothing was saved.');
    $this->assertFalse((bool) $this->reloadNode($first->id())->get('field_login_required')->value);

    Action::load('node_cas_unprotect_action')->execute([$second]);
    $this->assertSame([(int) $second->id()], $store->get(CasProtectionBatch::UNPROTECT));
    $this->assertSame([(int) $first->id(), (int) $second->id()], $store->get(CasProtectionBatch::PROTECT));
  }

  /**
   * The bulk form only offers each row to someone who could change it.
   */
  public function testActionAccessFollowsTheUpdaterRules(): void {
    $published = $this->createCasNode('published', FALSE);
    $draft = $this->createCasNode('draft', FALSE);

    foreach (['node_cas_protect_action', 'node_cas_unprotect_action'] as $id) {
      $plugin = Action::load($id)->getPlugin();
      $this->assertTrue($plugin->access($published, $this->editor), $id);
      $this->assertFalse($plugin->access($published, $this->contributor), $id);
      $this->assertTrue($plugin->access($draft, $this->contributor), $id);
    }
  }

  /**
   * Each action sends the bulk form to its own route.
   */
  public function testActionsRedirectToTheirRoutes(): void {
    $definitions = $this->container->get('plugin.manager.action')->getDefinitions();
    $this->assertSame('ys_node_access.cas_protect_confirm', $definitions['ys_node_access_cas_protect']['confirm_form_route_name']);
    $this->assertSame('ys_node_access.cas_unprotect', $definitions['ys_node_access_cas_unprotect']['confirm_form_route_name']);

    $route_provider = $this->container->get('router.route_provider');
    $confirm = $route_provider->getRouteByName('ys_node_access.cas_protect_confirm');
    $this->assertSame('access content overview', $confirm->getRequirement('_permission'));
    $remove = $route_provider->getRouteByName('ys_node_access.cas_unprotect');
    $this->assertSame('access content overview', $remove->getRequirement('_permission'));
  }

  /**
   * The URL the bulk form redirects to actually lets the editor in.
   *
   * This follows the bulk form's own path -- a form redirect to the action's
   * confirm_form_route_name, built by the form submitter -- and then checks
   * access on the resulting URL. Checking each route's requirements on their
   * own is not enough: the removal route once required a CSRF token, which
   * looked satisfiable that way, but a URL built for a redirect only ever
   * carries the token's render placeholder, so every editor was denied.
   */
  public function testEditorCanFollowTheBulkFormRedirect(): void {
    $this->setCurrentUser($this->editor);
    $session = $this->container->get('request_stack')->getCurrentRequest()->getSession();

    foreach (['ys_node_access.cas_protect_confirm', 'ys_node_access.cas_unprotect'] as $route_name) {
      $form_state = new FormState();
      $form_state->setRedirect($route_name, [], ['query' => ['destination' => '/admin/content/manage-pages']]);
      $target = $this->container->get('form_submitter')->redirectForm($form_state)->getTargetUrl();

      $request = Request::create($target);
      $request->setSession($session);
      $request->attributes->add($this->container->get('router.no_access_checks')->matchRequest($request));
      $this->assertSame($route_name, $request->attributes->get(RouteObjectInterface::ROUTE_NAME));
      $this->assertTrue($this->container->get('access_manager')->checkRequest($request, $this->editor), "The editor can open $target");
    }
  }

  /**
   * The confirmation shows the node form's warning and the selected items.
   */
  public function testConfirmationShowsTheWarningAndTheSelection(): void {
    $this->setCurrentUser($this->editor);
    $first = $this->createCasNode('published', FALSE, 'First page');
    $second = $this->createCasNode('published', FALSE, 'Second page');
    Action::load('node_cas_protect_action')->execute([$first, $second]);

    $form = $this->container->get('form_builder')->getForm(CasProtectConfirmForm::class);
    $this->assertSame('Enable CAS protection on 2 items?', (string) $form['#title']);
    $this->assertSame('I understand - Enable CAS Protection', (string) $form['actions']['submit']['#value']);
    $this->render($form);

    $this->assertText('⚠️ Warning: YaleSites is NOT secure for sensitive data');
    $this->assertText('Never upload personal information, student records, research data, or confidential documents to YaleSites. All content is stored on the public internet.');
    $this->assertText('CAS protection only limits who can view content - it does not make this platform secure for sensitive data.');
    $this->assertLink("Learn more about Yale's Data Classification Policy");
    $this->assertLinkByHref('https://your.yale.edu/policies-procedures/policies/1604-data-classification-policy#1604.1');
    $this->assertText('CAS protection will be enabled on:');
    $this->assertText('First page');
    $this->assertText('Second page');
    $this->assertLink('Cancel');
  }

  /**
   * With nothing waiting to be confirmed, the page sends the editor back.
   */
  public function testConfirmationWithoutSelectionRedirects(): void {
    $this->setCurrentUser($this->editor);
    try {
      $this->container->get('form_builder')->getForm(CasProtectConfirmForm::class);
      $this->fail('The confirmation form should redirect when nothing is selected.');
    }
    catch (EnforcedResponseException $e) {
      $this->assertInstanceOf(RedirectResponse::class, $e->getResponse());
    }
  }

  /**
   * Confirming runs the batch: every item changes, and the editor is told.
   */
  public function testConfirmingProtectsTheSelection(): void {
    $this->setCurrentUser($this->editor);
    $first = $this->createCasNode('published', FALSE, 'First page');
    $second = $this->createCasNode('published', TRUE, 'Already protected');
    Action::load('node_cas_protect_action')->execute([$first, $second]);
    $this->pushRouteRequest('ys_node_access.cas_protect_confirm');

    $form_state = new FormState();
    $this->container->get('form_builder')->submitForm(CasProtectConfirmForm::class, $form_state);

    $this->assertTrue((bool) $this->reloadNode($first->id())->get('field_login_required')->value);
    $this->assertEquals($second->getRevisionId(), $this->reloadNode($second->id())->getRevisionId());
    $this->assertNull($this->container->get('tempstore.private')->get(CasProtectionBatch::TEMPSTORE)->get(CasProtectionBatch::PROTECT), 'The selection cannot be run twice.');
    $this->assertSame([
      'Enabled CAS protection on 1 item.',
      '1 item was already CAS protected, so it was skipped.',
    ], array_map('strval', $this->container->get('messenger')->messagesByType('status')));
  }

  /**
   * The removal route starts its batch at once, with no confirmation step.
   */
  public function testRemovalRouteStartsTheBatch(): void {
    $this->setCurrentUser($this->editor);
    $node = $this->createCasNode('published', TRUE);
    Action::load('node_cas_unprotect_action')->execute([$node]);
    $this->pushRouteRequest('ys_node_access.cas_unprotect');

    $response = CasUnprotectController::create($this->container)->start();

    $this->assertInstanceOf(RedirectResponse::class, $response);
    $this->assertStringContainsString('/batch', $response->getTargetUrl());
    // batch_process() has moved the operation into the batch's queue.
    require_once $this->root . '/core/includes/batch.inc';
    $set = batch_get()['sets'][0];
    $this->assertSame('Removing CAS protection', (string) $set['title']);
    $this->assertSame([[CasProtectionBatch::class, 'process'], [[(int) $node->id()], FALSE]], _batch_queue($set)->claimItem()->data);
    $this->assertTrue((bool) $this->reloadNode($node->id())->get('field_login_required')->value, 'Nothing is saved until the batch runs.');
    $this->assertNull($this->container->get('tempstore.private')->get(CasProtectionBatch::TEMPSTORE)->get(CasProtectionBatch::UNPROTECT), 'The selection cannot be run twice.');
  }

  /**
   * With nothing waiting to be removed, the route sends the editor back.
   */
  public function testRemovalRouteWithoutSelectionRedirects(): void {
    $this->setCurrentUser($this->editor);
    $this->pushRouteRequest('ys_node_access.cas_unprotect');

    $response = CasUnprotectController::create($this->container)->start();

    $this->assertInstanceOf(RedirectResponse::class, $response);
    $this->assertStringNotContainsString('/batch', $response->getTargetUrl());
    $this->assertEmpty(batch_get());
  }

  /**
   * Makes the given route the current one, as batch_process() requires.
   *
   * @param string $route_name
   *   The route name.
   */
  protected function pushRouteRequest(string $route_name): void {
    $route = $this->container->get('router.route_provider')->getRouteByName($route_name);
    $request = Request::create($route->getPath());
    $request->attributes->set(RouteObjectInterface::ROUTE_NAME, $route_name);
    $request->attributes->set(RouteObjectInterface::ROUTE_OBJECT, $route);
    $request->setSession($this->container->get('request_stack')->getCurrentRequest()->getSession());
    $this->container->get('request_stack')->push($request);
  }

}
