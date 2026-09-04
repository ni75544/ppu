<?php

namespace Drupal\custom_webform_handlers\Form;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\custom_webform_handlers\Review\ReviewManagerInterface;
use Drupal\custom_webform_handlers\Review\ReviewReasonText;
use Drupal\custom_webform_handlers\Review\ReviewRequest;
use Drupal\custom_webform_handlers\Review\ReviewRequestSummary;
use Drupal\custom_webform_handlers\Review\ReviewState;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Takes a request off the table: declined because we will not, or cancelled because
 * it is not wanted.
 *
 * One class serving two routes, because the two decisions differ in what they mean and
 * not at all in what they collect. Keeping them as one form keeps the audit path, the
 * message bounds, and the wording of a refusal identical for both; keeping them as two
 * routes keeps the fact itself distinct, which matters because "we will not do this
 * work" and "never mind" are different things to have said to a customer and are read
 * differently by whoever looks at the row afterwards.
 *
 * Which of the two it is arrives as a route default, and that is load-bearing. A route
 * default cannot be set by a query string, a posted field, or a forged form — it is
 * written in this module's own routing file — so there is no request anybody can make
 * that turns a cancellation into a decline, or either into some third transition. The
 * form maps it through a closed two-entry match and answers anything else with the
 * site's ordinary 404.
 *
 * Both decisions are legal from three states, including Approved: an offer may be
 * withdrawn right up until it is paid for. Neither is legal from Scheduled, and this
 * form cannot make it so — a paid booking is given back through the Reservation's own
 * release operation, in the capacity system, where the vehicle's day is.
 */
final class ReviewWithdrawForm extends ConfirmFormBase {

  /**
   * The request being withdrawn.
   */
  private ?ReviewRequest $request = NULL;

  /**
   * Which withdrawal this is, resolved from the route and from nowhere else.
   */
  private ?ReviewState $decision = NULL;

