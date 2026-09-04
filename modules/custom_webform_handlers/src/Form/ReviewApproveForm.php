<?php

namespace Drupal\custom_webform_handlers\Form;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Form\FormBase;
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
 * The one screen in this module where a human decides what a job costs.
 *
 * Everything else that names money reads it: the quote engine derives it from rates,
 * the checkout copies it into a locked snapshot, the tracker prints what it was told.
 * This is the exception, and it exists because the requests that reach it are the ones
 * the engine refused to price — so the authority behind the figure is a person, and the
 * audit trail has to say which person. It does: the manager writes the acting account's
 * uid and name into the same compare-and-set that records the price.
 *
 * Approving is an offer and nothing else. No Slot is held, no Reservation is written,
 * no capacity is consumed — specification item 2 — and that is not a promise this form
 * keeps by being careful. It keeps it by having no way to break it: the manager it calls
 * holds no planner, no Slot service and no reservation store, so there is no capacity
 * call reachable from this button. The customer converts the offer by paying, and the
 * paid flow is the only thing that ever schedules anything.
 *
 * What the operator supplies is an amount and a sentence. The currency is not theirs to
 * choose: it is read from the module's own configuration, shown so they can see what
 * money they are quoting in, and never accepted as input. A typed currency would let a
 * member of staff price a job in something Commerce cannot charge, and the failure would
 * surface at the payment page in front of the customer rather than here.
 */
final class ReviewApproveForm extends FormBase {

  /**
   * The largest figure this form will accept, in minor units.
   *
   * A typo guard rather than a policy: 999,999.99 is far above any real job this module
   * quotes, and far below the column's ceiling, so a slipped keypress is refused here
   * instead of being offered to a customer.
   */
  const AMOUNT_MAX_CENTS = 99999999;

  /**
   * The request being priced.
   */
  private ?ReviewRequest $request = NULL;

  /**
   * The currency this site quotes in, or NULL when it is not configured as one.
   */
  private ?string $currency = NULL;

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
    return 'custom_webform_handlers_review_approve';
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

    $this->currency = $this->configuredCurrency();

    $form['request'] = [
      '#type' => 'table',
      '#header' => [$this->t('Field'), $this->t('Value')],
      '#weight' => -10,
      '#rows' => ReviewRequestSummary::rows(
        $this->request,
        $this->dateFormatter,
        // Asked, not assumed. Somebody may price a job without being a person who may
        // audit what other jobs were charged — item 8's split, at the field.
        $this->currentUser()->hasPermission('view dispatch financial details'),
      ),
    ];

    if ($this->currency === NULL) {
      return $this->blocked($form, $this->t('This site has no valid quote currency configured, so no offer can be priced. Set a three-letter currency code in the quote settings first.'));
    }

    if (!$this->request->state->allows(ReviewState::Approved)) {
      return $this->blocked($form, $this->whyNot());
    }

