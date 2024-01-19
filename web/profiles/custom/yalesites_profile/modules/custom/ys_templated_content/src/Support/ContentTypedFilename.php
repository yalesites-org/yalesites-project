<?php

namespace Drupal\ys_templated_content\Support;

/**
 * Helper class for template filenames.
 */
class ContentTypedFilename {

  /**
   * The original filename.
   *
   * @var string
   */
  public readonly String $originalFilename;

  /**
   * The content type.
   *
   * @var string
   */
  public readonly String $contentType;

  /**
   * The template name.
   *
   * @var string
   */
  public readonly String $template;

  /**
   * The humanized template name.
   *
   * @var string
   */
  public readonly String $humanizedTemplateName;

  /**
   * ContentTypedFilename constructor.
   *
   * @param string $filename
   *   The filename of the form <content_type>__<template_name>.yml.
   */
  public function __construct(String $filename) {
    $this->originalFilename = $filename;

    $nameWithoutExtension = $this->getNameWithoutExtension($filename);
    $this->contentType = $this->getContentType($nameWithoutExtension);
    $this->template = $this->getTemplate($nameWithoutExtension);
    $this->humanizedTemplateName = $this->humanizedName($this->template);
  }

  /**
   * Get the name of the file without the extension.
   *
   * @param string $filename
   *   The filename.
   */
  protected function getNameWithoutExtension(String $filename) : String {
    $suffix = str_replace('.yml', '', $filename);
    return $suffix;
  }

  /**
   * Get the content type from the filename.
   *
   * @param string $filename
   *   The filename.
   */
  protected function getContentType(String $filename) : String {
    $parts = explode('__', $filename);
    return $parts[0];
  }

  /**
   * Get the template from the filename.
   *
   * @param string $filename
   *   The filename.
   */
  protected function getTemplate(String $filename) : String {
    $parts = explode('__', $filename);
    return $parts[1];
  }

  /**
   * Get the humanized template name.
   *
   * @param string $template
   *   The template name of the form <under_scored_name>.
   */
  protected function humanizedName(String $template) : String {
    return ucwords(str_replace('_', ' ', $template));
  }

}
