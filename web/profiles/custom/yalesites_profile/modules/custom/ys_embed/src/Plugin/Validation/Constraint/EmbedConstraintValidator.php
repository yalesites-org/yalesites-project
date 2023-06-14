<?php

namespace Drupal\ys_embed\Plugin\Validation\Constraint;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\ys_embed\Plugin\EmbedSourceManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Constraint validator to ensure embedded code matches a valid provider.
 */
class EmbedConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  /**
   * The embed source plugin manager service.
   *
   * @var \Drupal\ys_embed\Plugin\EmbedSourceManager
   */
  protected $embedManager;

  /**
   * Creates a EmbedConstraintValidator instance.
   *
   * @param \Drupal\ys_embed\Plugin\EmbedSourceManager $embed_manager
   *   The EmbedSource management service.
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

    // Check if the embed code matches a playlist.
    if ($this->isPlaylist($input)) {
      $this->context->addViolation($constraint->invalidAudioTrack);
      return;
    }
  }

  /**
   * Check if the embed code matches a supported video media provider.
   *
   * @param string $input
   *   The user provided embed code.
   *
   * @return bool
   *   TRUE if the embed code matches one of the remote video providers.
   */
  protected function isVideo(string $input): bool {
    $p1 = "^https:\/\/\S+.youtube.com\/\S+";
    $p2 = "^https:\/\/youtu.be\/\S+";
    $p3 = "^https:\/\/vimeo.com\/\S+";
    $pattern = "/{$p1}|{$p2}|{$p3}/";
    return (bool) preg_match($pattern, $input, $matches);
  }

  /**
   * Check if the embed code matches a track.
   *
   * @param string $input
   *  The user provided embed code.
   *
   * @return bool
   *   TRUE if the embed code matches a track.
   */
  protected function isPlaylist(string $input): bool {
    if (!$this->isSoundcloud($input)) {
      return FALSE;
    }

    $p1 = "https:\/\/\S+.soundcloud.com\/playlists\S+";
    $pattern = "/{$p1}/";
    return (bool) preg_match($pattern, $input, $matches);
  }

  /**
   * Determines if it is a soundcloud embed. Refactor later.
   *
   * @param string $input
   *   The user provided embed code.
   *
   * @return bool
   */
  protected function isSoundcloud(string $input): bool {
    $p1 = "https:\/\/\S+.soundcloud.com";
    $pattern = "/{$p1}/";
    return (bool) preg_match($pattern, $input, $matches);
  }
}
