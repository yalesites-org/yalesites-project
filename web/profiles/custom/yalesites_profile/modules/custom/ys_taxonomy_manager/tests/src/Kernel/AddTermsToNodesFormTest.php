<?php

namespace Drupal\Tests\ys_taxonomy_manager\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\Core\Url;
use Drupal\Tests\ys_core\Kernel\YsKernelTestBase;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\ys_taxonomy_manager\Form\AddTermsToNodesForm;

/**
 * Kernel tests for AddTermsToNodesForm.
 *
 * @coversDefaultClass \Drupal\ys_taxonomy_manager\Form\AddTermsToNodesForm
 * @group ys_taxonomy_manager
 * @group yalesites
 */
class AddTermsToNodesFormTest extends YsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'node',
    'taxonomy',
    'ys_taxonomy_manager',
  ];

  /**
   * The vocabulary referenced by the article bundle's field_tags field.
   *
   * @var \Drupal\taxonomy\VocabularyInterface
   */
  protected $vocabulary;

  /**
   * The form under test.
   *
   * @var \Drupal\ys_taxonomy_manager\Form\AddTermsToNodesForm
   */
  protected $form;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installSchema('node', 'node_access');
    $this->installSchema('system', 'sequences');

    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();

    $this->vocabulary = Vocabulary::create(['vid' => 'tags', 'name' => 'Tags']);
    $this->vocabulary->save();

    FieldStorageConfig::create([
      'field_name' => 'field_tags',
      'type' => 'entity_reference',
      'entity_type' => 'node',
      'cardinality' => -1,
      'settings' => ['target_type' => 'taxonomy_term'],
    ])->save();

    FieldConfig::create([
      'field_name' => 'field_tags',
      'entity_type' => 'node',
      'bundle' => 'article',
      'label' => 'Tags',
      'settings' => [
        'handler' => 'default',
        'handler_settings' => [
          'target_bundles' => [$this->vocabulary->id() => $this->vocabulary->id()],
        ],
      ],
    ])->save();

    // The form's node query runs with accessCheck(TRUE), so grant the
    // permission published nodes are gated behind and switch to that user.
    // The form only lists and updates nodes the user can edit, so grant that
    // too.
    $role = Role::create(['id' => 'tester', 'label' => 'Tester']);
    $role->grantPermission('access content');
    $role->grantPermission('edit any article content');
    $role->save();

    $user = User::create(['name' => 'tester', 'status' => 1]);
    $user->addRole('tester');
    $user->save();
    $this->container->get('current_user')->setAccount($user);

    $this->form = AddTermsToNodesForm::create($this->container);
  }

  /**
   * Tests the form ID.
   *
   * @covers ::getFormId
   */
  public function testGetFormId() {
    $this->assertEquals('ys_taxonomy_manager_add_terms_to_nodes_form', $this->form->getFormId());
  }

  /**
   * Shows "No nodes found" when no bundle references the vocabulary.
   *
   * With the empty-bundles guard in buildForm(), a vocabulary that no content
   * type references yields the "No nodes found" message instead of throwing an
   * invalid-query exception.
   *
   * @covers ::buildForm
   */
  public function testBuildFormShouldShowMessageWhenNoBundleReferencesVocabulary() {
    $empty_vocabulary = Vocabulary::create(['vid' => 'empty_vocab', 'name' => 'Empty Vocab']);
    $empty_vocabulary->save();

    $form_state = new FormState();
    $form = $this->form->buildForm([], $form_state, $empty_vocabulary);

    $this->assertStringContainsString('No nodes found', $form['message']['#markup']);
    $this->assertArrayNotHasKey('terms', $form);
    $this->assertArrayNotHasKey('nodes', $form);
  }

  /**
   * Tests buildForm() lists terms (by depth) and nodes with a match.
   *
   * @covers ::buildForm
   */
  public function testBuildFormListsTermsAndMatchingNodes() {
    $parent_term = Term::create(['vid' => $this->vocabulary->id(), 'name' => 'Parent Term']);
    $parent_term->save();
    $child_term = Term::create(['vid' => $this->vocabulary->id(), 'name' => 'Child Term']);
    $child_term->set('parent', $parent_term->id());
    $child_term->save();

    $matching_node = Node::create([
      'type' => 'article',
      'title' => 'Matching Article',
      'status' => 1,
    ]);
    $matching_node->save();

    // Unpublished nodes are excluded by the form's node query.
    $unpublished_node = Node::create([
      'type' => 'article',
      'title' => 'Unpublished Article',
      'status' => 0,
    ]);
    $unpublished_node->save();

    $form_state = new FormState();
    $form = $this->form->buildForm([], $form_state, $this->vocabulary);

    $this->assertEquals($this->vocabulary, $form['voc']['#value']);

    $this->assertEquals($parent_term->label(), $form['terms']['#options'][$parent_term->id()]);
    $this->assertEquals('-' . $child_term->label(), $form['terms']['#options'][$child_term->id()]);

    $expected_key = $matching_node->id() . '-field_tags';
    $this->assertArrayHasKey($expected_key, $form['nodes']['#options']);
    $this->assertEquals('Matching Article (field_tags)', $form['nodes']['#options'][$expected_key]);

    $unpublished_key = $unpublished_node->id() . '-field_tags';
    $this->assertArrayNotHasKey($unpublished_key, $form['nodes']['#options']);

    $this->assertEquals(['field_tags'], $form['#relevant_fields']);
  }

  /**
   * Tests submitForm() merges selected terms into the selected node fields.
   *
   * @covers ::submitForm
   */
  public function testSubmitFormAddsSelectedTermsToSelectedNodes() {
    $existing_term = Term::create(['vid' => $this->vocabulary->id(), 'name' => 'Existing Term']);
    $existing_term->save();
    $selected_term = Term::create(['vid' => $this->vocabulary->id(), 'name' => 'Selected Term']);
    $selected_term->save();
    $unselected_term = Term::create(['vid' => $this->vocabulary->id(), 'name' => 'Unselected Term']);
    $unselected_term->save();

    $node = Node::create([
      'type' => 'article',
      'title' => 'Target Article',
      'status' => 1,
      'field_tags' => [$existing_term->id()],
    ]);
    $node->save();

    $form = [];
    $form_state = new FormState();
    $form_state->setValue('voc', $this->vocabulary);
    // Checkboxes submit every option, keyed by tid; unchecked options carry
    // a value of 0.
    $form_state->setValue('terms', [
      $selected_term->id() => $selected_term->id(),
      $unselected_term->id() => 0,
    ]);
    $form_state->setValue('nodes', [$node->id() . '-field_tags']);

    $this->form->submitForm($form, $form_state);

    $node = \Drupal::entityTypeManager()->getStorage('node')->load($node->id());
    $tag_ids = array_column($node->get('field_tags')->getValue(), 'target_id');
    sort($tag_ids);
    $expected = [$existing_term->id(), $selected_term->id()];
    sort($expected);
    $this->assertEquals($expected, $tag_ids);

    $messages = \Drupal::messenger()->all();
    $this->assertNotEmpty($messages['status']);
    $this->assertStringContainsString('Selected Term', (string) $messages['status'][0]);
    $this->assertStringContainsString('Target Article', (string) $messages['status'][0]);

    $redirect = $form_state->getRedirect();
    $this->assertInstanceOf(Url::class, $redirect);
    $this->assertEquals('taxonomy_manager.admin_vocabulary', $redirect->getRouteName());
    $this->assertEquals($this->vocabulary->id(), $redirect->getRouteParameters()['taxonomy_vocabulary']);
  }

  /**
   * Tests nodes the user cannot edit are neither listed nor updated.
   *
   * The user can create terms and edit their own articles, but not an article
   * owned by someone else. Submitting the form for both nodes must add the
   * terms to the editable node only, leave the other untouched, and warn that
   * it was skipped.
   *
   * @covers ::buildForm
   * @covers ::submitForm
   */
  public function testFormSkipsNodesTheUserCannotEdit() {
    $role = Role::create(['id' => 'own_editor', 'label' => 'Own editor']);
    $role->grantPermission('access content');
    $role->grantPermission('create terms in ' . $this->vocabulary->id());
    $role->grantPermission('edit own article content');
    $role->save();

    $other_user = User::create(['name' => 'other', 'status' => 1]);
    $other_user->save();
    $limited_user = User::create(['name' => 'limited', 'status' => 1]);
    $limited_user->addRole('own_editor');
    $limited_user->save();
    $this->container->get('current_user')->setAccount($limited_user);

    $term = Term::create(['vid' => $this->vocabulary->id(), 'name' => 'New Term']);
    $term->save();

    $editable_node = Node::create([
      'type' => 'article',
      'title' => 'Editable Article',
      'status' => 1,
      'uid' => $limited_user->id(),
    ]);
    $editable_node->save();
    $protected_node = Node::create([
      'type' => 'article',
      'title' => 'Protected Article',
      'status' => 1,
      'uid' => $other_user->id(),
    ]);
    $protected_node->save();

    $editable_key = $editable_node->id() . '-field_tags';
    $protected_key = $protected_node->id() . '-field_tags';

    $form = $this->form->buildForm([], new FormState(), $this->vocabulary);
    $this->assertArrayHasKey($editable_key, $form['nodes']['#options']);
    $this->assertArrayNotHasKey($protected_key, $form['nodes']['#options']);

    // Submit both nodes anyway, as a forged form submission could.
    $form = [];
    $form_state = new FormState();
    $form_state->setValue('voc', $this->vocabulary);
    $form_state->setValue('terms', [$term->id() => $term->id()]);
    $form_state->setValue('nodes', [$editable_key, $protected_key]);

    $this->form->submitForm($form, $form_state);

    $node_storage = \Drupal::entityTypeManager()->getStorage('node');
    $node_storage->resetCache();

    $editable_node = $node_storage->load($editable_node->id());
    $this->assertEquals([$term->id()], array_column($editable_node->get('field_tags')->getValue(), 'target_id'));

    $protected_node = $node_storage->load($protected_node->id());
    $this->assertTrue($protected_node->get('field_tags')->isEmpty(), 'A node the user cannot edit must not be changed.');

    $messages = \Drupal::messenger()->all();
    $this->assertCount(1, $messages['status']);
    $this->assertStringContainsString('Editable Article', (string) $messages['status'][0]);
    $this->assertStringNotContainsString('Protected Article', (string) $messages['status'][0]);
    $this->assertCount(1, $messages['warning']);
    $this->assertStringContainsString('Protected Article', (string) $messages['warning'][0]);
  }

  /**
   * Tests no success message is shown when no node could be updated.
   *
   * Also covers a selected node that no longer exists: it is skipped rather
   * than causing a fatal error.
   *
   * @covers ::submitForm
   */
  public function testSubmitFormShowsNoSuccessMessageWhenNothingUpdated() {
    $term = Term::create(['vid' => $this->vocabulary->id(), 'name' => 'New Term']);
    $term->save();

    $form = [];
    $form_state = new FormState();
    $form_state->setValue('voc', $this->vocabulary);
    $form_state->setValue('terms', [$term->id() => $term->id()]);
    $form_state->setValue('nodes', ['999999-field_tags']);

    $this->form->submitForm($form, $form_state);

    $messages = \Drupal::messenger()->all();
    $this->assertArrayNotHasKey('status', $messages);
    $this->assertNotEmpty($messages['warning']);
  }

  /**
   * Shows a message when the user can edit none of the matching nodes.
   *
   * @covers ::buildForm
   */
  public function testBuildFormShowsMessageWhenNoNodeIsEditable() {
    $role = Role::create(['id' => 'term_creator', 'label' => 'Term creator']);
    $role->grantPermission('access content');
    $role->grantPermission('create terms in ' . $this->vocabulary->id());
    $role->save();

    $other_user = User::create(['name' => 'other', 'status' => 1]);
    $other_user->save();
    $term_creator = User::create(['name' => 'term_creator', 'status' => 1]);
    $term_creator->addRole('term_creator');
    $term_creator->save();
    $this->container->get('current_user')->setAccount($term_creator);

    Node::create([
      'type' => 'article',
      'title' => 'Someone Else Article',
      'status' => 1,
      'uid' => $other_user->id(),
    ])->save();

    $form = $this->form->buildForm([], new FormState(), $this->vocabulary);

    $this->assertStringContainsString('No content you can edit', (string) $form['message']['#markup']);
    $this->assertArrayNotHasKey('terms', $form);
    $this->assertArrayNotHasKey('nodes', $form);
    $this->assertArrayNotHasKey('submit', $form);
  }

}
