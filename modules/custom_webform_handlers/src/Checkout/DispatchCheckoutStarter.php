<?php

namespace Drupal\custom_webform_handlers\Checkout;

use Drupal\commerce_cart\CartSessionInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;
use Drupal\custom_webform_handlers\Distance\RouteFactStore;
use Drupal\custom_webform_handlers\Distance\RoutingModeResolver;
use Drupal\custom_webform_handlers\Distance\RoutingPolicy;
use Drupal\custom_webform_handlers\Plugin\WebformHandler\AddressDistanceHandler;
use Drupal\custom_webform_handlers\Dispatch\DispatchRequestFactory;
use Drupal\custom_webform_handlers\Dispatch\ResourceProfileFactory;
use Drupal\custom_webform_handlers\Dispatch\Slot;
use Drupal\custom_webform_handlers\Quote\QuoteCalculator;
use Drupal\custom_webform_handlers\Review\ReviewRequest;
use Drupal\custom_webform_handlers\Review\ReviewState;
use Drupal\webform\WebformSubmissionInterface;
use Psr\Log\LoggerInterface;

/**
 * Turns a saved dispatch submission into a Commerce checkout to redirect to.
 *
 * This is the entry seam Step 3 begins at, and it is deliberately the only place
 * a submission becomes a payable order. It composes what already exists — the
 * server's own quote, the operating Resource Profile, the checkout manager, and
 * the anonymous cart session — into one refusable answer: either a checkout URL,
 * or NULL. NULL is a refusal the caller must honour by finishing the submission
 * normally and charging nothing; it is never an error to report upward, because
 * the reasons for it (no dispatch here, no arrival chosen, a manual-review quote,
 * an address that no longer routes) are all ordinary outcomes of a public form.
 *
 * Two properties are load-bearing and are the reason this is a service rather
 * than inline handler code:
 *
 *  - The price is derived, never trusted. The quote is rebuilt here from the
 *    submission's own stored addresses and the same server-owned route fact the
 *    handler validated at preSave (re-priced through the same QuoteCalculator),
 *    then locked into a QuoteSnapshot — whose constructor is private, so an amount
 *    can only ever have come from the server's quote. A browser cannot introduce a
 *    total, the route is reused rather than bought again, and a quote that has
 *    become manual-review — or a route fact that is missing or expired — yields no
 *    snapshot and therefore no checkout.
 *
 *    A reviewed job reaches payment by the second door, startFromApprovedReview(),
 *    and the property is unchanged rather than excepted: that amount comes from a
 *    Review Request row a staff form wrote, through a second private factory on
 *    the same value object. Two authorities may price a job — the rate table and a
 *    member of staff — and neither of them is the customer.
 *
 *  - The capacity facts are derived, never trusted. The Slot's Resource and
 *    timezone come from ResourceProfileFactory (configuration only), and the
 *    pickup arrival comes from the submission's server-written slot field, not
 *    from a request parameter. The BookingWorkflowGate remains the authority on
 *    whether dispatch may act at all: the manager consults it inside start(),
 *    and the cheap dispatchOwns() check here only spares a fully-configured but
 *    non-owned Webform an unnecessary route lookup before that authority runs.
 *
 * It holds no Hold, opens no order, and touches no capacity itself. start() does
 * all three, atomically and idempotently, and this class only supplies it with
 * derived facts and registers the resulting order in the customer's session so
 * an anonymous visitor keeps access to their own checkout.
 */
final class DispatchCheckoutStarter {

  /**
   * The operating profile, read for the customer-notification email element.
   *
   * The same config object ResourceProfileFactory and the notification composer
   * read. The email field name lives here, not in the handler settings, so that
   * the address an order is opened against is the identical element the eventual
   * booking notice is sent to — one answer to "who is the customer", in one
   * place.
   */
  const SETTINGS = 'custom_webform_handlers.dispatch';

  public function __construct(
    private readonly DispatchCheckoutManagerInterface $manager,
    private readonly DispatchRequestFactory $requestFactory,
    private readonly ResourceProfileFactory $profileFactory,
    private readonly QuoteCalculator $quoteCalculator,
    private readonly RouteFactStore $routeFactStore,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly CartSessionInterface $cartSession,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerInterface $logger,
    private readonly RoutingModeResolver $routingModeResolver,
  ) {}

