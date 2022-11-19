<?php

namespace Drupal\ys_embed\Plugin\Validation\Constraint;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\ys_embed\Plugin\EmbedSourceManager;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Constraint validator to ensure embedded code matches a valid provider.
 */
class EmbedConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  /**
   * Constructs a EmbedConstraintValidator object.
   *
   * @param \Drupal\ys_embed\Plugin\EmbedSourceManager $embed_manager
   *   Embed povider manager.
   */
  public function __construct(EmbedSourceManager $embed_manager) {
    $this->embedManager = $embed_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('plugin.manager.embed_source'),
    );
  }

  /**
   * {@inheritDoc}
   */
  public function validate($value, Constraint $constraint) {

    /** @var \Drupal\media\MediaInterface $media */
    $media = $value->getEntity();
    $source = $media->getSource();
    $input = $source->getSourceFieldValue($media);

    // Check if the embed code should use the video media type instead.
    if ($this->isVideo($input)) {
      $this->context->addViolation($constraint->isVideo);
      return;
    }

    // Check if the embed coude matches one of the allowed providers.
    if (!$this->embedManager->isValid($input)) {
      $this->context->addViolation($constraint->invalidPattern);
      return;
    }

  }

  /**
   * Check if the embed code matches a supported video media provider.
   *
   * @param string $source
   *   The user provided embed code.
   *
   * @return boolean
   *   TRUE if the embed code matches one of the remote video providers.
   */
  protected function isVideo(string $input): bool {
    $p1 = "^https:\/\/\S+.youtube.com\/\S+";
    $p2 = "^https:\/\/youtu.be\/\S+";
    $p3 = "^https:\/\/vimeo.com\/\S+";
    $pattern = "/{$p1}|{$p2}|{$p3}/";
    return (bool) preg_match($pattern, $input, $matches);
  }

}
