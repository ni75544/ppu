<?php

namespace Drupal\custom_webform_handlers\Review;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\custom_webform_handlers\Event\DomainEventEmitter;
use Drupal\custom_webform_handlers\Event\ReviewRequestTransitionedEvent;
use Drupal\webform\WebformSubmissionInterface;
use Psr\Log\LoggerInterface;

/**
 * The one implementation of the Review Request lifecycle.
 *
 * Everything specification item 1 asks for is structural here rather than
 * remembered. This is the only caller of ReviewState::allows(), the only holder of
 * ReviewRequestStore, and the only issuer of a tracking credential. A form calls a
 * named decision and is told yes or no; ECA is given no service reference to this
 * at all, so there is no reaction rule that can move a request.
 *
 * Item 2 is true by omission, and deliberately so: this class has no planner, no
 * Slot service, and no reservation store among its dependencies, so no method here
 * can spend capacity however it is called. approve() writes a price and nothing
 * else. ReviewState::Scheduled is reachable only through recordScheduled(), which
 * must be handed the Dispatch Reservation identifier the Resource Calendar Guard
 * already committed — the manager records that fact, it does not re-decide it.
 *
 * The raw tracking code exists in exactly one scope: the return value of the open()
 * call that created the row. It is not stored, not logged, not returned by any other
 * method, and not carried on ReviewRequest — so no staff screen, queue read, or cron
 * run can print it, because none of them has it. What is stored is an HMAC keyed
 * outside the database, and trackByCode() matches against that.
 */
final class ReviewManager implements ReviewManagerInterface {

  use StringTranslationTrait;

  /**
   * How long an approved offer stands before it lapses, in seconds.
   *
   * A constant rather than a setting, because it is a promise the tracker prints
   * and a deadline the sweep enforces, and those two must never disagree because
   * somebody edited a form while an offer was outstanding. Forty-eight hours.
   */
  const OFFER_TTL = 172800;

  /**
   * The elements a customer-facing service summary may be composed from.
   *
   * A named subset of AddressDistanceHandler::QUOTE_INPUT_FIELDS — pricing facts
   * about the work, which by that constant's own contract carry no contact or
   * payment information. Addresses are absent, and so is anything routed: item 9
   * forbids publishing the pickup, the delivery, and the distance between them,
   * and the way to obey that is to have no line here that could print one.
   */
  const SUMMARY_FIELDS = [
    'service_type',
    'delivery_mode',
    'courier_urgency',
    'shipment_size',
    'shipment_home_size',
    'move_size_residential',
    'item_quantity',
    'timing_of_move',
    'scope_of_move',
  ];

  /**
   * Bounds on the composed summary, so no element value can flood a page.
   */
  const SUMMARY_VALUE_MAX = 80;
  const SUMMARY_MAX = 600;

  /**
   * The customer-facing routes. Named here because the manager builds the URL.
   */
  const TRACK_ROUTE = 'custom_webform_handlers.review_track';
  const PAY_ROUTE = 'custom_webform_handlers.review_pay';

