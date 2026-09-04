<?php

namespace Drupal\custom_webform_handlers\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\custom_webform_handlers\Dispatch\DispatchOperations;
use Drupal\custom_webform_handlers\Dispatch\ResourceProfile;
use Drupal\custom_webform_handlers\Dispatch\Status\ResourceStatusState;
use Drupal\custom_webform_handlers\Dispatch\Status\ResourceStatusStoreInterface;
use Drupal\custom_webform_handlers\Dispatch\Status\ResourceStatusText;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Records what a Resource is reported to be doing, on staff authority.
 *
 * This form appends an observation and does nothing else. It cannot withhold a minute
 * of capacity, cannot move a booking, and cannot shorten a Dispatch Window that has
 * been proved — reporting a job Completed at ten past two leaves its window exactly as
 * it was, because the next job was planned against that window and not against a hope
 * about this one. Time is withheld by a Blackout and work is moved by a Replan, both of
 * which are proved against live bookings inside the Resource Calendar Guard. A status
 * that quietly did either would be a second, weaker way to do the same thing, and the
 * two would drift apart on the first busy afternoon.
 *
 * The Resource is not a form field, for the reason it is not one on the Blackout form:
 * it comes from the board's link or the operating profile, so there is no input here
 * that could report a different vehicle's day, and no way to name a Resource this
 * installation does not operate.
 *
 * The audit fields are as much the point of the record as the state is. The account and
 * the name it had at that instant are both stored, because an account can be renamed or
 * removed and an audit trail has to keep meaning something afterwards; the source column
 * marks this as a human assertion made under a permission check, so a later driver
 * application or telematics feed is distinguishable in storage from the day it is added
 * and can never be mistaken for one.
 */
final class DispatchResourceStatusForm extends FormBase {

  /**
   * The Resource being reported on.
   */
  private ?ResourceProfile $profile = NULL;