    return $this->quoteFields($form);
  }

  /**
   * The page as it reads when there is nothing to approve.
   *
   * A reason and a way out, and no fields at all. Rendering the amount field and
   * refusing the submit would take an operator's figure and throw it away, and the
   * refusal would arrive after they had committed to a number.
   */
  private function blocked(array $form, string|\Stringable $why): array {
    $form['blocked'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $why,
    ];

    // Offered only where it is the actual next step. A request nobody has taken can be
    // taken; a decided one cannot be un-decided from here.
    if ($this->request !== NULL && $this->request->state === ReviewState::Submitted) {
      $form['begin'] = [
        '#type' => 'link',
        '#title' => $this->t('Take it under review first'),
        '#url' => Url::fromRoute('custom_webform_handlers.review_begin', [
          'review_id' => $this->request->reviewId,
        ]),
        '#attributes' => ['class' => ['button', 'button--primary']],
      ];
    }

    $form['cancel'] = $this->boardLink($this->t('Back to the board'));

    return $form;
  }

  /**
   * Why this request cannot be priced, in the operator's terms.
   */
  private function whyNot(): \Stringable {
    return match ($this->request->state) {
      ReviewState::Submitted => $this->t('Nobody has taken this request yet. A job is priced by the person reviewing it, so take it under review before quoting it.'),
      ReviewState::Approved => $this->t('This request already carries an offer and the customer has been given it. Withdraw it with Decline if the terms have changed; a second approval would move a price under somebody mid-payment.'),
      ReviewState::Scheduled => $this->t('This job is paid for and scheduled. Its price is settled and is not re-quoted here.'),
      default => $this->t('This request is closed, so there is nothing to offer. It is kept as the record of what was decided.'),
    };
  }

  /**
   * The amount, the sentence that goes with it, and the button.
   *
   * Two inputs, and the second is optional. The price is what the customer is being
   * asked to agree to and the note is why — a decline needs its reason, an approval
   * carries its reason in the figure, so this one is not forced.
   */
  private function quoteFields(array $form): array {
    $form['amount'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Total to charge, in @currency', ['@currency' => $this->currency]),
      '#required' => TRUE,
      '#size' => 14,
      '#maxlength' => 12,
      '#description' => $this->t('Digits and an optional full stop, as in 1250 or 1250.00. Everything the customer pays for this job, including anything a rate card would normally add.'),
    ];

    $form['staff_message'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Note to the customer'),
      '#rows' => 4,
      '#maxlength' => ReviewRequest::MESSAGE_MAX,
      '#description' => $this->t('Shown on the customer’s own quote page beside the price, as plain text. Write it for them: what the figure covers, and anything they need to do. Do not put another customer’s details, an internal note, or a link in it.'),
    ];

    $form['what_happens'] = [
      '#theme' => 'item_list',
      '#title' => $this->t('What approving does'),
      '#items' => [
        $this->t('The customer’s own tracking page starts offering this price, and a button to pay it.'),
        $this->t('No time is held on any vehicle. The schedule is untouched until the payment completes and the guard confirms a booking.'),
        $this->t('The offer carries a deadline, after which the customer is told it has lapsed rather than being allowed to pay a stale price. You are told the exact moment when you approve.'),
      ],
    ];

    $form['actions'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Approve and offer this price'),
        '#button_type' => 'primary',
      ],
      'cancel' => $this->boardLink($this->t('Cancel')),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   *
   * Validates the shape of the figure and nothing about the request. Whether the state
   * still permits approval is the manager's to answer, under its own compare-and-set,
   * at the moment of the write — asking it here as well would be a second answer that
   * can disagree with the first.
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    if ($this->request === NULL || $this->currency === NULL) {
      return;
    }

    $cents = self::cents((string) $form_state->getValue('amount'));
    if ($cents === NULL) {
      $form_state->setErrorByName('amount', $this->t('Give the total as digits with an optional full stop and up to two decimal places, for example 1250.00. No currency symbol, and no thousands separators.'));

      return;
    }

    if ($cents > self::AMOUNT_MAX_CENTS) {
      $form_state->setErrorByName('amount', $this->t('That is above the largest total this form accepts. Check for a slipped keypress; if the job really costs that much, it is not one to quote through this screen.'));

      return;
    }

    // Stored back as minor units so submitForm() acts on the integer this validated
    // rather than re-parsing the string and possibly reading it differently.
    $form_state->setValue('amount_cents', $cents);
  }

  /**
   * Reads a typed total as minor units, or NULL when it is not a total.
   *
   * Parsed as text and converted with integer arithmetic, never through a float: a
   * price is exactly what somebody typed, and 0.1 + 0.2 is a bad reason for a customer
   * to be charged a penny they did not agree to. Only the full stop is accepted as a
   * decimal separator, because a comma means one thing in one country and the opposite
   * in another, and this figure becomes a charge.
   */
  private static function cents(string $typed): ?int {
    $typed = trim($typed);
    if (preg_match('/^(\d{1,9})(?:\.(\d{1,2}))?$/', $typed, $parts) !== 1) {
      return NULL;
    }

    $cents = (int) $parts[1] * 100 + (int) str_pad($parts[2] ?? '', 2, '0');

    // Zero is a shape a customer cannot be offered: a job worth nothing is either a
    // mistake or a decision to do it for free, and the second is not an offer to pay.
    return $cents < 1 ? NULL : $cents;
  }

  /**
   * {@inheritdoc}
   *
   * Hands the figure to the manager and reports what it decided. The price and the
   * state move together in one compare-and-set there, so there is no instant at which
   * this request is approved and unpriced, and no path by which this form's success
   * message describes a write that did not happen.
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    if ($this->request === NULL || $this->currency === NULL) {
      return;
    }

    $cents = $form_state->getValue('amount_cents');
    if (!is_int($cents)) {
      // validateForm() sets this and only sets it for a figure it accepted, so this is
      // unreachable through the form. Refusing rather than parsing again keeps it that
      // way if somebody later adds a second submit handler.
      $this->messenger()->addError($this->t('That total could not be read, so nothing was offered.'));
      $form_state->setRebuild(TRUE);

      return;
    }

    $outcome = $this->reviews->approve(
      $this->request->reviewId,
      $cents,
      $this->currency,
      (string) $form_state->getValue('staff_message'),
    );

    if ($outcome->ok) {
      $this->messenger()->addStatus($this->offered($outcome->request));
      $form_state->setRedirect('custom_webform_handlers.dispatch_planner');

      return;
    }

    // Nothing was written: an approval refused at any point leaves the request exactly
    // as it was, unpriced and in whatever state it was already in.
    $this->messenger()->addError($this->t('Not approved: @why', [
      '@why' => ReviewReasonText::staff($outcome->reason),
    ]));

    if (ReviewReasonText::staffCanFixHere($outcome->reason)) {
      $form_state->setRebuild(TRUE);

      return;
    }

    $form_state->setRedirect('custom_webform_handlers.dispatch_planner');
  }

  /**
   * What was offered, and until when.
   *
   * Read from the request the manager returned rather than from what was typed, so the
   * operator is told what was actually stored. The deadline is stated because it is the
   * one consequence of approving that nobody can see on this screen otherwise.
   */
  private function offered(?ReviewRequest $approved): \Stringable {
    if ($approved === NULL || !$approved->hasQuote()) {
      return $this->t('Approved.');
    }

    $amount = number_format((int) $approved->quoteAmountCents / 100, 2, '.', ',') . ' ' . $approved->quoteCurrency;
    if ($approved->expiresAt === NULL) {
      return $this->t('Request @id is offered at @amount. No time is held on any vehicle until it is paid for.', [
        '@id' => $approved->reviewId,
        '@amount' => $amount,
      ]);
    }

    return $this->t('Request @id is offered at @amount, standing until @until. No time is held on any vehicle until it is paid for.', [
      '@id' => $approved->reviewId,
      '@amount' => $amount,
      '@until' => $this->dateFormatter->format($approved->expiresAt, 'short'),
    ]);
  }

  /**
   * The currency this site quotes in, or NULL when configuration does not name one.
   *
   * Fail-closed on purpose. A missing or malformed currency code means this form
   * offers no price at all, rather than falling back to a guess: a job quoted in a
   * currency the store cannot charge fails at the customer's payment page, which is
   * the worst place in the system to discover a configuration mistake.
   */
  private function configuredCurrency(): ?string {
    $configured = $this->config('custom_webform_handlers.settings')->get('quote.currency');
    $currency = strtoupper(trim((string) ($configured ?: 'USD')));

    return preg_match('/^[A-Z]{3}$/', $currency) === 1 ? $currency : NULL;
  }

  /**
   * The way back to the board this request was reached from.
   */
  private function boardLink(string|\Stringable $title): array {
    return [
      '#type' => 'link',
      '#title' => $title,
      '#url' => Url::fromRoute('custom_webform_handlers.dispatch_planner'),
      '#attributes' => ['class' => ['button']],
    ];
  }

}
