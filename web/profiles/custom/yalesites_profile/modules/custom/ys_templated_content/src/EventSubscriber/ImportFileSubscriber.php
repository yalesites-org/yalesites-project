<?php

namespace Drupal\ys_templated_content\EventSubscriber;

use Drupal\single_content_sync\Event\ImportEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Modifies the import data before it is imported.
 */
class ImportFileSubscriber implements EventSubscriberInterface {

  /**
   * The template modifier service.
   *
   * @var \Drupal\ys_templated_content\TemplateModifier
   */
  protected $templateModifier;

  /**
   * Constructs a new ImportFileSubscriber object.
   */
  public function __construct() {
    $this->templateModifier = \Drupal::service('ys_templated_content.template_modifier');
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    $events[ImportEvent::class][] = ['onImportEvent'];
    return $events;
  }

  /**
   * Resets UUIDs on import.
   *
   * @param \Drupal\single_content_sync\Event\ImportEvent $event
   *   The event to process.
   */
  public function onImportEvent(ImportEvent $event) {
    $content = $event->getContent();

    $modifiedContent = $this->templateModifier->process($content);
    $event->setContent($modifiedContent);

    return $event;
  }

}