  public function __construct(
    private readonly ResourceStatusStoreInterface $statuses,
    private readonly DispatchOperations $operations,
    private readonly AccountInterface $currentUser,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('custom_webform_handlers.dispatch_resource_status_store'),
      $container->get('custom_webform_handlers.dispatch_operations'),
      $container->get('current_user'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'custom_webform_handlers_dispatch_resource_status';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    // Resolved before anything else, and a 404 rather than a fallback when it names no
    // Resource this installation operates: an operator must not be shown a form that
    // files a report against a different vehicle.
    $requested = $this->getRequest()->query->get('resource', '');
    $this->profile = $this->operations->profile(is_string($requested) ? $requested : '');
    if ($this->profile === NULL) {
      throw new NotFoundHttpException();
    }

    $current = $this->statuses->current($this->profile->resourceId);

    $form['resource'] = [
      '#type' => 'item',
      '#title' => $this->t('Resource'),
      '#markup' => $current === NULL
        ? $this->t('@resource · nothing has been reported yet, which is not the same as available.', [
          '@resource' => $this->profile->resourceId,
        ])
        : $this->t('@resource · last reported @state by @who.', [
          '@resource' => $this->profile->resourceId,
          '@state' => ResourceStatusText::label($current->state),
          '@who' => $current->actorName === '' ? $this->t('an account since removed') : $current->actorName,
        ]),
    ];

    $form['state'] = [
      '#type' => 'select',
      '#title' => $this->t('What is being reported'),
      '#options' => ResourceStatusText::options(),
      '#default_value' => $current === NULL
        ? ResourceStatusState::Available->value
        : $current->state->value,
      '#required' => TRUE,
    ];

    // Spelled out beside the choice rather than left to be discovered. An operator
    // about to report a vehicle unavailable should learn here that doing so withholds
    // no time, not from a double booking an hour later.
    $consequences = [];
    foreach (ResourceStatusState::cases() as $state) {
      $consequences[] = $this->t('<strong>@label</strong> — @consequence', [
        '@label' => ResourceStatusText::label($state),
        '@consequence' => ResourceStatusText::consequence($state),
      ]);
    }

    $form['consequences'] = [
      '#theme' => 'item_list',
      '#title' => $this->t('What each report does, and what it does not do'),
      '#items' => $consequences,
    ];

    $form['dispatch_reservation_id'] = [
      '#type' => 'number',
      '#title' => $this->t('Booking this is about'),
      '#min' => 1,
      '#step' => 1,
      '#description' => $this->t('The Reservation identifier from the board. Required by, and recorded only for, the four states that are about one job — a report of “at pickup” must say which pickup. Available and Unavailable are about the vehicle itself, so anything typed here is ignored for those two.'),
      '#states' => [
        'visible' => [
          ':input[name="state"]' => $this->workingStateConditions(),
        ],
      ],
    ];

    $form['note'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Note for other staff'),
      '#maxlength' => 255,
      '#description' => $this->t('Optional, and read by staff on the board. Keep customer detail out of it: this is a note about a vehicle, and it is kept as history.'),
    ];

    $form['audit'] = [
      '#type' => 'item',
      '#title' => $this->t('Recorded as'),
      '#markup' => $this->t('@who, at the moment you submit this. The report is kept; a correction is a newer report, never an edit of this one.', [
        '@who' => $this->currentUser->getAccountName(),
      ]),
    ];

    $form['actions'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Record this report'),
        '#button_type' => 'primary',
      ],
      'cancel' => [
        '#type' => 'link',
        '#title' => $this->t('Cancel'),
        '#url' => $this->boardUrl(),
        '#attributes' => ['class' => ['button']],
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   *
   * A report about one job has to name a job that exists, is confirmed, and belongs to
   * this Resource. All three failures answer with the same sentence, because answering
   * them differently would turn this field into a way of asking which identifiers are
   * live and which are somebody's unfinished attempt.
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    if ($this->profile === NULL) {
      return;
    }

    $state = $this->submittedState($form_state);
    if ($state === NULL) {
      $form_state->setErrorByName('state', $this->t('That is not a state this release records.'));

      return;
    }

    if (!$state->needsReservation()) {
      return;
    }

    $reservationId = (int) $form_state->getValue('dispatch_reservation_id');
    if ($reservationId < 1) {
      $form_state->setErrorByName('dispatch_reservation_id', $this->t('“@state” is about one job, so it needs that booking’s identifier from the board.', [
        '@state' => ResourceStatusText::label($state),
      ]));

      return;
    }

    $claim = $this->operations->claim($reservationId);
    if ($claim === NULL || !$claim->isConfirmed() || $claim->resourceId !== $this->profile->resourceId) {
      $form_state->setErrorByName('dispatch_reservation_id', $this->t('@id is not a confirmed booking on @resource.', [
        '@id' => $reservationId,
        '@resource' => $this->profile->resourceId,
      ]));
    }
  }

  /**
   * {@inheritdoc}
   *
   * Appends the report and says plainly what it did not do. The message is worded that
   * way on purpose: the one mistake this form invites is believing that reporting a
   * vehicle unavailable stops the calendar offering it.
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    if ($this->profile === NULL) {
      return;
    }

    $state = $this->submittedState($form_state);
    if ($state === NULL) {
      return;
    }

    // Read only for the states that are about one job, so a value left behind in a
    // field that #states merely hid cannot be smuggled onto a report about the vehicle
    // itself. Validation has already proved this one is a confirmed booking here.
    $reservationId = $state->needsReservation()
      ? (int) $form_state->getValue('dispatch_reservation_id')
      : NULL;

    $record = $this->statuses->record(
      $this->profile->resourceId,
      $state,
      $reservationId,
      trim((string) $form_state->getValue('note')),
      (int) $this->currentUser->id(),
      $this->currentUser->getAccountName(),
    );

    if ($record === NULL) {
      $this->messenger()->addWarning($this->t('That report was not recorded, so the board still shows the one before it. Nothing else changed either: no booking moved, and no time was withheld or returned.'));
    }
    else {
      $this->messenger()->addStatus($this->t('Recorded: @state. The calendar is untouched — to stop time being offered, add a closure; to move a booking, use Replan, which is proved against the work already on this Resource.', [
        '@state' => ResourceStatusText::label($state),
      ]));
    }

    $form_state->setRedirect('custom_webform_handlers.dispatch_planner', [], [
      'query' => ['resource' => $this->profile->resourceId],
    ]);
  }

  /**
   * The submitted state, or NULL when the value is not one this release knows.
   *
   * Resolved through the enum in both validate and submit rather than trusted once and
   * remembered, because the submitted value is input on each pass.
   */
  private function submittedState(FormStateInterface $form_state): ?ResourceStatusState {
    return ResourceStatusState::tryFrom(trim((string) $form_state->getValue('state')));
  }

  /**
   * The #states conditions matching every state that names one job.
   *
   * Derived from the enum rather than listed here, so a state added later cannot be
   * left out of the condition and quietly hide the field it is going to need.
   *
   * @return array<int, array<string, string>>
   *   Value conditions, OR-ed together by the Form API.
   */
  private function workingStateConditions(): array {
    $conditions = [];
    foreach (ResourceStatusState::cases() as $state) {
      if ($state->needsReservation()) {
        $conditions[] = ['value' => $state->value];
      }
    }

    return $conditions;
  }

  /**
   * The board for this Resource, which is where a report comes from and returns to.
   */
  private function boardUrl(): Url {
    $query = $this->profile === NULL ? [] : ['resource' => $this->profile->resourceId];

    return Url::fromRoute('custom_webform_handlers.dispatch_planner', [], ['query' => $query]);
  }

}
