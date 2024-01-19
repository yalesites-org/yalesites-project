<?php

namespace Drupal\ys_templated_content\Form;

use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\single_content_sync\ContentImporterInterface;
use Drupal\single_content_sync\ContentSyncHelperInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for allowing users to create nodes based on templated content.
 */
class TemplatedContentForm extends FormBase implements FormInterface {

  /*
   * Sample content to import.
   *
   * @var string
   */
  const CONTENT = <<<EOT
site_uuid: 3be7d457-cc13-4e03-bde2-d004a9e0f46e
uuid: 15011b23-65a3-4083-b7e3-2e861f33a2fe
entity_type: node
bundle: page
base_fields:
  title: 'Test Page'
  status: true
  langcode: en
  created: '1705614253'
  author: admin@example.com
  url: /test-page
  revision_log_message: null
  revision_uid: '1'
custom_fields:
  field_login_required:
    -
      value: '0'
  field_metatags: null
  field_tags: null
  field_teaser_media:
    -
      uuid: b7103556-2f77-429a-b2f9-159617684731
      entity_type: media
      bundle: image
      base_fields:
        name: students-cross-campus_anna-zhang.jpg
        created: '1693934413'
        status: true
        langcode: en
      custom_fields:
        field_media_image:
          -
            uri: 'public://2023-09/students-cross-campus_anna-zhang.jpg'
            url: 'https://yalesites-platform.lndo.site/sites/default/files/2023-09/students-cross-campus_anna-zhang.jpg'
            alt: "students enjoy the vibrancy of Yale's campus scenery"
            title: ''
  field_teaser_text:
    -
      value: '<p>This is only a test</p>'
      format: heading_html
  field_teaser_title:
    -
      value: 'A test to remember'
  layout_builder__layout: null
EOT;

  /**
   * The UUID service.
   *
   * @var \Drupal\Core\Uuid\UuidInterface
   */
  protected $uuidService;

  /**
   * The content importer.
   *
   * @var \Drupal\single_content_sync\ContentImporterInterface
   */
  protected $contentImporter;

  /**
   * The content sync helper.
   *
   * @var \Drupal\single_content_sync\ContentSyncHelperInterface
   */
  protected $contentSyncHelper;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityManager;

  /**
   * The available templates.
   *
   * @var array
   */
  protected const TEMPLATES = [
    'page' => [
      '' => 'Empty',
      'faq' => 'FAQ',
      'landing_page' => 'Landing Page',
    ],
    'event' => [
      '' => 'Empty',
      'in_person' => 'In Person',
      'online' => 'Online',
    ],
    'post' => [
      '' => 'Empty',
      'blog' => 'Blog',
      'news' => 'News',
      'press_release' => 'Press Release',
    ],
    'profile' => [
      '' => 'Empty',
      'student' => 'Student',
      'faculty' => 'Faculty',
      'staff' => 'Staff',
    ],
  ];

  /**
   * Constructs the controller object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager to get the content types available.
   * @param \Drupal\single_content_sync\ContentImporterInterface $contentImporter
   *   The content importer.
   * @param \Drupal\single_content_sync\ContentSyncHelperInterface $contentSyncHelper
   *   The content sync helper.
   * @param \Drupal\Core\Uuid\UuidInterface $uuidService
   *   The UUID service.
   */
  public function __construct(EntityTypeManagerInterface $entityTypeManager, ContentImporterInterface $contentImporter, ContentSyncHelperInterface $contentSyncHelper, UuidInterface $uuidService) {
    $this->entityManager = $entityTypeManager;
    $this->contentImporter = $contentImporter;
    $this->contentSyncHelper = $contentSyncHelper;
    $this->uuidService = $uuidService;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('single_content_sync.importer'),
      $container->get('single_content_sync.helper'),
      $container->get('uuid'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'templated_content_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $currentContentType = $this->getCurrentContentType($form_state);

    $form['content_types'] = [
      '#type' => 'select',
      '#title' => $this->t('Content Type'),
      '#options' => $this->getContentTypes(),
      '#required' => TRUE,
      '#default_value' => 'page',
      '#ajax' => [
        'callback' => [$this, 'updateTemplates'],
        'wrapper' => 'template-update-wrapper',
        'disable-refocus' => FALSE,
        'event' => 'change',
        'progress' => [
          'type' => 'none',
        ],
      ],
    ];

    $form['templates'] = [
      '#type' => 'select',
      '#title' => $this->t('Template'),
      '#options' => $this->getCurrentTemplates($currentContentType),
      '#required' => FALSE,
      '#prefix' => '<div id="template-update-wrapper">',
      '#suffix' => '</div>',
    ];

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Create'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $content_type = $this->getCurrentContentType($form_state);
    $template = $form_state->getValue('templates');

    if ($template == '') {
      $form_state->setRedirect('node.add', ['node_type' => $content_type, 'template' => $template]);
    }
    else {
      $this->createImport($form_state, $content_type, $template);
    }
  }

  /**
   * Create the import from the sample content.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param string $content_type
   *   The content type.
   * @param string $template
   *   The template.
   *
   * @return void
   *   Redirects to the edit form of the imported entity.
   */
  protected function createImport(FormStateInterface $form_state, String $content_type, String $template) : void {
    try {
      $content_array = $this->contentSyncHelper->validateYamlFileContent($this::CONTENT);
      $content_array['uuid'] = $this->uuidService->generate();

      $entity = $this->contentImporter->doImport($content_array);
      $form_state->setRedirect('entity.' . $entity->getEntityTypeId() . '.edit_form', [$entity->getEntityTypeId() => $entity->id()]);
    }
    catch (\Exception $e) {
      $this->messenger()->addError($e->getMessage());
      return;
    }
  }

  /**
   * Get all content types.
   *
   * @return array
   *   The content types.
   */
  private function getContentTypes() : array {
    $content_types = $this->entityManager->getStorage('node_type')->loadMultiple();
    $options = [];

    foreach ($content_types as $content_type) {
      $options[$content_type->id()] = $content_type->label();
    }

    return $options;
  }

  /**
   * Update the template options when the content type changes.
   *
   * @param array $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array
   *   The updated template options.
   */
  public function updateTemplates(array &$form, FormStateInterface $form_state) : array {
    $currentContentType = $this->getCurrentContentType($form_state);
    $form['templates']['#options'] = $this->getCurrentTemplates($currentContentType);
    $form_state->setValue('templates', '');
    return $form['templates'];
  }

  /**
   * Get the content type from the form state.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return string
   *   The content type.
   */
  protected function getCurrentContentType(FormStateInterface $form_state) : String {
    return $form_state->getValue('content_types') ?? 'page';
  }

  /**
   * Get the template options for the currrent content type.
   *
   * @return array
   *   The template options.
   */
  protected function getCurrentTemplates($content_type) : array {
    $templates = [];

    // Return an empty array if there is no content type.
    if ($content_type) {
      $templates = self::TEMPLATES[$content_type];
    }

    return $templates;
  }

}
