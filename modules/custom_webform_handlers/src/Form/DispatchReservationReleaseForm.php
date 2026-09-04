<?php

namespace Drupal\custom_webform_handlers\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\custom_webform_handlers\Dispatch\DispatchClaimSummary;
use Drupal\custom_webform_handlers\Dispatch\DispatchOperations;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Frees the capacity one claim holds, on staff authority.
 *
 * Two different things arrive here. One is a visitor's unfinished Hold, which an
 * operator may drop because somebody abandoned a form and the vehicle is standing
 * idle behind it. The other is a booking a customer completed. Both consume the
 * same capacity, so both are released the same way and under the same lock, but
 * they are not allowed to disclose the same amount, and the difference is made by
 * DispatchClaimSummary rather than by this form remembering to censor itself: a
 * Hold has no Webform, submission, arrival or Dispatch Window property to render.
 *
 * The state the operator was shown is carried into the submit and re-checked
 * against the row under its lock. That is the whole reason this is a confirmation
 * page and not a link: between rendering and confirming, the customer behind an
 * unfinished Hold may have finished. A page that offered to drop a Hold must not
 * cancel the booking that Hold became, so a stale confirmation frees nothing and
 * says so.
 *
 * Nothing is deleted. The row is marked terminal, keeps its history, and stops
 * counting against the Resource — so a day that was released still shows that it
 * once was not.
 */
final class DispatchReservationReleaseForm extends ConfirmFormBase {

