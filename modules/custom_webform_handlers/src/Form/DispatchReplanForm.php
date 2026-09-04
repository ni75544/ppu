<?php

namespace Drupal\custom_webform_handlers\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\custom_webform_handlers\Dispatch\DispatchClaimSummary;
use Drupal\custom_webform_handlers\Dispatch\DispatchOperations;
use Drupal\custom_webform_handlers\Dispatch\DispatchReasonText;
use Drupal\custom_webform_handlers\Dispatch\LocalWallClock;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Proposes a new pickup arrival for one confirmed Reservation.
 *
 * This is the authoritative Replan operation, and the board's drag handler is a
 * shortcut to it rather than a second way of doing it. Both end in exactly the same
 * call with exactly the same two facts — an identifier, and a local date and time —
 * because everything else about the move is derived rather than supplied: the
 * planner reloads the Reservation, rebuilds its Dispatch Request from the
 * submission that owns it, re-measures the route, re-derives the window, and
 * re-proves repositioning against the neighbours the day actually has.
 *
 * That is why there is no duration field here, no window field, no travel figure
 * and no override. A form that let an operator type a window would let them assert
 * a journey nobody can drive, and the Reservation would then record a plan that was
 * never true. The only thing an operator asserts is when they want the vehicle to
 * arrive; whether it can is answered, not accepted.
 *
 * A refused move changes nothing at all — the Reservation stays exactly where it
 * was — so the refusal is reported in the planner's own words and the form stays
 * open on the same job. A successful move retires the old row and writes a new one
 * in the same locked transaction, so the pair is the audit trail: what was proved
 * before, and what is proved now.
 */
final class DispatchReplanForm extends FormBase {

  /**
   * The Reservation being moved.
   */
  private ?DispatchClaimSummary $claim = NULL;

