<?php

namespace Drupal\custom_webform_handlers\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\custom_webform_handlers\Reservation\ReservationException;
use Drupal\custom_webform_handlers\Reservation\ReservationManagerInterface;
use Drupal\custom_webform_handlers\Reservation\ReservationRecord;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Returns one held, confirmed, or blocked Slot to availability.
 *
 * This is the only path that can free a confirmed Reservation, which is why it
 * asks first: the visitor who owns it is not present, and the Slot becomes
 * bookable by anyone the moment it is released.
 */
final class ReservationReleaseForm extends ConfirmFormBase {

  /**
   * The record being released.
   */
  private ?ReservationRecord $record = NULL;

  public function __construct(
    private readonly ReservationManagerInterface $reservationManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('custom_webform_handlers.reservation_manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'custom_webform_handlers_reservation_release';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $reservation_id = NULL) {
    $this->record = $this->reservationManager->adminRecord((int) $reservation_id);
    if (!$this->record) {
      throw new NotFoundHttpException();
    }

    $form = parent::buildForm($form, $form_state);

    // Only non-secret fields: enough for an administrator to be sure they are
    // releasing the slot they meant to.
    $form['record'] = [
      '#type' => 'table',
      '#header' => [$this->t('Field'), $this->t('Value')],
      '#rows' => [
        [$this->t('Webform'), $this->record->webformId],
        [$this->t('Resource'), $this->record->resourceId],
        [$this->t('Local date'), $this->record->localDate()],
        [$this->t('Local time'), $this->record->localTime() . '–' . $this->record->localEndTime()],
        [$this->t('State'), $this->record->state],
        [
          $this->t('Submission ID'),
          $this->record->submissionId === NULL ? $this->t('None') : $this->record->submissionId,
        ],
      ],
      '#weight' => -10,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    if (!$this->record) {
      return $this->t('Release this reservation slot?');
    }

    return $this->t('Release the slot on @date at @time?', [
      '@date' => $this->record->localDate(),
      '@time' => $this->record->localTime(),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    if ($this->record && $this->record->submissionId !== NULL) {
      return $this->t('This slot is confirmed against submission @sid. Releasing it makes the time bookable by anyone else immediately, and does not cancel or alter the submission itself.', [
        '@sid' => $this->record->submissionId,
      ]);
    }

    return $this->t('Releasing this slot makes the time bookable by anyone else immediately. This cannot be undone.');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Release slot');
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
    try {
      $this->reservationManager->releaseByAdministrator($this->record->reservationId);
    }
    catch (ReservationException $exception) {
      $this->messenger()->addError(match ($exception->getReservationCode()) {
        ReservationException::RESERVATION_NOT_FOUND => $this->t('That reservation no longer exists.'),
        ReservationException::RESERVATION_NOT_ACTIVE => $this->t('That reservation is already released or expired, so its slot is free.'),
        default => $this->t('That reservation could not be released.'),
      });
      $form_state->setRedirect('custom_webform_handlers.reservation_admin');
      return;
    }

    $this->messenger()->addStatus($this->t('Released the slot on @date at @time.', [
      '@date' => $this->record->localDate(),
      '@time' => $this->record->localTime(),
    ]));
    $form_state->setRedirect('custom_webform_handlers.reservation_admin');
  }

}
