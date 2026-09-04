<?php

namespace Drupal\custom_webform_handlers\Form;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\custom_webform_handlers\Dispatch\DispatchOperations;
use Drupal\custom_webform_handlers\Dispatch\DispatchQueuedRequest;
use Drupal\custom_webform_handlers\Dispatch\DispatchReasonText;
use Drupal\custom_webform_handlers\Dispatch\LocalWallClock;
use Drupal\custom_webform_handlers\Dispatch\ResourceProfile;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Books a request that was held for staff approval, at a time staff choose.
 *
 * This is the only way a Manual Review request ever gets capacity. Until this form
 * is submitted the request holds nothing at all: it is a completed submission the
 * quote engine would not price, sitting in a queue, and the vehicle's day is
 * entirely unaware of it. Nothing promotes one in the background, no cron sweep
 * turns one into a booking, and the public path cannot: eligibility refuses it
 * there, which is why it is in the queue in the first place.
 *
 * What happens on submit is the same transaction a visitor's booking runs. The
 * planner takes a Hold under the Resource Calendar Guard, re-proves the plan under
 * the same locks, and only then writes a Reservation — so a job staff book is
 * proved exactly as often, and against exactly the same rows, as one a customer
 * books. Staff authority is the permission on the route; it is not a way past any
 * physical check, and a refusal here is the schedule's answer rather than a
 * suggestion.
 *
 * Staff type an arrival instead of picking from offered Slots because there are no
 * offers to pick from. Offers are gated by the public eligibility answer, so a
 * request in Manual Review yields none by definition, and a picker here would have
 * to re-decide eligibility with the refusals left out — a second copy of the rules,
 * disagreeing with the first the day either changes.
 */
final class DispatchManualReviewConfirmForm extends FormBase {

  /**
   * The waiting request, projected down to what this screen may show.
   */
  private ?DispatchQueuedRequest $request = NULL;

  /**
   * The Resource the request would be booked onto.
   */
  private ?ResourceProfile $profile = NULL;