  public function __construct(
    private readonly DispatchOperations $operations,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('custom_webform_handlers.dispatch_operations'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'custom_webform_handlers_dispatch_replan';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $dispatch_reservation_id = NULL) {
    $this->claim = $this->operations->claim((int) $dispatch_reservation_id);

    // A missing row and a live Hold are the same 404, exactly as on the Reservation
    // detail page. An unfinished attempt is not rescheduled on a visitor's behalf —
    // it is dropped, on the release form — and answering differently here would let
    // this page report which identifiers exist as anonymous Holds.
    if ($this->claim === NULL || !$this->claim->isConfirmed() || $this->claim->pickupArrival === NULL) {
      throw new NotFoundHttpException();
    }

    $form['current'] = [
      '#type' => 'table',
      '#header' => [$this->t('Field'), $this->t('Value')],
      '#weight' => -10,
      '#rows' => [
        [$this->t('Reservation'), $this->claim->dispatchReservationId],
        [$this->t('Resource'), $this->claim->resourceId],
        [
          $this->t('Arrives now'),
          $this->t('@date at @time (@zone)', [
            '@date' => $this->localise($this->claim->pickupArrival, 'Y-m-d'),
            '@time' => $this->localise($this->claim->pickupArrival, 'H:i'),
            '@zone' => $this->claim->timezone,
          ]),
        ],
        [
          $this->t('Removed from the Resource now'),
          $this->t('@from until @until, repositioning included', [
            '@from' => $this->localise($this->claim->occupiedStart, 'H:i'),
            '@until' => $this->localise($this->claim->occupiedEnd, 'H:i'),
          ]),
        ],
      ],
    ];

    $form['local_date'] = [
      '#type' => 'date',
      '#title' => $this->t('New arrival date'),
      '#required' => TRUE,
      '#default_value' => $this->localise($this->claim->pickupArrival, 'Y-m-d'),
      '#description' => $this->t('Read on @resource’s own calendar, in @zone.', [
        '@resource' => $this->claim->resourceId,
        '@zone' => $this->claim->timezone,
      ]),
    ];

    // Plain text and server-validated, for the same reason as on the Blackout form:
    // browsers disagree about whether a time control submits seconds, and an
    // arrival carrying a second nobody typed is not an arrival anybody can verify.
    $form['local_time'] = [
      '#type' => 'textfield',
      '#title' => $this->t('New arrival time'),
      '#required' => TRUE,
      '#size' => 5,
      '#maxlength' => 5,
      '#default_value' => $this->localise($this->claim->pickupArrival, 'H:i'),
      '#description' => $this->t('24-hour local time, as HH:MM. This is when the vehicle should reach the pickup, not when it should leave.'),
    ];

    $form['actions'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Prove and move'),
        '#button_type' => 'primary',
      ],
      'cancel' => $this->cancelLink(),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    if ($this->claim === NULL) {
      return;
    }

    $date = trim((string) $form_state->getValue('local_date'));
    if (!LocalWallClock::isDate($date)) {
      $form_state->setErrorByName('local_date', $this->t('That is not a date on the calendar.'));

      return;
    }

    $time = LocalWallClock::normaliseTime((string) $form_state->getValue('local_time'));
    if ($time === NULL) {
      $form_state->setErrorByName('local_time', $this->t('Give a 24-hour local time as HH:MM, for example 09:30.'));

      return;
    }
    $form_state->setValue('local_time', $time);

    // Only the two things an operator can fix without leaving the page are checked
    // here: that the entry names a real local moment in this Resource's zone, and
    // that it is a different moment from the one already booked. Whether the vehicle
    // can be there is the planner's answer, proved under the calendar locks against
    // the neighbours the day really has — asking it twice, once weakly here, is how
    // two sets of rules start disagreeing.
    $instant = LocalWallClock::instant($date, $time, $this->zone());
    if ($instant === NULL) {
      $form_state->setErrorByName('local_time', $this->t('@time does not exist on @date in @zone.', [
        '@time' => $time,
        '@date' => $date,
        '@zone' => $this->claim->timezone,
      ]));

      return;
    }

    // Moving a Reservation onto the moment it already occupies would succeed — the
    // planner excludes the row being moved, so the old time is free to it — and
    // would retire a perfectly good row to write an identical one. That is audit
    // noise for no change, so it is refused as the mistake it almost certainly is.
    if ($instant === $this->claim->pickupArrival) {
      $form_state->setErrorByName('local_time', $this->t('It already arrives then. Give a different date or time, or cancel.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    if ($this->claim === NULL) {
      return;
    }

    $result = $this->operations->replan(
      $this->claim->dispatchReservationId,
      trim((string) $form_state->getValue('local_date')),
      trim((string) $form_state->getValue('local_time')),
    );

    if ($result->isMoved() && $result->slot !== NULL) {
      $this->messenger()->addStatus($this->t('Proved and moved. Reservation @new now arrives @date at @time; @old is retired and kept as the record of the earlier plan.', [
        '@new' => $result->reservationId,
        '@old' => $result->supersededReservationId,
        '@date' => $result->slot->localDate(),
        '@time' => $result->slot->localTime(),
      ]));
      // The new row is the one the board links to from here on, so the operator
      // lands on it rather than on the identifier they arrived with.
      $form_state->setRedirect('custom_webform_handlers.dispatch_planner_reservation', [
        'dispatch_reservation_id' => $result->reservationId,
      ]);

      return;
    }

    // Nothing moved. The Reservation is exactly where and when it was, and the form
    // stays open on the same job so another time can be tried without navigating
    // back — but only where another time is the kind of thing that would help.
    $this->messenger()->addError($this->t('Not moved: @why', [
      '@why' => DispatchReasonText::staff($result->reason),
    ]));
    if (!DispatchReasonText::anotherTimeMayWork($result->reason)) {
      $this->messenger()->addWarning($this->t('Another time will not resolve this one.'));
    }

    $form_state->setRebuild(TRUE);
  }

  /**
   * The way back to the Reservation as it stands.
   */
  private function cancelLink(): array {
    return [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => $this->claim === NULL
        ? Url::fromRoute('custom_webform_handlers.dispatch_planner')
        : Url::fromRoute('custom_webform_handlers.dispatch_planner_reservation', [
          'dispatch_reservation_id' => $this->claim->dispatchReservationId,
        ]),
      '#attributes' => ['class' => ['button']],
    ];
  }

  /**
   * The Resource's own zone, which is the only zone this form reads or writes in.
   *
   * An unusable stored zone falls back to UTC rather than throwing, because the
   * planner will refuse the move on its own terms a moment later and a refusal an
   * operator can read beats a white screen. It cannot cause a wrong write: the
   * instant this zone produces is only ever offered to the planner, which rebuilds
   * the Slot in the Resource's recorded zone before anything is claimed.
   */
  private function zone(): \DateTimeZone {
    try {
      return new \DateTimeZone($this->claim === NULL ? 'UTC' : $this->claim->timezone);
    }
    catch (\Exception) {
      return new \DateTimeZone('UTC');
    }
  }

  /**
   * Renders one stored instant as the operator reads it.
   */
  private function localise(int $timestamp, string $format): string {
    return (new \DateTimeImmutable('@' . $timestamp))->setTimezone($this->zone())->format($format);
  }

}
