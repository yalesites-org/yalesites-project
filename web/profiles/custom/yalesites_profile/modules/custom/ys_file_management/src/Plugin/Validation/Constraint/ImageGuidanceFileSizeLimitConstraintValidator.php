<?php

namespace Drupal\ys_file_management\Plugin\Validation\Constraint;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\ByteSizeMarkup;
use Drupal\file\Plugin\Validation\Constraint\FileSizeLimitConstraintValidator;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * Adds optimisation guidance to the oversize-image message.
 */
class ImageGuidanceFileSizeLimitConstraintValidator extends FileSizeLimitConstraintValidator {

  /**
   * Constructs the validator.
   */
  public function __construct(
    AccountInterface $currentUser,
    EntityTypeManagerInterface $entityTypeManager,
    protected ConfigFactoryInterface $configFactory,
  ) {
    parent::__construct($currentUser, $entityTypeManager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('current_user'),
      $container->get('entity_type.manager'),
      $container->get('config.factory'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    $file = $this->assertValueIsFile($value);
    if (!$constraint instanceof ImageGuidanceFileSizeLimitConstraint) {
      throw new UnexpectedTypeException($constraint, ImageGuidanceFileSizeLimitConstraint::class);
    }

    $isImage = str_starts_with((string) $file->getMimeType(), 'image/');
    if ($isImage && $constraint->fileLimit && $file->isNew() && $file->getSize() > $constraint->fileLimit) {
      $resolution = $this->configFactory
        ->get('field.field.media.image.field_media_image')
        ->get('settings.max_resolution');
      $params = [
        '%filesize' => ByteSizeMarkup::create($file->getSize()),
        '%maxsize' => ByteSizeMarkup::create($constraint->fileLimit),
      ];
      if ($resolution) {
        $params['%resolution'] = str_replace('x', ' x ', $resolution);
        $this->context->addViolation($constraint->imageMessage, $params);
      }
      else {
        $this->context->addViolation($constraint->imageMessageNoResolution, $params);
      }

      // Size already reported; let core handle only the disk quota check.
      $constraint = clone $constraint;
      $constraint->fileLimit = 0;
    }

    parent::validate($value, $constraint);
  }

}
