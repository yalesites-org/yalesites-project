<?php

namespace Drupal\ys_templated_content\Support;

/**
 * Helper class for template filenames.
 */
class ContentTypedFilename {
  public readonly String $originalFilename;
  public readonly String $contentType;
  public readonly String $template;
  public readonly String $humanizedTemplateName;

  /**
   *
   */
  public function __construct(String $filename) {
    $this->originalFilename = $filename;

    $nameWithoutExtension = $this->getNameWithoutExtension($filename);
    $this->contentType = $this->getContentType($nameWithoutExtension);
    $this->template = $this->getTemplate($nameWithoutExtension);
    $this->humanizedTemplateName = $this->humanizedName($this->template);
  }

  /**
   *
   */
  protected function getNameWithoutExtension(String $filename) : String {
    $suffix = str_replace('.yml', '', $filename);
    return $suffix;
  }

  /**
   *
   */
  protected function getContentType(String $filename) : String {
    $parts = explode('__', $filename);
    return $parts[0];
  }

  /**
   *
   */
  protected function getTemplate(String $filename) : String {
    $parts = explode('__', $filename);
    return $parts[1];
  }

  /**
   *
   */
  protected function humanizedName(String $template) : String {
    return ucwords(str_replace('_', ' ', $template));
  }

}
