<?php

namespace Drupal\Tests\ys_layouts\Unit;

use Drupal\Core\Form\FormState;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_layouts\Form\OrphanedInlineBlockDeleteForm;
use Drupal\ys_layouts\Service\OrphanedInlineBlockCleanerInterface;

/**
 * Tests the orphaned inline block deletion confirm form.
 *
 * The form is the only UI path to a destructive sweep, so these tests pin the
 * two things that keep it safe: it takes no block IDs from the request (the
 * cleaner derives its own list), and it always lands the operator back on the
 * report so the result is visible.
 *
 * @coversDefaultClass \Drupal\ys_layouts\Form\OrphanedInlineBlockDeleteForm
 *
 * @group yalesites
 * @group ys_layouts
 */
class OrphanedInlineBlockDeleteFormTest extends UnitTestCase {

  /**
   * The orphaned inline block cleaner mock.
   *
   * @var \Drupal\ys_layouts\Service\OrphanedInlineBlockCleanerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $cleaner;

  /**
   * The messenger mock.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $messenger;

  /**
   * The form under test.
   *
   * @var \Drupal\ys_layouts\Form\OrphanedInlineBlockDeleteForm
   */
  protected $form;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->cleaner = $this->createMock(OrphanedInlineBlockCleanerInterface::class);
    $this->messenger = $this->createMock(MessengerInterface::class);

    $this->form = new OrphanedInlineBlockDeleteForm($this->cleaner);
    $this->form->setStringTranslation($this->getStringTranslationStub());
    $this->form->setMessenger($this->messenger);
  }

  /**
   * The form ID matches the machine name the route is built around.
   *
   * @covers ::getFormId
   */
  public function testGetFormId(): void {
    $this->assertSame('ys_layouts_orphaned_inline_block_delete', $this->form->getFormId());
  }

  /**
   * Cancelling returns to the report rather than a generic admin page.
   *
   * @covers ::getCancelUrl
   */
  public function testGetCancelUrlReturnsToTheReport(): void {
    $this->assertSame(
      'ys_layouts.orphaned_blocks_report',
      $this->form->getCancelUrl()->getRouteName()
    );
  }

  /**
   * Confirming deletes the orphans the cleaner derives for itself.
   *
   * The form deliberately passes no IDs: deleteOrphans() re-runs the analysis,
   * so a block that became referenced between the report and the confirmation
   * is left alone.
   *
   * @covers ::submitForm
   */
  public function testSubmitDeletesOrphansAndRedirectsToTheReport(): void {
    $this->cleaner->expects($this->once())
      ->method('deleteOrphans')
      ->willReturn(3);

    $form_state = $this->submit('Deleted 3 orphaned inline blocks.');

    $this->assertSame(
      'ys_layouts.orphaned_blocks_report',
      $form_state->getRedirect()->getRouteName()
    );
  }

  /**
   * Deleting nothing still reports back instead of failing silently.
   *
   * @covers ::submitForm
   */
  public function testSubmitReportsWhenThereWasNothingToDelete(): void {
    $this->cleaner->method('deleteOrphans')->willReturn(0);

    $form_state = $this->submit(
      'No orphaned inline blocks were found, so nothing was deleted.'
    );

    $this->assertSame(
      'ys_layouts.orphaned_blocks_report',
      $form_state->getRedirect()->getRouteName()
    );
  }

  /**
   * One deletion is reported in the singular.
   *
   * @covers ::submitForm
   */
  public function testSubmitUsesTheSingularForOneDeletion(): void {
    $this->cleaner->method('deleteOrphans')->willReturn(1);

    $this->submit('Deleted 1 orphaned inline block.');
  }

  /**
   * Submits the form, asserting the exact status message it sets.
   *
   * The message text is pinned rather than merely counted: asserting only that
   * addStatus() was called once passes even with the two messages swapped, so
   * the deleted-something and deleted-nothing branches would then be
   * indistinguishable.
   *
   * @param string $expected_message
   *   The status message the submission must set.
   *
   * @return \Drupal\Core\Form\FormState
   *   The form state the submission acted on.
   */
  protected function submit(string $expected_message): FormState {
    $this->messenger->expects($this->once())
      ->method('addStatus')
      ->with($this->callback(
        fn($message) => (string) $message === $expected_message
      ));

    $form = [];
    $form_state = new FormState();
    $this->form->submitForm($form, $form_state);

    return $form_state;
  }

}