  public function __construct(
    private readonly ReviewManagerInterface $reviews,
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get(ReviewManagerInterface::class),
      $container->get('date.formatter'),
    );
  }

  /**
   * {@inheritdoc}
   *
   * Two form ids, one class. The id is what Form API keys a build cache and a token on,
   * and what an alter hook names, so a site that wants to alter the decline screen
   * without touching the cancel screen must be able to tell them apart.
   */
  public function getFormId() {
    return $this->decision === ReviewState::Cancelled
      ? 'custom_webform_handlers_review_cancel'
      : 'custom_webform_handlers_review_decline';
  }

  /**
   * {@inheritdoc}
   *
   * @param string|null $review_id
   *   The request, from the route. Bounded to digits by the route's own pattern.
   * @param string|null $review_decision
   *   Which withdrawal this route means. A route default, never an input.
   */
  public function buildForm(array $form, FormStateInterface $form_state, $review_id = NULL, $review_decision = NULL) {
    // Resolved before the row is read, because it decides every word on the page and
    // which manager method the button reaches. An unmapped value means a route this
    // module did not write, and the site's ordinary 404 is the honest answer to it.
    $this->decision = self::decisionState($review_decision);
    if ($this->decision === NULL) {
      throw new NotFoundHttpException();
    }

    $this->request = $this->reviews->load((int) $review_id);
    if ($this->request === NULL) {
      throw new NotFoundHttpException();
    }

    $form = parent::buildForm($form, $form_state);

    $form['request'] = [
      '#type' => 'table',
      '#header' => [$this->t('Field'), $this->t('Value')],
      '#weight' => -10,
      '#rows' => ReviewRequestSummary::rows(
        $this->request,
        $this->dateFormatter,
        $this->currentUser()->hasPermission('view dispatch financial details'),
      ),
    ];

    // Nothing left to withdraw. The row stays readable as the audit record it is, and
    // the button goes: a confirm that cannot commit teaches an operator to distrust
    // every other confirm on the site.
    if (!$this->request->state->allows($this->decision)) {
      unset($form['actions']['submit']);

      return $form;
    }

    return $this->reasonField($form);
  }

  /**
   * The sentence the customer will read, which is the whole substance of this screen.
   *
   * Required, unlike the note on an approval. An approval carries its own explanation
   * in the figure; a withdrawal carries none, and a customer told only that their
   * request is closed has been given a fact and no reason. It is also the last thing
   * this module ever says to them about the job, so it is worth making somebody type it.
   */
  private function reasonField(array $form): array {
    $form['staff_message'] = [
      '#type' => 'textarea',
      '#title' => $this->decision === ReviewState::Cancelled
        ? $this->t('Why it is being cancelled')
        : $this->t('Why we are declining'),
      '#required' => TRUE,
      '#rows' => 4,
      '#weight' => -5,
      '#maxlength' => ReviewRequest::MESSAGE_MAX,
      '#description' => $this->t('Shown on the customer’s own quote page as plain text, and shown to colleagues on the board. Write it for the customer. Do not put another customer’s details, an internal note, or a link in it.'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    if ($this->request === NULL) {
      return $this->decision === ReviewState::Cancelled
        ? $this->t('Cancel this request?')
        : $this->t('Decline this request?');
    }

    return $this->decision === ReviewState::Cancelled
      ? $this->t('Cancel request @id?', ['@id' => $this->request->reviewId])
      : $this->t('Decline request @id?', ['@id' => $this->request->reviewId]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    if ($this->request === NULL || $this->decision === NULL) {
      return parent::getDescription();
    }

    if (!$this->request->state->allows($this->decision)) {
      return $this->closedDescription();
    }

    // Withdrawing a live offer is the case worth spelling out: somebody may have the
    // payment page open, and after this it stops working for them.
    if ($this->request->state === ReviewState::Approved) {
      return $this->decision === ReviewState::Cancelled
        ? $this->t('There is a live offer on this request. Cancelling withdraws it, so the price stops being payable — including for a customer who has the payment page open right now. Nothing was ever held in the schedule for it, so nothing is released.')
        : $this->t('There is a live offer on this request. Declining withdraws it, so the price stops being payable — including for a customer who has the payment page open right now. Nothing was ever held in the schedule for it, so nothing is released.');
    }

    return $this->decision === ReviewState::Cancelled
      ? $this->t('The request is closed as no longer wanted, your reason is shown to the customer, and it leaves the review queue. This cannot be undone: a request that should be looked at again is a new submission.')
      : $this->t('The request is closed as work we will not do, your reason is shown to the customer, and it leaves the review queue. This cannot be undone: a request that should be looked at again is a new submission.');
  }

  /**
   * Why there is nothing to withdraw, in the operator's terms.
   *
   * Scheduled is separated from the terminal states because it is the only one where the
   * operator wants something real and this is the wrong screen for it: the job is paid
   * for, a booking is holding time on a vehicle, and the thing that gives the vehicle
   * its day back is the Reservation's own release. The rest are already closed, and
   * saying which way they were closed is the whole of the answer.
   */
  private function closedDescription(): \Stringable {
    if ($this->request->state === ReviewState::Scheduled) {
      return $this->t('This job is paid for and a booking is holding time on a vehicle for it, so it is not withdrawn from here. Release that booking in the schedule instead — that is what gives the vehicle its day back, and it is what the customer is owed a decision about.');
    }

    return match ($this->request->state) {
      ReviewState::Declined => $this->t('This request was already declined, so there is nothing to withdraw. It is kept as the record of what was decided.'),
      ReviewState::Cancelled => $this->t('This request was already cancelled, so there is nothing to withdraw. It is kept as the record of what was decided.'),
      default => $this->t('This request lapsed with nobody deciding it in time, so there is nothing to withdraw. It is kept as the record of what happened; a customer who still wants the job sends a new one.'),
    };
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->decision === ReviewState::Cancelled
      ? $this->t('Cancel this request')
      : $this->t('Decline this request');
  }

  /**
   * {@inheritdoc}
   *
   * Overridden because the default is the bare word 'Cancel', which on the cancel route
   * would sit beside 'Cancel this request' — one of them abandoning the screen and the
   * other closing a customer's job. Naming the way out for what it actually does keeps
   * the two apart on both routes.
   */
  public function getCancelText() {
    return $this->t('Leave it as it is');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return Url::fromRoute('custom_webform_handlers.dispatch_planner');
  }

  /**
   * {@inheritdoc}
   *
   * Which of the two moves this is was fixed by the route before the page was drawn, so
   * there is nothing here to be talked into a different transition. The state is decided
   * again by the manager inside its own compare-and-set: what buildForm() read is only
   * what the screen was drawn from, so a page left open while a colleague acted cannot
   * close a request they have already closed differently.
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    if ($this->request === NULL || $this->decision === NULL) {
      return;
    }

    $message = (string) $form_state->getValue('staff_message');
    $cancelling = $this->decision === ReviewState::Cancelled;

    $outcome = $cancelling
      ? $this->reviews->cancel($this->request->reviewId, $message)
      : $this->reviews->decline($this->request->reviewId, $message);

    if ($outcome->ok) {
      $this->messenger()->addStatus($cancelling
        ? $this->t('Request @id is cancelled, and the customer’s own page now carries your reason.', ['@id' => $this->request->reviewId])
        : $this->t('Request @id is declined, and the customer’s own page now carries your reason.', ['@id' => $this->request->reviewId]));
      $form_state->setRedirect('custom_webform_handlers.dispatch_planner');

      return;
    }

    // Nothing was written. The request is in whatever state it was in, which for a
    // refused withdrawal means the customer has been told nothing at all.
    $this->messenger()->addError($cancelling
      ? $this->t('Not cancelled: @why', ['@why' => ReviewReasonText::staff($outcome->reason)])
      : $this->t('Not declined: @why', ['@why' => ReviewReasonText::staff($outcome->reason)]));

    if (ReviewReasonText::staffCanFixHere($outcome->reason)) {
      $form_state->setRebuild(TRUE);

      return;
    }

    $form_state->setRedirect('custom_webform_handlers.dispatch_planner');
  }

  /**
   * Maps the route's own decision default to the transition it names.
   *
   * Two entries and NULL for everything else, taken as mixed rather than as a string so
   * that a value which is not one of the two — of any type, from any source — lands on
   * the NULL arm and becomes a 404 rather than a type error. The only place the two
   * accepted values are written is this module's routing file, so the NULL arm is not
   * reachable by making a request; it is reachable by somebody adding a third route and
   * forgetting to add its meaning here, and a 404 is the right answer to that too.
   *
   * Returning a ReviewState rather than the string means everything after this compares
   * transitions. Nothing downstream re-reads the route value, so there is one place
   * where the mapping can be checked and no second interpretation of it.
   */
  private static function decisionState(mixed $routeDefault): ?ReviewState {
    return match ($routeDefault) {
      'decline' => ReviewState::Declined,
      'cancel' => ReviewState::Cancelled,
      default => NULL,
    };
  }

}
