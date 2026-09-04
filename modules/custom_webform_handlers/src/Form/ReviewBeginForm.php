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
 * Where a member of staff takes a queued job as theirs to price.
 *
 * The smallest of the four staff decisions, and the one that earns its own screen for
 * a reason that has nothing to do with what it collects — it collects nothing. Taking
 * a request under review is how two quoters stop working on the same job: the first
 * submit wins the compare-and-set, the second is refused with ReviewReason::Contended
 * or AlreadyOpen, and neither of them finds out by both sending a customer a price.
 * A link that did this on GET would be claimed by a prefetching browser on behalf of
 * whoever happened to hover over it.
 *
 * It decides nothing itself. The state machine is asked whether the move is legal, and
 * the manager is the only thing that may make it — which is specification item 1's
 * boundary as it looks from the form's side: this class holds a route permission and a
 * button, and everything else it knows it was told.
 */
final class ReviewBeginForm extends ConfirmFormBase {

  /**
   * The request being taken.
   *
   * Held on the object, as the module's other confirm forms hold their subject: one
   * form instance serves build and submit within a request, so nothing about the row
   * has to be echoed into the HTML and posted back to be trusted again.
   */
  private ?ReviewRequest $request = NULL;

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
   */
  public function getFormId() {
    return 'custom_webform_handlers_review_begin';
  }

  /**
   * {@inheritdoc}
   *
   * @param string|null $review_id
   *   The request, from the route. Bounded to digits by the route's own pattern.
   */
  public function buildForm(array $form, FormStateInterface $form_state, $review_id = NULL) {
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
        // Item 8's split, asked here rather than assumed: pricing authority is not a
        // licence to read what other jobs were charged.
        $this->currentUser()->hasPermission('view dispatch financial details'),
      ),
    ];

    // Nothing to take. The state machine would refuse the submit anyway, and a button
    // that exists only to be refused teaches an operator to distrust the screen.
    if (!$this->request->state->allows(ReviewState::UnderReview)) {
      unset($form['actions']['submit']);
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    if ($this->request === NULL) {
      return $this->t('Take this request under review?');
    }

    return $this->t('Take request @id under review?', ['@id' => $this->request->reviewId]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    if ($this->request === NULL) {
      return parent::getDescription();
    }

    if ($this->request->state === ReviewState::UnderReview) {
      return $this->t('@who already has this one. Taking it again would not tell them, so nothing is offered here — talk to them before quoting it.', [
        '@who' => $this->request->actorName === '' ? $this->t('Somebody') : $this->request->actorName,
      ]);
    }

    if (!$this->request->state->allows(ReviewState::UnderReview)) {
      return $this->t('This request is no longer open work, so there is nothing to take. It is shown because a decided request is kept as the record of what was decided.');
    }

    return $this->t('Your name goes on it as the person reviewing it, and it stops appearing to colleagues as unclaimed work. The customer is told nothing by this, no price is set, and no capacity is held — quoting is the next step and a separate decision.');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Take this request');
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
   * The state is re-decided by the manager inside its own compare-and-set. What was
   * read in buildForm() is only what the page was drawn from; it is never the basis of
   * the move, so a stale page cannot take a request somebody else already has.
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    if ($this->request === NULL) {
      return;
    }

    $outcome = $this->reviews->beginReview($this->request->reviewId);
    if ($outcome->ok) {
      $this->messenger()->addStatus($this->t('Request @id is yours to price.', [
        '@id' => $this->request->reviewId,
      ]));
      $form_state->setRedirect('custom_webform_handlers.review_approve', [
        'review_id' => $this->request->reviewId,
      ]);

      return;
    }

    $this->messenger()->addError($this->t('Not taken: @why', [
      '@why' => ReviewReasonText::staff($outcome->reason),
    ]));
    $form_state->setRedirect('custom_webform_handlers.dispatch_planner');
  }

}
