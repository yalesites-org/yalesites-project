<?php

namespace Drupal\ys_layouts\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\FileInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Handles resource file downloads with forced download behavior.
 */
class ResourceDownloadController extends ControllerBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * Constructs a ResourceDownloadController object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   The file system service.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, FileSystemInterface $file_system) {
    $this->entityTypeManager = $entity_type_manager;
    $this->fileSystem = $file_system;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('file_system')
    );
  }

  /**
   * Downloads a file with forced download headers.
   *
   * @param int $file_id
   *   The file entity ID.
   *
   * @return \Symfony\Component\HttpFoundation\BinaryFileResponse
   *   The file download response.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
   *   Thrown when the file is not found or not accessible.
   */
  public function download($file_id) {
    /** @var \Drupal\file\FileInterface $file */
    $file = $this->entityTypeManager->getStorage('file')->load($file_id);

    if (!$file || !($file instanceof FileInterface)) {
      throw new NotFoundHttpException('File not found.');
    }

    // Get the file URI and convert to real path.
    $uri = $file->getFileUri();
    $path = $this->fileSystem->realpath($uri);

    if (!$path || !file_exists($path)) {
      throw new NotFoundHttpException('File not found on disk.');
    }

    // Create binary file response with forced download.
    $response = new BinaryFileResponse($path);
    $response->setContentDisposition(
      ResponseHeaderBag::DISPOSITION_ATTACHMENT,
      $file->getFilename()
    );

    // Set appropriate headers.
    $response->headers->set('Content-Type', $file->getMimeType());
    $response->headers->set('Content-Length', $file->getSize());

    return $response;
  }

  /**
   * Access callback for the download route.
   *
   * @param int $file_id
   *   The file entity ID.
   *
   * @return \Drupal\Core\Access\AccessResult
   *   The access result.
   */
  public function access($file_id) {
    /** @var \Drupal\file\FileInterface $file */
    $file = $this->entityTypeManager->getStorage('file')->load($file_id);

    if (!$file || !($file instanceof FileInterface)) {
      return AccessResult::forbidden('File not found.');
    }

    // Check if the file is associated with a published resource node.
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'resource')
      ->condition('status', 1)
      ->condition('field_media.entity.field_media_file.target_id', $file_id)
      ->accessCheck(TRUE);

    $results = $query->execute();

    if (empty($results)) {
      return AccessResult::forbidden('File not associated with a published resource.');
    }

    return AccessResult::allowed();
  }

}
