<?php

namespace Drupal\custom_webform_handlers\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\custom_webform_handlers\Reservation\ReservationException;
use Drupal\custom_webform_handlers\Reservation\ReservationManagerInterface;
use Drupal\custom_webform_handlers\Reservation\ReservationReconciliation;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Takes a historical submission's Slot out of service, without inventing a booking.
 *
 * A submission completed while reservations were off has no Hold and no
 * Reservation, so nothing stops the next visitor choosing the same Slot. This
 * form closes that Slot using the ordinary administrative block, which is the
 * only honest option available: fabricating a Hold token or a confirmed row
 * would put a reservation in the table that no visitor ever obtained, and would
 * silently decide the owner of a Slot several submissions may claim.
 *
 * The block therefore records no submission ID — the domain model does not
 * define that relationship for a block — and the Webform submission itself is
 * left exactly as it was.
 */
final class ReservationProtectHistoricalForm extends ConfirmFormBase {

  /**
   * The candidate being protected.
   */
  private ?array $candidate = NULL;

  /**
   * The Webform the candidate belongs to.
   */
  private string $webformId = '';

  public function __construct(
    private readonly ReservationReconciliation $reconciliation,
    private readonly ReservationManagerInterface $reservationManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('custom_webform_handlers.reservation_reconciliation'),
      $container->get('custom_webform_handlers.reservation_manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'custom_webform_handlers_reservation_protect_historical';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $webform = NULL, $sid = NULL) {
    $this->webformId = (string) $webform;
    // Re-derived from stored submission data and the live reservation
    // definition, never from what a stale listing displayed.
    $this->candidate = $this->reconciliation->candidate($this->webformId, (int) $sid);
    if (!$this->candidate) {
      throw new NotFoundHttpException();
    }

    $form = parent::buildForm($form, $form_state);

    $rows = [
      [$this->t('Submission ID'), $this->candidate['sid']],
      [$this->t('Local date'), $this->candidate['date']],
      [$this->t('Local time'), $this->candidate['time']],
      [$this->t('Timezone'), $this->candidate['timezone']],
      [$this->t('Resource'), $this->candidate['resource']],
      [
        $this->t('Slot currently'),
        $this->candidate['status'] === ReservationReconciliation::STATUS_PROTECTED
          ? $this->t('Already covered by a @state reservation', ['@state' => $this->candidate['state']])
          : $this->t('Not covered — bookable by anyone'),
      ],
    ];

    if ($this->candidate['conflict_sids']) {
      $rows[] = [
        $this->t('Conflicting submissions'),
        $this->t('Submissions @sids also selected this slot. Blocking it stops future visitors, but it does not decide which submission owns it — settle that separately.', [
          '@sids' => implode(', ', $this->candidate['conflict_sids']),
        ]),
      ];
    }

    $form['candidate'] = [
      '#type' => 'table',
      '#header' => [$this->t('Field'), $this->t('Value')],
      '#rows' => $rows,
      '#weight' => -10,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    if (!$this->candidate) {
      return $this->t('Protect this historical booking?');
    }
    return $this->t('Block @date at @time so no new visitor can book it?', [
      '@date' => $this->candidate['date'],
      '@time' => $this->candidate['time'],
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('This blocks the slot so it stops appearing as available. It does not create a reservation for submission @sid, does not link the block to that submission, and does not change the submission in any way. Release the block later from the reservation list if it was not wanted.', [
      '@sid' => $this->candidate['sid'] ?? '',
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Block this slot');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return Url::fromRoute('custom_webform_handlers.reservation_admin');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Re-checked at submit time: the listing, the confirm page, and this moment
    // are three different instants, and another administrator or visitor may
    // have taken the Slot in between.
    $candidate = $this->reconciliation->candidate($this->webformId, (int) ($this->candidate['sid'] ?? 0));
    if (!$candidate) {
      $this->messenger()->addError($this->t('That submission is no longer eligible for protection.'));
      $form_state->setRedirect('custom_webform_handlers.reservation_admin');
      return;
    }

    if ($candidate['status'] === ReservationReconciliation::STATUS_PROTECTED) {
      $this->messenger()->addWarning($this->t('That slot is already covered by a @state reservation, so nothing was changed.', [
        '@state' => $candidate['state'],
      ]));
      $form_state->setRedirect('custom_webform_handlers.reservation_admin');
      return;
    }

    try {
      // The same unique-key path a visitor's Hold competes for, so a race here
      // loses cleanly instead of overwriting anyone.
      $this->reservationManager->block($candidate['slot']);
    }
    catch (ReservationException $exception) {
      $this->messenger()->addError(
        $exception->getReservationCode() === ReservationException::SLOT_UNAVAILABLE
          ? $this->t('That slot was claimed while you were confirming, so it was not blocked.')
          : $this->t('That slot could not be blocked.')
      );
      $form_state->setRedirect('custom_webform_handlers.reservation_admin');
      return;
    }

    $this->messenger()->addStatus($this->t('Blocked @date at @time. Submission @sid was not modified.', [
      '@date' => $candidate['date'],
      '@time' => $candidate['time'],
      '@sid' => $candidate['sid'],
    ]));
    $form_state->setRedirect('custom_webform_handlers.reservation_admin');
  }

}