  public function __construct(
    private readonly DispatchOperations $operations,
    private readonly TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('custom_webform_handlers.dispatch_operations'),
      $container->get('datetime.time'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'custom_webform_handlers_dispatch_manual_review_confirm';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $dispatch_submission_id = NULL) {
    // The Resource comes from the board's link or from configuration, never from a
    // field, so there is no input here that could book this job onto a vehicle this
    // installation does not operate. It is resolved first because it owns the zone
    // the arrival is read in.
    $requested = $this->getRequest()->query->get('resource', '');
    $this->profile = $this->operations->profile(is_string($requested) ? $requested : '');
    if ($this->profile === NULL) {
      throw new NotFoundHttpException();
    }

    $this->request = $this->operations->waitingRequest((int) $dispatch_submission_id);
    if ($this->request === NULL) {
      throw new NotFoundHttpException();
    }

    $form['request'] = [
      '#type' => 'table',
      '#header' => [$this->t('Field'), $this->t('Value')],
      '#weight' => -10,
      '#rows' => [
        [$this->t('Submission'), $this->request->submissionId],
        [$this->t('Webform'), $this->request->webformId],
        [$this->t('Arrived'), $this->localise($this->request->submittedTimestamp, 'Y-m-d H:i')],
        [
          $this->t('Resource'),
          $this->t('@resource · times below are read in @zone', [
            '@resource' => $this->profile->resourceId,
            '@zone' => $this->profile->timezone,
          ]),
        ],
      ],
    ];

    // Something already holds capacity for this submission. The planner would refuse
    // a second booking under its locks anyway, but there is no reason to let an
    // operator type a time first, and every reason to point them at the claim that
    // exists: two Reservations for one move send the vehicle twice.
    if ($this->request->isAlreadyClaimed()) {
      $form['claimed'] = [
        '#theme' => 'item_list',
        '#title' => $this->t('This request already has capacity'),
        '#items' => [
          $this->t('Claim @id holds time for submission @sid. Look at that one rather than making a second booking.', [
            '@id' => $this->request->liveClaimId,
            '@sid' => $this->request->submissionId,
          ]),
          $this->t('If it should not have that time, free it or move it first. Then this request can be booked.'),
        ],
      ];
      $form['cancel'] = $this->cancelLink();

      return $form;
    }

    return $this->arrivalFields($form);
  }

  /**
   * The arrival an operator is proposing, and nothing else.
   *
   * There is no duration field, no window field, no route override and no price:
   * the planner derives every one of those from the submission this request already
   * names. An operator asserts when the vehicle should arrive; whether it can is
   * answered by the same transaction a visitor's booking runs.
   */
  private function arrivalFields(array $form): array {
    $localToday = $this->localise($this->time->getRequestTime(), 'Y-m-d');

    $form['local_date'] = [
      '#type' => 'date',
      '#title' => $this->t('Pickup arrival date'),
      '#required' => TRUE,
      '#default_value' => $this->defaultDate($localToday),
      '#description' => $this->t('On @resource’s own calendar. Today there is @today.', [
        '@resource' => $this->profile->resourceId,
        '@today' => $localToday,
      ]),
    ];

    $form['local_time'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Pickup arrival time'),
      '#required' => TRUE,
      '#size' => 5,
      '#maxlength' => 5,
      '#description' => $this->t('24-hour local time, as HH:MM. When the vehicle should reach the pickup, not when it should leave.'),
    ];

    $form['actions'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Prove and book'),
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
    if ($this->profile === NULL || $this->request === NULL || $this->request->isAlreadyClaimed()) {
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

    // Only that the entry names a real local moment in this Resource's zone. Working
    // hours, breaks, the horizon, repositioning and occupancy are all the planner's
    // to answer, under its locks, against the day as it actually stands.
    if (LocalWallClock::instant($date, $time, $this->profile->timezoneObject()) === NULL) {
      $form_state->setErrorByName('local_time', $this->t('@time does not exist on @date in @zone.', [
        '@time' => $time,
        '@date' => $date,
        '@zone' => $this->profile->timezone,
      ]));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    if ($this->profile === NULL || $this->request === NULL) {
      return;
    }

    $date = trim((string) $form_state->getValue('local_date'));
    $result = $this->operations->confirmManualReview(
      $this->request->submissionId,
      $this->profile->resourceId,
      $date,
      trim((string) $form_state->getValue('local_time')),
    );

    if ($result->isConfirmed() && $result->slot !== NULL) {
      $this->messenger()->addStatus($this->t('Booked. Submission @sid now has Reservation @id on @resource, arriving @date at @time.', [
        '@sid' => $this->request->submissionId,
        '@id' => $result->reservationId,
        '@resource' => $this->profile->resourceId,
        '@date' => $result->slot->localDate(),
        '@time' => $result->slot->localTime(),
      ]));
      // Onto the day it landed on, so the operator sees it in place beside the work
      // it now sits between rather than taking the confirmation's word for it.
      $form_state->setRedirect('custom_webform_handlers.dispatch_planner_day', [], [
        'query' => [
          'resource' => $this->profile->resourceId,
          'date' => $result->slot->localDate(),
        ],
      ]);

      return;
    }

    // Nothing was booked and nothing was consumed: the request is still waiting,
    // exactly as it was.
    $this->messenger()->addError($this->t('Not booked: @why', [
      '@why' => DispatchReasonText::staff($result->reason),
    ]));
    if (!DispatchReasonText::anotherTimeMayWork($result->reason)) {
      $this->messenger()->addWarning($this->t('Another time will not resolve this one.'));
    }

    $form_state->setRebuild(TRUE);
  }

  /**
   * The way back to the board, on the Resource the request would have gone to.
   */
  private function cancelLink(): array {
    $query = $this->profile === NULL ? [] : ['resource' => $this->profile->resourceId];

    return [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => Url::fromRoute('custom_webform_handlers.dispatch_planner', [], ['query' => $query]),
      '#attributes' => ['class' => ['button']],
    ];
  }

  /**
   * The date the arrival field opens on, taken from the board's link when it gave one.
   */
  private function defaultDate(string $localToday): string {
    $requested = $this->getRequest()->query->get('date', '');
    $requested = is_string($requested) ? trim($requested) : '';

    return LocalWallClock::isDate($requested) ? $requested : $localToday;
  }

  /**
   * Renders one instant in the Resource's own zone, never the site's.
   */
  private function localise(int $timestamp, string $format): string {
    $zone = $this->profile === NULL ? new \DateTimeZone('UTC') : $this->profile->timezoneObject();

    return (new \DateTimeImmutable('@' . $timestamp))->setTimezone($zone)->format($format);
  }

}
