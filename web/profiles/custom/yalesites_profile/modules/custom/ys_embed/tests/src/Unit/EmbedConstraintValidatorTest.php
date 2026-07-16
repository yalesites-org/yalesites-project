<?php

namespace Drupal\Tests\ys_embed\Unit;

use Drupal\Core\Field\FieldItemInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\media\MediaInterface;
use Drupal\media\MediaSourceInterface;
use Drupal\ys_embed\Plugin\EmbedSourceManager;
use Drupal\ys_embed\Plugin\Validation\Constraint\EmbedConstraint;
use Drupal\ys_embed\Plugin\Validation\Constraint\EmbedConstraintValidator;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * @coversDefaultClass \Drupal\ys_embed\Plugin\Validation\Constraint\EmbedConstraintValidator
 *
 * @group yalesites
 * @group ys_embed
 */
class EmbedConstraintValidatorTest extends UnitTestCase {

  /**
   * The mocked EmbedSource plugin manager.
   *
   * @var \Drupal\ys_embed\Plugin\EmbedSourceManager|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $embedManager;

  /**
   * The mocked validation execution context.
   *
   * @var \Symfony\Component\Validator\Context\ExecutionContextInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $context;

  /**
   * The validator under test.
   *
   * @var \Drupal\ys_embed\Plugin\Validation\Constraint\EmbedConstraintValidator
   */
  protected $validator;

