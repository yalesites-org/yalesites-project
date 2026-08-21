<?php

namespace Drupal\ys_core\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\ys_core\DashboardAnnouncements;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Clears the current user's unread dashboard announcements.
 *
 * Embedded in the Announcements section of the editorial dashboard rather than
 * given its own page: it is a single action on the list the editor is already
 * looking at. It posts back to the dashboard route, so that route's
 * `yalesites manage settings` permission gates the submission too.
 *
 * This is the only way read state is ever cleared -- see markAllRead() for why
 * it is no longer cleared by viewing the page.
 *
 * @see \Drupal\ys_core\Controller\DashboardController::content()
 * @see \Drupal\ys_core\DashboardAnnouncements::markAllRead()
 */
class MarkAnnouncementsReadForm extends FormBase {

  /**
   * Constructs a MarkAnnouncementsReadForm object.
   *
   * @param \Drupal\ys_core\DashboardAnnouncements $announcements
   *   The dashboard announcements service, which owns the read state.
   */
  public function __construct(
    protected DashboardAnnouncements $announcements,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('ys_core.dashboard_announcements')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ys_core_mark_announcements_read';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['actions'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        // Names what it acts on, so it still makes sense to a screen reader
        // user reading the button out of its surrounding context.
        '#value' => $this->t('Mark all announcements as read'),
        // Gin promotes the first submit in a form's actions to its primary
        // style, which would make this utility control the loudest thing in
        // the section. Small keeps it clearly a button without competing with
        // the announcements themselves.
        '#attributes' => [
          'class' => ['ys-dashboard__announcements-mark-read', 'button--small'],
        ],
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->announcements->markAllRead($this->currentUser());

    // markAllRead() invalidates this user's badge cache tag, which the
    // dashboard render and the toolbar badge both depend on, so both catch up
    // on the redirect without a manual cache rebuild. The status message is
    // what tells a screen reader user the action succeeded -- the markers
    // simply disappearing is a visual-only signal.
    $this->messenger()->addStatus($this->t('All announcements have been marked as read.'));

    $form_state->setRedirect('ys_core.admin_dashboard');
  }

}