  /**
   * Starts checkout for one saved submission, or refuses with NULL.
   *
   * @param \Drupal\webform\WebformSubmissionInterface $submission
   *   The submission that has just recorded checkout intent.
   *
   * @return \Drupal\Core\Url|null
   *   The Commerce checkout URL to redirect the customer to, or NULL when this
   *   submission is not a dispatch checkout or cannot become one.
   */
  public function start(WebformSubmissionInterface $submission): ?Url {
    $prepared = $this->prepare($submission);
    if ($prepared === NULL) {
      return NULL;
    }

    // The price, rebuilt from the submission's own addresses. This is the same
    // seam preSave ran, so within the request that saved the submission the
    // route provider answers from a warm cache and the figure is identical to
    // the one the visitor was shown. A manual-review quote yields no snapshot.
    $snapshot = $this->resolveQuote($submission, $prepared['settings'], $prepared['submission_id']);
    if ($snapshot === NULL) {
      return NULL;
    }

    return $this->open($submission, $prepared['slot'], $prepared['contact'], $snapshot);
  }

  /**
   * Starts checkout for a job a member of staff has priced, or refuses.
   *
   * The same order, the same Hold, the same attempt — only the figure's authority
   * differs. Everything that makes the booking real still comes from the server:
   * the Slot from the submission's own field, the contact from configuration, and
   * the amount from a Review Request row a staff form wrote. The tracking code
   * that got the customer here is a credential, not a price; it never reaches this
   * method, and nothing the customer can send names an amount.
   *
   * Approval is an offer, and this is where it is accepted. It is also the last
   * place the deadline can be honoured, so the deadline is honoured here as well
   * as in the tracker: the cron sweep may not have run, and a lapsed offer must
   * not open a payable order merely because a page was left open. The caller
   * supplies the clock rather than this class holding one, which keeps the one
   * answer to "what time is it" with the request that is asking.
   *
   * @param \Drupal\custom_webform_handlers\Review\ReviewRequest $request
   *   The approved request. Only ReviewRequestStore builds one, and only from a
   *   row this module wrote, so an amount cannot arrive here from a browser.
   * @param int $now
   *   The instant to judge the offer's deadline against.
   *
   * @return \Drupal\Core\Url|null
   *   The Commerce checkout URL, or NULL when this request may not be paid.
   */
  public function startFromApprovedReview(ReviewRequest $request, int $now): ?Url {
    if ($request->state !== ReviewState::Approved || $request->hasLapsed($now)) {
      return NULL;
    }

    // The cheap refusal is kept first: a request with no readable price cannot
    // become a snapshot however the rest resolves, so it is refused before the
    // submission is loaded. The snapshot itself is built later, once the accepted
    // job's identity can be derived from the submission it was approved for — a
    // reviewed figure is still bound to its job (Task 24B / E09), not floated free
    // of it.
    if ($request->quoteAmountCents === NULL || $request->quoteCurrency === NULL) {
      return NULL;
    }

    $submission = $this->loadSubmission($request->submissionId);
    if ($submission === NULL) {
      return NULL;
    }

    // The row names its Webform; the submission is the authority on which one it
    // actually belongs to. They disagreeing means the submission was replaced
    // under a review, which is not a job anyone approved.
    $webform = $submission->getWebform();
    if ($webform === NULL || (string) $webform->id() !== $request->webformId) {
      $this->logger->warning('Review Request @id was not paid: submission @sid no longer belongs to webform @webform.', [
        '@id' => $request->reviewId,
        '@sid' => $request->submissionId,
        '@webform' => $request->webformId,
      ]);

      return NULL;
    }

    $prepared = $this->prepare($submission);
    if ($prepared === NULL) {
      return NULL;
    }

    // The staff figure, locked by the same value object an automatic quote locks,
    // and bound to the job the reviewer priced. The accepted job is derived from the
    // submission and the same endpoints the review saw, so a reviewed booking
    // confirms only while that job is unchanged — the approved price is retained,
    // and an intervening edit is what the confirm-time comparison would catch.
    //
    // A review whose endpoints are no longer readable, or whose facts cannot be
    // canonicalized, is REFUSED here — not bound to an empty identity and waved
    // through as legacy. A newly accepted review is a new snapshot and must carry a
    // real identity (Task 24B / E09); the legacy skip is only ever for rows written
    // before the identity existed, never minted for a review the customer is about
    // to pay.
    $origin = $submission->getElementData((string) ($prepared['settings']['origin_address_field'] ?? ''));
    $destination = $submission->getElementData((string) ($prepared['settings']['destination_address_field'] ?? ''));
    if (!is_string($origin) || !is_string($destination) || $origin === '' || $destination === '') {
      $this->logger->warning('Review Request @id was not paid: submission @sid has no readable pickup/delivery address to bind the accepted job to.', [
        '@id' => $request->reviewId,
        '@sid' => $request->submissionId,
      ]);

      return NULL;
    }

    $acceptedJob = $this->acceptedJobFor($submission, $origin, $destination, $request->submissionId);
    if ($acceptedJob === NULL) {
      return NULL;
    }

    $snapshot = QuoteSnapshot::fromApprovedReview($request, $acceptedJob);
    if ($snapshot === NULL) {
      return NULL;
    }

    return $this->open($submission, $prepared['slot'], $prepared['contact'], $snapshot);
  }