  /**
   * The constraint whose violation messages are asserted against.
   *
   * @var \Drupal\ys_embed\Plugin\Validation\Constraint\EmbedConstraint
   */
  protected $constraint;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->embedManager = $this->createMock(EmbedSourceManager::class);
    $this->context = $this->createMock(ExecutionContextInterface::class);
    $this->constraint = new EmbedConstraint();
    $this->validator = new EmbedConstraintValidator($this->embedManager);
    $this->validator->initialize($this->context);
  }

  /**
   * Builds a mocked $value for validate() to read via getEntity().
   *
   * The getEntity()->getSource()->getSourceFieldValue() chain is mocked to
   * return $input, mirroring what EmbedConstraintValidator::validate() reads.
   *
   * @param string $input
   *   The embed code/URL that the media's source field should yield.
   *
   * @return \Drupal\Core\Field\FieldItemInterface|\PHPUnit\Framework\MockObject\MockObject
   *   The mocked field item to pass as $value.
   */
  protected function mockValueForInput(string $input) {
    $source = $this->createMock(MediaSourceInterface::class);
    $source->method('getSourceFieldValue')->willReturn($input);

    $media = $this->createMock(MediaInterface::class);
    $media->method('getSource')->willReturn($source);

    $value = $this->createMock(FieldItemInterface::class);
    $value->method('getEntity')->willReturn($media);

    return $value;
  }

  /**
   * @covers ::validate
   * @covers ::isVideo
   */
  public function testValidateAddsIsVideoViolationForYoutubeUrl(): void {
    $this->context->expects($this->once())
      ->method('addViolation')
      ->with($this->constraint->isVideo);
    $this->embedManager->expects($this->never())->method('isValid');

    $this->validator->validate(
      $this->mockValueForInput('https://www.youtube.com/watch?v=abc123'),
      $this->constraint
    );
  }

  /**
   * @covers ::validate
   * @covers ::isVideo
   */
  public function testValidateAddsIsVideoViolationForVimeoUrl(): void {
    $this->context->expects($this->once())
      ->method('addViolation')
      ->with($this->constraint->isVideo);

    $this->validator->validate(
      $this->mockValueForInput('https://vimeo.com/123456'),
      $this->constraint
    );
  }

  /**
   * @covers ::validate
   */
  public function testValidateAddsInvalidPatternViolationWhenManagerRejects(): void {
    $this->embedManager->method('isValid')->willReturn(FALSE);
    $this->context->expects($this->once())
      ->method('addViolation')
      ->with($this->constraint->invalidPattern);

    $this->validator->validate(
      $this->mockValueForInput('not a recognized embed code'),
      $this->constraint
    );
  }

  /**
   * @covers ::validate
   * @covers ::isTrack
   * @covers ::isSoundcloud
   */
  public function testValidateAddsInvalidAudioTrackViolationForSoundcloudPlaylist(): void {
    $this->embedManager->method('isValid')->willReturn(TRUE);
    $this->context->expects($this->once())
      ->method('addViolation')
      ->with($this->constraint->invalidAudioTrack);

    $this->validator->validate(
      $this->mockValueForInput('https://api.soundcloud.com/playlists/123456'),
      $this->constraint
    );
  }

  /**
   * @covers ::validate
   * @covers ::isTrack
   */
  public function testValidateAddsNoViolationForValidSoundcloudTrack(): void {
    $this->embedManager->method('isValid')->willReturn(TRUE);
    $this->context->expects($this->never())->method('addViolation');

    $this->validator->validate(
      $this->mockValueForInput('https://api.soundcloud.com/tracks/123456'),
      $this->constraint
    );
  }

  /**
   * @covers ::validate
   */
  public function testValidateAddsNoViolationForValidNonSoundcloudEmbed(): void {
    $this->embedManager->method('isValid')->willReturn(TRUE);
    $this->context->expects($this->never())->method('addViolation');

    $this->validator->validate(
      $this->mockValueForInput('<blockquote class="twitter-tweet"></blockquote>'),
      $this->constraint
    );
  }

  /**
   * Invokes a protected method on the validator via reflection.
   *
   * @param string $method
   *   The protected method name.
   * @param string $input
   *   The embed code/URL argument.
   *
   * @return bool
   *   The method's return value.
   */
  protected function invokeProtected(string $method, string $input): bool {
    $reflection = new \ReflectionMethod($this->validator, $method);
    $reflection->setAccessible(TRUE);
    return $reflection->invoke($this->validator, $input);
  }

  /**
   * @covers ::isVideo
   */
  public function testIsVideoAcceptsYoutubeYoutubeDotBeAndVimeo(): void {
    $this->assertTrue($this->invokeProtected('isVideo', 'https://www.youtube.com/watch?v=abc123'));
    $this->assertTrue($this->invokeProtected('isVideo', 'https://youtu.be/abc123'));
    $this->assertTrue($this->invokeProtected('isVideo', 'https://vimeo.com/123456'));
  }

  /**
   * @covers ::isVideo
   */
  public function testIsVideoRejectsUnrelatedUrl(): void {
    $this->assertFalse($this->invokeProtected('isVideo', 'https://example.com/some-page'));
  }

  /**
   * CHARACTERIZATION: isVideo() false-positives on a non-video URL.
   *
   * It flags a URL as a video embed even when "youtube.com" merely appears
   * elsewhere in the string, because $p1 uses an unescaped "." (matches any
   * character, not a literal dot) and a leading "\S+" with no host
   * anchoring. A legitimate non-video embed whose URL happens to contain
   * that substring is wrongly rejected as "should use the Video component
   * instead".
   *
   * Paired with testIsVideoShouldOnlyMatchTheActualHost() -- delete once the
   * GAP is fixed.
   *
   * @covers ::isVideo
   */
  public function testIsVideoFalsePositivesOnUnrelatedHostContainingYoutubeSubstring(): void {
    $this->assertTrue($this->invokeProtected('isVideo', 'https://evil.com/redirect?to=youtube.com/x'));
  }

  /**
   * GAP: isVideo() should anchor its match to the actual request host.
   *
   * It should anchor $p1 to the actual host (e.g. via parse_url()) and
   * escape the literal "." in "youtube.com", instead of a bare
   * "\S+.youtube.com" that matches the substring anywhere in the string --
   * see ~/Documents/Claude/not_dave/module-tests-20260710/ys_embed.md.
   */
  public function testIsVideoShouldOnlyMatchTheActualHost(): void {
    $this->markTestSkipped('GAP: EmbedConstraintValidator::isVideo() wrongly classifies a non-YouTube URL as a video embed because its regex is unanchored and its "." is unescaped -- see ~/Documents/Claude/not_dave/module-tests-20260710/ys_embed.md');
  }

  /**
   * @covers ::isSoundcloud
   */
  public function testIsSoundcloudAcceptsSoundcloudSubdomain(): void {
    $this->assertTrue($this->invokeProtected('isSoundcloud', 'https://api.soundcloud.com/tracks/1'));
    $this->assertFalse($this->invokeProtected('isSoundcloud', 'https://example.com/tracks/1'));
  }

  /**
   * @covers ::isTrack
   */
  public function testIsTrackTrueForNonSoundcloudInput(): void {
    // isTrack() only restricts SoundCloud URLs; anything else passes.
    $this->assertTrue($this->invokeProtected('isTrack', 'https://example.com/anything'));
  }

  /**
   * @covers ::isTrack
   */
  public function testIsTrackFalseForSoundcloudPlaylist(): void {
    $this->assertFalse($this->invokeProtected('isTrack', 'https://api.soundcloud.com/playlists/1'));
  }

  /**
   * @covers ::isTrack
   */
  public function testIsTrackTrueForSoundcloudTrack(): void {
    $this->assertTrue($this->invokeProtected('isTrack', 'https://api.soundcloud.com/tracks/1'));
  }

}