  public function __construct(
    private readonly ReviewRequestStore $store,
    private readonly ReviewCredential $credential,
    private readonly TimeInterface $time,
    private readonly AccountProxyInterface $currentUser,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerInterface $logger,
    private readonly DomainEventEmitter $events,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function open(string $webformId, int $submissionId): ?ReviewIssue {
    $now = $this->time->getRequestTime();

    try {
      $request = ReviewRequest::open($webformId, $submissionId, $now, NULL);
    }
    catch (\InvalidArgumentException $e) {
      // A handler asking to review something that is not a saved submission. Logged
      // because it is a wiring fault, not a customer's doing.
      $this->logger->error('A Review Request was not opened: @message', ['@message' => $e->getMessage()]);

      return NULL;
    }

    // The code is minted before the insert and hashed on the way in, so the raw form
    // never reaches a query, a log, or a second variable that outlives this method.
    $rawCode = $this->credential->issue();
    $hash = $this->credential->hash($rawCode);
    if ($hash === '') {
      $this->logger->error('A Review Request for submission @sid was not opened: no tracking credential could be issued.', [
        '@sid' => $submissionId,
      ]);

      return NULL;
    }

    $opened = $this->store->open($request, $hash);
    if ($opened === NULL) {
      return NULL;
    }

    [$stored, $isNew] = $opened;

    if (!$isNew) {
      // The submission already had a review. The code just minted names nothing and
      // is discarded unused; the customer's own code was shown when it was raised.
      return new ReviewIssue($stored, NULL);
    }

    $this->logger->info('Review Request @id was opened for submission @sid.', [
      '@id' => $stored->reviewId,
      '@sid' => $stored->submissionId,
    ]);

    return new ReviewIssue($stored, $rawCode);
  }

  /**
   * {@inheritdoc}
   */
  public function beginReview(int $reviewId): ReviewOutcome {
    $request = $this->store->load($reviewId);
    if ($request === NULL) {
      return ReviewOutcome::refused(ReviewReason::NotFound);
    }

    // "Somebody already took this" is a different thing to tell a quoter than "that
    // is not a legal move", so it is a different refusal even though both end here.
    if ($request->state === ReviewState::UnderReview) {
      return ReviewOutcome::refused(ReviewReason::AlreadyOpen, $request);
    }

    return $this->decide($request, ReviewState::UnderReview, $this->actor());
  }

  /**
   * {@inheritdoc}
   */
  public function approve(int $reviewId, int $amountCents, string $currency, string $staffMessage): ReviewOutcome {
    $currency = ReviewRequest::normalizeCurrency($currency);
    if ($amountCents < 1 || $currency === NULL) {
      // Refused before anything is read, so an unpriced approval never becomes a
      // state change that a later step has to undo.
      return ReviewOutcome::refused(ReviewReason::QuoteRequired, $this->store->load($reviewId));
    }

    $request = $this->store->load($reviewId);
    if ($request === NULL) {
      return ReviewOutcome::refused(ReviewReason::NotFound);
    }

    // The price and the state move in the same compare-and-set, so there is no
    // instant at which a request is approved and unpriced. The offer's deadline is
    // written here too: an offer that stands forever is capacity nobody can plan
    // around, and the sweep needs a figure to compare against.
    return $this->decide($request, ReviewState::Approved, $this->actor() + [
      'quote_amount_cents' => $amountCents,
      'quote_currency' => $currency,
      'staff_message' => $staffMessage,
      'expires_at' => $this->time->getRequestTime() + self::OFFER_TTL,
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function decline(int $reviewId, string $staffMessage): ReviewOutcome {
    $request = $this->store->load($reviewId);
    if ($request === NULL) {
      return ReviewOutcome::refused(ReviewReason::NotFound);
    }

    return $this->decide($request, ReviewState::Declined, $this->actor() + [
      'staff_message' => $staffMessage,
      // A declined offer has no deadline left to run: there is nothing to lapse.
      'expires_at' => NULL,
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function cancel(int $reviewId, string $staffMessage): ReviewOutcome {
    $request = $this->store->load($reviewId);
    if ($request === NULL) {
      return ReviewOutcome::refused(ReviewReason::NotFound);
    }

    return $this->decide($request, ReviewState::Cancelled, $this->actor() + [
      'staff_message' => $staffMessage,
      'expires_at' => NULL,
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function recordScheduled(int $reviewId, int $orderId, int $dispatchReservationId): ReviewOutcome {
    if ($dispatchReservationId < 1) {
      // The one refusal that keeps this from becoming a way to schedule a job with
      // no capacity behind it. Checked first, before the row is even read.
      return ReviewOutcome::refused(ReviewReason::ReservationRequired, $this->store->load($reviewId));
    }
    if ($orderId < 1) {
      return ReviewOutcome::refused(ReviewReason::InvalidRequest, $this->store->load($reviewId));
    }

    $request = $this->store->load($reviewId);
    if ($request === NULL) {
      return ReviewOutcome::refused(ReviewReason::NotFound);
    }

    // The actor columns are deliberately not touched. The last human decision on this
    // request was the approval, and that is who the audit trail should keep naming;
    // what happened since is a payment, and the order identifier records it.
    return $this->decide($request, ReviewState::Scheduled, [
      'order_id' => $orderId,
      'dispatch_reservation_id' => $dispatchReservationId,
      'expires_at' => NULL,
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function expireLapsed(int $limit = 50): int {
    $now = $this->time->getRequestTime();
    $lapsed = 0;

    foreach ($this->store->lapsed($now, $limit) as $request) {
      // Re-checked against the state machine even though the query filtered on it,
      // because the row may have been decided between the SELECT and this loop. The
      // compare-and-set inside move() is what makes losing that race harmless.
      if (!$request->state->allows(ReviewState::Expired)) {
        continue;
      }
      // No actor. Nobody expired this; a deadline did, and the audit trail should not
      // imply that whichever account happened to run cron made a decision.
      if ($this->store->move($request->reviewId, $request->state, ReviewState::Expired)) {
        $lapsed++;
      }
    }

    if ($lapsed > 0) {
      $this->logger->info('@count Review Request(s) lapsed.', ['@count' => $lapsed]);
    }

    return $lapsed;
  }

  /**
   * {@inheritdoc}
   */
  public function load(int $reviewId): ?ReviewRequest {
    return $this->store->load($reviewId);
  }

  /**
   * {@inheritdoc}
   */
  public function loadBySubmission(int $submissionId): ?ReviewRequest {
    return $this->store->loadBySubmission($submissionId);
  }

  /**
   * {@inheritdoc}
   */
  public function openQueue(int $limit = 100): array {
    return $this->store->openQueue($limit);
  }

  /**
   * {@inheritdoc}
   */
  public function trackByCode(string $rawCode): ?ReviewStatus {
    // Every refusal on this path returns the identical NULL, and each is reached
    // without a distinguishing side effect: a code the wrong shape is refused by the
    // credential's own normalisation, a well-shaped code that matches nothing is
    // refused by an indexed equality read that costs the same as a hit, and neither
    // is logged. That is item 4's non-disclosure requirement — an enumerator learns
    // nothing about whether some other code exists, because there is nothing
    // different to observe.
    $hash = $this->credential->hash($rawCode);
    if ($hash === '') {
      return NULL;
    }

    $request = $this->store->loadByCredential($hash);

    return $request === NULL ? NULL : $this->publish($request, $rawCode);
  }

  /**
   * {@inheritdoc}
   */
  public function payableByCode(string $rawCode): ?ReviewRequest {
    // The same normalisation and the same indexed equality read as trackByCode(), so
    // this door discloses no more by timing or by behaviour than that one does.
    $hash = $this->credential->hash($rawCode);
    if ($hash === '') {
      return NULL;
    }

    $request = $this->store->loadByCredential($hash);
    if ($request === NULL) {
      return NULL;
    }

    // Approved and priced, judged by the request itself, and still inside its
    // deadline. A lapsed offer is refused here even though its stored state is still
    // approved: publish() already declines to offer a payment action for one, and this
    // is the same judgement made again at the moment it would be acted on.
    if (!$request->isPayable() || $request->hasLapsed($this->time->getRequestTime())) {
      return NULL;
    }

    return $request;
  }

  /**
   * Applies the state machine and records the move, or explains the refusal.
   *
   * The single point every transition passes through, so ReviewState::allows() has
   * exactly one caller in this module and legality cannot be decided anywhere else.
   *
   * The reload after a failed move is what turns a bare FALSE into something a form
   * can say out loud. FALSE means the row was not in the state this caller read, so
   * either somebody else decided it first — Contended, and the request that comes
   * back shows what they decided — or the row is gone and the failure was the
   * storage layer's.
   */
  private function decide(ReviewRequest $request, ReviewState $to, array $extra = []): ReviewOutcome {
    if (!$request->state->allows($to)) {
      return ReviewOutcome::refused(ReviewReason::IllegalTransition, $request);
    }

    if (!$this->store->move($request->reviewId, $request->state, $to, $extra)) {
      $current = $this->store->load($request->reviewId);

      return ReviewOutcome::refused(
        $current === NULL ? ReviewReason::StorageFailed : ReviewReason::Contended,
        $current,
      );
    }

    $moved = $this->store->load($request->reviewId);
    if ($moved === NULL) {
      return ReviewOutcome::refused(ReviewReason::StorageFailed);
    }

    $this->logger->info('Review Request @id moved from @from to @to.', [
      '@id' => $moved->reviewId,
      '@from' => $request->state->value,
      '@to' => $to->value,
    ]);

    // Announced after the compare-and-set has committed, so a reaction reacts to a
    // transition that has happened, not one still in flight. Four scalars, no private
    // data: a listener that needs the submission's answers loads them from the id. The
    // emitter contains any listener failure, so a broken reaction cannot turn a
    // recorded decision into an error the deciding form sees.
    $this->events->emit(
      new ReviewRequestTransitionedEvent(
        $moved->reviewId,
        $request->state->value,
        $to->value,
        $moved->submissionId,
      ),
      ReviewRequestTransitionedEvent::EVENT_NAME,
    );

    return ReviewOutcome::moved($moved);
  }

  /**
   * Who is deciding, recorded on the row rather than only in the log.
   *
   * The name is stored alongside the uid because an account can be renamed or
   * deleted and the audit trail must still say who approved a job. Both columns are
   * bounded by the store.
   */
  private function actor(): array {
    return [
      'actor_uid' => (int) $this->currentUser->id(),
      'actor_name' => (string) $this->currentUser->getAccountName(),
    ];
  }

  /**
   * Builds the one projection a tracking code buys.
   *
   * Composed by naming each publishable fact, never by filtering a ReviewRequest —
   * so a column added later publishes nothing until somebody writes a line here.
   *
   * A lapsed offer is published as lapsed even though its stored state is still
   * approved, because the sweep runs on cron and a customer must not be shown a
   * payment action for an offer that will not be honoured. The state string still
   * reports what is stored; only the payment action and the wording change.
   */
  private function publish(ReviewRequest $request, string $rawCode): ReviewStatus {
    $now = $this->time->getRequestTime();
    $lapsed = $request->state === ReviewState::Approved && $request->hasLapsed($now);
    $payable = $request->isPayable() && !$lapsed;

    if ($lapsed) {
      $headline = $this->t('This quote has expired');
      $detail = $this->t('The offer we sent is no longer valid. Please submit a new request and we will price it again.');
    }
    else {
      [$headline, $detail] = $this->voice($request->state);
    }

    // The price is shown only while it is an offer or a booking. A declined or
    // cancelled request has no price, because there is nothing being offered.
    $showQuote = $payable || $request->state === ReviewState::Scheduled;

    return new ReviewStatus(
      $request->state->value,
      (string) $headline,
      (string) $detail,
      $this->serviceSummary($request),
      $request->staffMessage,
      $showQuote ? $this->money($request->quoteAmountCents, $request->quoteCurrency) : NULL,
      $payable ? $this->paymentUrl($rawCode) : NULL,
      $request->changed,
    );
  }

  /**
   * What each state says to the customer whose request it is.
   *
   * Two sentences per state: what stage the work is at, and what happens next or
   * what to do. Neither names another customer, an address, a driver, a schedule, or
   * an internal identifier — item 9 is obeyed by there being no such sentence to
   * write. Declined and cancelled are worded differently on purpose: "we cannot do
   * this" and "this was called off" are different facts about the same job.
   *
   * @return array{0: \Drupal\Core\StringTranslation\TranslatableMarkup, 1: \Drupal\Core\StringTranslation\TranslatableMarkup}
   */
  private function voice(ReviewState $state): array {
    return match ($state) {
      ReviewState::Submitted => [
        $this->t('We have your request'),
        $this->t('Your request needs a person to price it. We will send you a quote as soon as it has been looked at.'),
      ],
      ReviewState::UnderReview => [
        $this->t('Someone is pricing your request'),
        $this->t('A member of our team is working out the cost now. Check back here for the quote.'),
      ],
      ReviewState::Approved => [
        $this->t('Your quote is ready'),
        $this->t('Pay to confirm your booking. We will hold nothing in the schedule until payment is complete.'),
      ],
      ReviewState::Scheduled => [
        $this->t('Your booking is confirmed'),
        $this->t('We have your payment and your job is booked. We will be in touch with the arrangements.'),
      ],
      ReviewState::Declined => [
        $this->t('We cannot take this job'),
        $this->t('We are not able to carry out this request. Nothing has been charged.'),
      ],
      ReviewState::Cancelled => [
        $this->t('This request was cancelled'),
        $this->t('This request is no longer going ahead. Nothing has been charged.'),
      ],
      ReviewState::Expired => [
        $this->t('This request has expired'),
        $this->t('Too much time has passed for us to stand behind the price. Please submit a new request.'),
      ],
    };
  }

  /**
   * Formats an amount for display, or NULL when there is no price to show.
   *
   * Formatted here rather than in a template, so no page has to decide how money
   * reads and no page receives an amount it could round differently.
   */
  private function money(?int $amountCents, ?string $currency): ?string {
    if ($amountCents === NULL || $amountCents < 1 || $currency === NULL || $currency === '') {
      return NULL;
    }

    return number_format($amountCents / 100, 2, '.', ',') . ' ' . $currency;
  }

  /**
   * The absolute URL of this request's own payment action.
   *
   * Built from the code the customer already holds, not from the order identifier,
   * which is exactly why ReviewStatus can offer a payment action without publishing
   * an internal number. Generated with its metadata collected rather than leaked, so
   * building a URL inside a service cannot poison the render cache of the page that
   * asked for it.
   */
  private function paymentUrl(string $rawCode): ?string {
    try {
      return Url::fromRoute(self::PAY_ROUTE, ['code' => $rawCode], ['absolute' => TRUE])
        ->toString(TRUE)
        ->getGeneratedUrl();
    }
    catch (\Exception $e) {
      // A route that is not there yet, or a code the route's own pattern refuses.
      // The status is still publishable; it simply offers nothing to pay.
      $this->logger->warning('A Review Request payment URL could not be built: @message', [
        '@message' => $e->getMessage(),
      ]);

      return NULL;
    }
  }

  /**
   * Composes a staff-safe description of the work from the allow-listed elements.
   *
   * Never a copy of the customer's answers: only SUMMARY_FIELDS is read, each value
   * is bounded, and anything that is not a scalar or a flat list of scalars is
   * skipped rather than serialised — a composite element must not be able to smuggle
   * an address into this string through a nested key.
   *
   * The label comes from the Webform's own element title, so the summary reads in the
   * site's own words. An empty string is a perfectly good answer: it means there was
   * nothing publishable to say, which is better than saying something else.
   */
  private function serviceSummary(ReviewRequest $request): string {
    try {
      $submission = $this->entityTypeManager
        ->getStorage('webform_submission')
        ->load($request->submissionId);
    }
    catch (\Exception $e) {
      $this->logger->warning('A Review Request service summary could not be composed: @message', [
        '@message' => $e->getMessage(),
      ]);

      return '';
    }

    if (!$submission instanceof WebformSubmissionInterface) {
      return '';
    }

    $webform = $submission->getWebform();
    $parts = [];
    foreach (self::SUMMARY_FIELDS as $key) {
      $value = $this->summaryValue($submission->getElementData($key));
      if ($value === '') {
        continue;
      }

      $element = $webform !== NULL ? $webform->getElement($key) : NULL;
      $title = is_array($element) ? trim((string) ($element['#title'] ?? '')) : '';
      $label = $title !== '' ? $title : ucfirst(str_replace('_', ' ', $key));

      $parts[] = $label . ': ' . $value;
    }

    return mb_substr(implode('; ', $parts), 0, self::SUMMARY_MAX);
  }

  /**
   * Reduces one element value to a bounded plain string, or '' when it cannot be.
   */
  private function summaryValue(mixed $value): string {
    if (is_array($value)) {
      $value = implode(', ', array_filter($value, static fn (mixed $item): bool => is_scalar($item)));
    }
    if (!is_scalar($value)) {
      return '';
    }

    return mb_substr(ReviewRequest::normalizeMessage((string) $value), 0, self::SUMMARY_VALUE_MAX);
  }

}