  /**
   * Derives everything an entry point needs to open an order, or refuses.
   *
   * The steps are ordered cheapest-refusal-first: every reason a submission is
   * not a dispatch checkout is settled before the one network call (the route
   * lookup) and before start() opens anything, so a form that is not payable
   * costs nothing and leaves no draft order behind.
   *
   * These are the facts that do not depend on where the price came from — which
   * Webform this is, which Slot the server wrote, who the customer is — so they
   * are derived once and an automatic quote and a staff-approved one open their
   * order against the identical Slot and contact. The price is deliberately not
   * among them. It is the only thing the two paths disagree about, and each
   * supplies its own.
   *
   * @param \Drupal\webform\WebformSubmissionInterface $submission
   *   The submission being checked out.
   *
   * @return array{settings: array, slot: \Drupal\custom_webform_handlers\Dispatch\Slot, contact: \Drupal\custom_webform_handlers\Checkout\CheckoutContact, submission_id: int}|null
   *   The derived facts, or NULL when this submission cannot become an order.
   */
  private function prepare(WebformSubmissionInterface $submission): ?array {
    $submissionId = (int) $submission->id();
    if ($submissionId < 1) {
      return NULL;
    }

    $webform = $submission->getWebform();
    $webformId = $webform !== NULL ? (string) $webform->id() : '';
    if ($webformId === '') {
      return NULL;
    }

    // Which fields on this Webform hold the addresses and the chosen arrival.
    // NULL means dispatch is disabled or half-configured here; either way there
    // is nothing to check out.
    $settings = $this->requestFactory->settingsFor($webformId);
    if ($settings === NULL) {
      return NULL;
    }

    // The gate inside start() is the authority; this only avoids rebuilding a
    // quote for a Webform the reservation workflow owns instead.
    if (!$this->requestFactory->dispatchOwns($webformId)) {
      return NULL;
    }

    // The pickup arrival the server wrote when the Slot was chosen. Read as the
    // canonical UTC instant it is; a missing or non-positive value is a
    // submission that never selected a Slot, so there is nothing to book.
    $slotField = (string) ($settings['dispatch_slot_field'] ?? '');
    $pickupArrival = $slotField !== '' ? (int) $submission->getElementData($slotField) : 0;
    if ($pickupArrival < 1) {
      return NULL;
    }

    // The Resource and its timezone come from configuration only. NULL is a
    // fail-closed profile — no Resource, therefore no Slot, therefore no Hold.
    $profile = $this->profileFactory->create();
    if ($profile === NULL) {
      return NULL;
    }

    try {
      $slot = new Slot($webformId, $profile->resourceId, $pickupArrival, $profile->timezone);
    }
    catch (\InvalidArgumentException $e) {
      // A stored zone that is no longer an IANA identifier. Refused rather than
      // resolved to UTC, which would move the local pickup time silently.
      $this->logger->warning('Dispatch checkout was refused for submission @sid: @message', [
        '@sid' => $submissionId,
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }

    // The customer email is the notification address, read from the same config
    // element the booking notice uses, and validated by the value object rather
    // than here. No valid email means no order can carry a receipt, so no
    // checkout.
    $contact = $this->resolveContact($submission);
    if ($contact === NULL) {
      return NULL;
    }

    return [
      'settings' => $settings,
      'slot' => $slot,
      'contact' => $contact,
      'submission_id' => $submissionId,
    ];
  }

  /**
   * Opens the order and returns the customer's checkout destination.
   *
   * The manager's start() is the only composer of gate, Hold, order, and attempt,
   * and it is idempotent: the same submission, Slot, and price return the same
   * order rather than a second one. A refusal here has already undone anything it
   * began. Both entry points end in this one method so neither can acquire a
   * different idea of what opening an order involves.
   */
  private function open(WebformSubmissionInterface $submission, Slot $slot, CheckoutContact $contact, QuoteSnapshot $snapshot): ?Url {
    $outcome = $this->manager->start($submission, $slot, $contact, $snapshot);
    if (!$outcome->ok) {
      return NULL;
    }

    $orderId = (int) ($outcome->data['order_id'] ?? 0);
    if ($orderId < 1) {
      return NULL;
    }

    // Register the order in the customer's session. This is what grants an
    // anonymous visitor access to their own checkout: CheckoutController admits
    // an anonymous request only for an order the session owns. Harmless for an
    // authenticated customer, whose access comes from the order's uid instead.
    $this->cartSession->addCartId($orderId);

    // Step defaults to the first incomplete step; Commerce resolves it.
    return Url::fromRoute('commerce_checkout.form', ['commerce_order' => $orderId]);
  }

  /**
   * Loads one submission, or NULL when it is gone or unreadable.
   *
   * A Review Request outlives nothing, but a submission can be deleted while a
   * review stands. That is a refusal, not a fault: there is no job left to book.
   */
  private function loadSubmission(int $submissionId): ?WebformSubmissionInterface {
    if ($submissionId < 1) {
      return NULL;
    }

    try {
      $submission = $this->entityTypeManager->getStorage('webform_submission')->load($submissionId);
    }
    catch (\Exception $e) {
      $this->logger->error('A submission could not be loaded for payment: @message', [
        '@message' => $e->getMessage(),
      ]);

      return NULL;
    }

    return $submission instanceof WebformSubmissionInterface ? $submission : NULL;
  }

  /**
   * Reads and validates the customer's email from the configured element.
   *
   * The element name is the notification recipient field, so the order is opened
   * against the identical address the booking notice is later sent to. Validation
   * is CheckoutContact's, not this method's: it lowercases, bounds, and rejects a
   * malformed or empty value, returning NULL, which surfaces here as a refusal.
   */
  private function resolveContact(WebformSubmissionInterface $submission): ?CheckoutContact {
    $emailField = $this->configFactory->get(self::SETTINGS)->get('recipients.customer_email_field');
    if (!is_string($emailField) || $emailField === '') {
      return NULL;
    }

    $raw = $submission->getElementData($emailField);
    if (!is_string($raw)) {
      return NULL;
    }

    return CheckoutContact::create($raw);
  }

  /**
   * Rebuilds the server quote and locks it into a snapshot, or refuses.
   *
   * The route is REUSED, not recomputed: it comes from the same server-owned route
   * fact the acceptance gate and preSave validated, retrieved for this owner and
   * re-validated (ordered pair, routing mode, purpose, policy version, expiry). The
   * fact carries the duration a stored mileage could not, which is why an earlier
   * version recomputed here; reusing the fact supplies both distance and duration
   * with no route call, so an unchanged booking is carried to payment without buying
   * the route again. The full submission data is passed to the calculator exactly as
   * preSave passes it — no re-filtered subset. A missing, expired or mismatched fact
   * yields NULL (the existing refusal: finish the submission and charge nothing),
   * never a network fallback or a fabricated distance; a fresh route requires an
   * explicit address re-commit. A manual-review status likewise yields no snapshot.
   */
  /**
   * Reads the route-fact reference the handler persisted in a stored breakdown.
   *
   * The reference is the only handle on the exact fact an estimate was priced from;
   * it travels inside the breakdown so save and checkout price from the identical
   * route without a new form element. An empty string ("no reference") is a refusal
   * to price, never a licence to re-select a fact by pair.
   */
  private function routeReferenceFrom(WebformSubmissionInterface $submission): string {
    // The dedicated per-instance carrier the handler persisted in this submission's
    // own data. Bound to this submission, never recovered by owner or address pair.
    $value = $submission->getElementData(AddressDistanceHandler::ROUTE_REFERENCE_KEY);
    $value = is_string($value) ? trim($value) : '';

    return ($value !== '' && strlen($value) <= 128 && preg_match('/^[0-9a-f]+$/', $value) === 1) ? $value : '';
  }

  private function resolveQuote(WebformSubmissionInterface $submission, array $settings, int $submissionId): ?QuoteSnapshot {
    $origin = $submission->getElementData((string) ($settings['origin_address_field'] ?? ''));
    $destination = $submission->getElementData((string) ($settings['destination_address_field'] ?? ''));
    if (!is_string($origin) || !is_string($destination) || $origin === '' || $destination === '') {
      return NULL;
    }

    // The exact fact the accepted estimate was priced from is named by the opaque
    // reference the handler persisted inside the stored breakdown. Retrieve by that
    // reference, not by re-selecting a fact whose pair matches, so checkout prices
    // from the identical route the customer accepted — never a newer one for the
    // same pair.
    $reference = $this->routeReferenceFrom($submission);
    $mode = $this->routingModeResolver->effectiveMode();
    $distance = $this->routeFactStore->retrieve(
      $reference,
      $origin,
      $destination,
      $mode,
      RoutingPolicy::PURPOSE_ORDINARY,
    );
    if ($distance === NULL) {
      // No valid server-owned route fact named by that reference (missing, expired,
      // mismatched, or a breakdown without one), so there is no price we can stand
      // behind and no checkout. Refused, not recomputed: a fresh route is bought
      // only by an explicit address re-commit that re-runs the preview. Logged at
      // warning because it is the customer's request being refused, not a fault.
      $this->logger->warning('Dispatch checkout was refused for submission @sid: no valid server-owned route fact to price from; the estimate must be recommitted.', [
        '@sid' => $submissionId,
      ]);
      return NULL;
    }

    $quote = $this->quoteCalculator->calculate($submission->getData(), $distance);

    // The accepted job, derived from the same submission and the same endpoints the
    // quote was priced for, through the one shared canonicalization (Task 24B / E09).
    // Passed whole to the snapshot so the stored identity and the stored facts come
    // from the same reduction of the same job and provably correspond. It hashes the
    // material inputs, never this figure, so it is the job's identity and not the
    // price's, and confirmation can recompute it to refuse an intervening job edit
    // that left the price and the reserved duration unchanged.
    //
    // A job whose facts cannot be canonicalized — an unrepresentable value shape in
    // the submission — is refused here, not priced. AcceptedJob throws rather than
    // hash over discarded material, and a checkout that cannot name what it is
    // charging for must not open. This is the automatic door's half of "a new
    // snapshot must carry a valid identity": there is no legacy fallback on it.
    $acceptedJob = $this->acceptedJobFor($submission, $origin, $destination, $submissionId);
    if ($acceptedJob === NULL) {
      return NULL;
    }

    return QuoteSnapshot::fromQuoteResult($quote, $origin, $destination, $acceptedJob);
  }

  /**
   * Builds the accepted job for a submission, or refuses when it cannot.
   *
   * The one place both doors reach AcceptedJob, so a reviewed job and an automatic
   * quote derive their identity identically and the confirm path compares like with
   * like. It fails closed: the shared canonicalization throws on a submission value
   * it cannot represent losslessly rather than produce a digest over silently
   * dropped material, and this catches that and returns NULL so the caller refuses
   * the checkout. A NULL here is never read as "legacy, skip the check" — a newly
   * accepted job with underivable facts is refused, not waved through, because the
   * legacy skip exists only for rows written before the identity existed.
   *
   * The endpoints must already be readable non-empty strings; the callers check that
   * before pricing, so an empty endpoint never reaches here to be canonicalized into
   * a hollow identity.
   */
  private function acceptedJobFor(WebformSubmissionInterface $submission, string $origin, string $destination, int $submissionId): ?AcceptedJob {
    try {
      return AcceptedJob::fromSubmissionData($submission->getData(), $origin, $destination);
    }
    catch (\UnexpectedValueException $e) {
      $this->logger->warning('Dispatch checkout was refused for submission @sid: the accepted job could not be canonicalized (@reason).', [
        '@sid' => $submissionId,
        '@reason' => $e->getMessage(),
      ]);

      return NULL;
    }
  }

}