  /**
   * The claim being freed.
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
    return 'custom_webform_handlers_dispatch_claim_release';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $dispatch_reservation_id = NULL) {
    // A released, expired, cancelled or superseded row answers NULL, and so does a
    // row that never existed. All of those are one 404: there is nothing left to
    // free, and answering differently would let this page report which identifiers
    // were once live.
    $this->claim = $this->operations->claim((int) $dispatch_reservation_id);
    if ($this->claim === NULL) {
      throw new NotFoundHttpException();
    }

    $form = parent::buildForm($form, $form_state);

    $rows = [
      [$this->t('Claim'), $this->claim->dispatchReservationId],
      [$this->t('Resource'), $this->claim->resourceId],
      [
        $this->t('State'),
        $this->claim->isConfirmed() ? $this->t('Confirmed booking') : $this->t('Unfinished hold'),
      ],
      [
        $this->t('Removed from the Resource'),
        $this->t('@from until @until (@zone), repositioning included', [
          '@from' => $this->localise($this->claim->occupiedStart, 'Y-m-d H:i'),
          '@until' => $this->localise($this->claim->occupiedEnd, 'H:i'),
          '@zone' => $this->claim->timezone,
        ]),
      ],
    ];

    // Present only on a Hold, and the reason it is shown: an operator deciding
    // whether to drop somebody's unfinished attempt should be able to see that it
    // was about to lapse on its own anyway.
    if ($this->claim->expiresAt !== NULL) {
      $rows[] = [$this->t('Lapses on its own'), $this->localise($this->claim->expiresAt, 'Y-m-d H:i')];
    }

    // Present only on a confirmed Reservation. A Hold carries no arrival, window,
    // Webform or submission at all, so there is nothing here to leak for one.
    if ($this->claim->pickupArrival !== NULL) {
      $rows[] = [$this->t('Pickup arrival'), $this->localise($this->claim->pickupArrival, 'Y-m-d H:i')];
    }
    if ($this->claim->windowStart !== NULL && $this->claim->windowEnd !== NULL) {
      $rows[] = [
        $this->t('Dispatch Window'),
        $this->t('@from – @until', [
          '@from' => $this->localise($this->claim->windowStart, 'H:i'),
          '@until' => $this->localise($this->claim->windowEnd, 'H:i'),
        ]),
      ];
    }
    if ($this->claim->webformId !== NULL) {
      $rows[] = [$this->t('Webform'), $this->claim->webformId];
    }
    if ($this->claim->submissionId !== NULL) {
      $rows[] = [$this->t('Submission'), $this->claim->submissionId];
    }

    $form['claim'] = [
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
    if ($this->claim === NULL) {
      return $this->t('Free this reserved capacity?');
    }

    if ($this->claim->isConfirmed()) {
      return $this->t('Cancel booking @id and free @from on @date?', [
        '@id' => $this->claim->dispatchReservationId,
        '@from' => $this->localise($this->claim->occupiedStart, 'H:i'),
        '@date' => $this->localise($this->claim->occupiedStart, 'Y-m-d'),
      ]);
    }

    return $this->t('Drop the unfinished hold on @from on @date?', [
      '@from' => $this->localise($this->claim->occupiedStart, 'H:i'),
      '@date' => $this->localise($this->claim->occupiedStart, 'Y-m-d'),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    if ($this->claim === NULL) {
      return parent::getDescription();
    }

    if ($this->claim->isConfirmed()) {
      return $this->t('The time becomes offerable again immediately, including to the public. This does not tell the customer anything and it does not refund anything — it frees the vehicle. If the job is still happening but at a different time, move it instead of cancelling it.');
    }

    return $this->t('Somebody may still be filling in the form this hold belongs to. Dropping it frees the time at once; if they submit afterwards they are told the time went, and nothing is double-booked either way.');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    if ($this->claim !== NULL && !$this->claim->isConfirmed()) {
      return $this->t('Drop this hold');
    }

    return $this->t('Free this capacity');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    // A confirmed Reservation has a detail page to go back to. A Hold deliberately
    // has none — the detail route answers 404 for one, so that page cannot be used
    // to enumerate anonymous attempts — so cancelling a Hold returns to the board
    // for its Resource instead.
    if ($this->claim !== NULL && $this->claim->isConfirmed()) {
      return Url::fromRoute('custom_webform_handlers.dispatch_planner_reservation', [
        'dispatch_reservation_id' => $this->claim->dispatchReservationId,
      ]);
    }

    $query = $this->claim === NULL ? [] : ['resource' => $this->claim->resourceId];

    return Url::fromRoute('custom_webform_handlers.dispatch_planner', [], ['query' => $query]);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    if ($this->claim === NULL) {
      return;
    }

    $resourceId = $this->claim->resourceId;
    // The state shown on the page is what the locked re-read is asked to match, so
    // a Hold that became a booking while this page sat open is left alone.
    $state = $this->operations->release($this->claim->dispatchReservationId, $this->claim->state);

    if ($state === NULL) {
      $this->messenger()->addWarning($this->t('Nothing was freed. That claim is already history, or it is no longer the @state one this page described. Reload the board to see what it holds now.', [
        '@state' => $this->claim->state,
      ]));
    }
    else {
      $this->messenger()->addStatus($this->t('Claim @id no longer holds capacity; it is recorded as @state and kept as history.', [
        '@id' => $this->claim->dispatchReservationId,
        '@state' => $state,
      ]));
    }

    $form_state->setRedirect('custom_webform_handlers.dispatch_planner', [], [
      'query' => ['resource' => $resourceId],
    ]);
  }

  /**
   * Renders one stored instant in the Resource's own zone.
   *
   * Every instant on this page is UTC in storage and meaningless to an operator in
   * that form. The zone travels with the claim because a dispatch claim is always
   * written in its Resource's zone, so this is a fact about the vehicle rather than
   * about whoever booked it. An unusable stored zone falls back to UTC for display
   * only — a visibly wrong time beats a white screen on a page whose whole purpose
   * is to free capacity.
   */
  private function localise(int $timestamp, string $format): string {
    $moment = new \DateTimeImmutable('@' . $timestamp);

    try {
      $zone = new \DateTimeZone($this->claim === NULL ? 'UTC' : $this->claim->timezone);
    }
    catch (\Exception) {
      $zone = new \DateTimeZone('UTC');
    }

    return $moment->setTimezone($zone)->format($format);
  }

}
