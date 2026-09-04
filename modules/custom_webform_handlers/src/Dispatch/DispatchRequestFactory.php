<?php

namespace Drupal\custom_webform_handlers\Dispatch;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\custom_webform_handlers\Dispatch\Profile\BookingProfile;
use Drupal\custom_webform_handlers\Dispatch\Profile\BookingProfileResolverInterface;
use Drupal\webform\WebformInterface;
use Drupal\webform\WebformSubmissionInterface;

/**
 * Rebuilds a Dispatch Request from submitted values, server-side, every time.
 *
 * Nothing a browser sends describes the job here. The browser sends element
 * values, which is what a Webform already stores; this class turns those values
 * into the operational facts the planner reasons about, using a mapping that lives
 * in configuration. A request that arrived as JSON and a request read back off a
 * completed submission therefore go through exactly one normalisation, which is
 * what makes "re-run feasibility at confirmation" a meaningful sentence: if the
 * two disagree, the facts changed.
 *
 * The mapping is configuration rather than code because the element keys belong to
 * the Webform, not to the planner. Keys shipped empty are deliberate. An unmapped
 * fact reads as unknown, and unknown reaches eligibility as Manual Review — never
 * as a permissive default. That is the whole reason store_pickup_paid_and_ready
 * ships unmapped: until an element exists to answer it, a Store Pickup request
 * cannot be self-booked at all.
 *
 * One fact is deliberately not a question at all. A service the profile declares
 * to be a one-driver service — see singleDriverHandling() — carries that fact by
 * definition, so it is resolved from the service policy before any element is
 * read. The unknown-is-Manual-Review invariant still governs every service type
 * the policy does not name.
 *
 * The addresses are the exception to the mapping: they come from the handler's own
 * origin and destination settings, because those already exist, are already
 * validated, and are already what the quote is calculated from. Duplicating them
 * into a second mapping would let dispatch route one pair of addresses while the
 * quote prices another.
 */
final class DispatchRequestFactory {

  /**
   * The operating profile, which carries the element mapping.
   */
  const SETTINGS = 'custom_webform_handlers.dispatch';

  /**
   * The one handler that owns dispatch on a Webform.
   */
  const HANDLER_ID = 'address_distance_handler';

  /**
   * Pounds per kilogram, matching the figure the quote path already uses.
   */
  const POUNDS_PER_KILOGRAM = 2.2046226218;

  /**
   * The prohibited-contents radio option that means "nothing prohibited".
   *
   * A vocabulary fact rather than a policy, so it lives here beside the unit names
   * this class already interprets rather than in configuration: it is the stored
   * option value of an element, and renaming it would break historic submissions
   * whatever configuration said. It is the same value QuoteCalculator accepts, and
   * the two must not be allowed to drift apart.
   */
  const CONTENTS_SAFE_VALUE = 'none';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly BookingProfileResolverInterface $bookingProfiles,
  ) {}

  /**
   * Returns the dispatch-enabled handler settings for a Webform, or NULL.
   *
   * "Enabled" means more than a flag: the handler must be enabled, dispatch must
   * be switched on, and the elements dispatch depends on must all be named. A
   * half-configured handler is treated as not configured, because a dispatch gate
   * that cannot find the field it is meant to read would otherwise pass.
   *
   * @return array|null
   *   The handler's settings, or NULL when this Webform does not do dispatch.
   */
  public function settingsFor(string $webformId): ?array {
    $webform = $this->loadWebform($webformId);
    if ($webform === NULL) {
      return NULL;
    }

    foreach ($webform->getHandlers() as $handler) {
      if ($handler->getPluginId() !== self::HANDLER_ID || !$handler->isEnabled()) {
        continue;
      }
      $settings = $handler->getConfiguration()['settings'] ?? [];
      if (!is_array($settings) || empty($settings['dispatch_enabled'])) {
        continue;
      }
      if (trim((string) ($settings['origin_address_field'] ?? '')) === ''
        || trim((string) ($settings['destination_address_field'] ?? '')) === ''
        || trim((string) ($settings['dispatch_slot_field'] ?? '')) === ''
        || trim((string) ($settings['dispatch_hold_token_field'] ?? '')) === '') {
        continue;
      }

      return $settings;
    }

    return NULL;
  }

  /**
   * Tells whether dispatch, and not the older fixed-slot guard, owns a Webform.
   *
   * This is the cutover interlock, and it lives here because this class already
   * decides what "dispatch is configured on this Webform" means. One definition,
   * so the planner, the booking endpoints, and the Webform handler cannot disagree
   * about which workflow is enforcing.
   *
   * It is deliberately one-directional. The fixed-slot workflow enforces exactly
   * when its own reservation_enabled setting is on; dispatch enforces only when
   * that setting is off and dispatch is on. The two conditions are mutually
   * exclusive by construction, so no ordering of a cutover — no partially applied
   * configuration, no half-rebuilt cache — leaves both guards enforcing the same
   * submission. Expressing it this way, rather than by editing the live fixed-slot
   * guard, is what makes that true without altering the behaviour of a workflow
   * that is currently taking real bookings.
   *
   * Both switches on is a misconfiguration, and it resolves in favour of the guard
   * that is already enforcing: dispatch stands down.
   */
  public function dispatchOwns(string $webformId): bool {
    $settings = $this->settingsFor($webformId);

    return $settings !== NULL && empty($settings['reservation_enabled']);
  }

  /**
   * Tells whether both guards are switched on for one Webform.
   *
   * Separated from dispatchOwns() so a caller can report the misconfiguration
   * without having to re-derive why dispatch stood down.
   */
  public function hasConflictingGuards(string $webformId): bool {
    $settings = $this->settingsFor($webformId);

    return $settings !== NULL && !empty($settings['reservation_enabled']);
  }

  /**
   * Builds a Dispatch Request from raw element values, or NULL.
   *
   * @param string $webformId
   *   The Webform the values belong to. Used to find the element mapping; it is
   *   never taken from the values themselves.
   * @param array $values
   *   Element values keyed by element key, as a submission stores them.
   *
   * @return \Drupal\custom_webform_handlers\Dispatch\DispatchRequest|null
   *   The request, or NULL when this Webform does not do dispatch or either
   *   address is empty. A request without both endpoints has no Dispatch Window,
   *   so there is nothing to plan and nothing to refuse in detail.
   */
  public function fromValues(string $webformId, array $values): ?DispatchRequest {
    $settings = $this->settingsFor($webformId);
    if ($settings === NULL) {
      return NULL;
    }

    return $this->build($webformId, $values, $settings);
  }

  /**
   * Builds a Dispatch Request from a submission's stored values, or NULL.
   *
   * This is the confirmation path's only source of facts. Reading the submission
   * rather than the request that is confirming it is the point: the Hold reserved
   * capacity for a job described a certain way, and only the saved submission says
   * what the job actually became.
   */
  public function fromSubmission(WebformSubmissionInterface $submission): ?DispatchRequest {
    $webform = $submission->getWebform();
    if (!$webform instanceof WebformInterface) {
      return NULL;
    }

    return $this->fromValues((string) $webform->id(), $submission->getData());
  }

  /**
   * The element key configured to carry the requested service date, or ''.
   *
   * Separate from reading the value because the two answer different questions.
   * An empty key means no element was nominated at all, and a caller that needs a
   * date must then fall back to whatever it was given; a nominated key with an
   * empty value means the customer has not chosen a date yet, which is a refusal
   * rather than a fallback. Collapsing the two would let a missing configuration
   * look exactly like a missing answer.
   */
  public function requestedDateElement(): string {
    return trim((string) ($this->fieldMap()['requested_date'] ?? ''));
  }

  /**
   * The requested service date the values carry, as an unvalidated string, or ''.
   *
   * The format is deliberately not checked here: this returns what the customer's
   * own form said, and the caller that plans against it decides whether a date it
   * cannot parse is a refusal. Multi-value and composite elements read as unknown,
   * as they do for every other fact.
   */
  public function requestedDate(array $values): string {
    $element = $this->requestedDateElement();
    if ($element === '') {
      return '';
    }

    return trim((string) $this->scalar($values, $element));
  }

  /**
   * Normalises one set of element values against one handler's settings.
   */
  private function build(string $webformId, array $values, array $settings): ?DispatchRequest {
    $pickup = trim((string) $this->scalar($values, (string) $settings['origin_address_field']));
    $delivery = trim((string) $this->scalar($values, (string) $settings['destination_address_field']));
    if ($pickup === '' || $delivery === '') {
      return NULL;
    }

    $map = $this->fieldMap();
    $serviceType = trim((string) $this->mapped($values, $map, 'service_type'));

    return new DispatchRequest(
      $webformId,
      $serviceType,
      $pickup,
      $delivery,
      DeliveryMode::tryFrom(trim((string) $this->mapped($values, $map, 'delivery_mode'))),
      $this->weightLbs($values, $map, $serviceType),
      $this->singleDriverHandling($values, $map, $serviceType),
      $this->nullableString($values, $map, 'vehicle_helper_needs'),
      $this->flagList($values, $map, 'add_ons'),
      $this->flagList($values, $map, 'handling_flags'),
      $this->nullableString($values, $map, 'move_size'),
      $this->nullableInt($values, $map, 'origin_stairs_flights'),
      $this->nullableInt($values, $map, 'destination_stairs_flights'),
      $this->nullableString($values, $map, 'origin_access'),
      $this->nullableString($values, $map, 'destination_access'),
      $this->nullableString($values, $map, 'parking_situation'),
      $this->nullableInt($values, $map, 'specialty_item_count'),
      $this->boolean($values, $map, 'store_pickup_paid_and_ready'),
      $this->contentsAttested($values, $map),
    );
  }

  /**
   * Resolves the one-driver fact: service policy first, element second.
   *
   * Express Courier is *defined* as a one-driver, self-service courier. The
   * profile's Resource is one driver and one vehicle, and the weight ceiling
   * exists precisely so that one person can carry the package unaided. For a
   * service like that the fact is a property of the service, not a question for
   * the customer, so the service's BookingProfile answers
   * singleDriverByDefinition() and this returns TRUE for it before any element is
   * read.
   *
   * That is what makes the fact trustworthy. The browser never was a safe source
   * for it: a stale wizard value, a resumed draft, or a crafted payload saying
   * single_driver_handling=0 would otherwise push an entirely valid in-limit
   * Courier request into Manual Review, and the customer would have no way to see
   * why. Reading the policy instead means the same request gets the same verdict
   * from the quote, the offer endpoint, and the checkout requote, because all
   * three come through this one seam.
   *
   * Every service type the policy does not name still reads the mapped element, so
   * the class-level invariant holds where it still applies: unmapped or unanswered
   * is unknown, unknown is FALSE here, and FALSE reaches eligibility as Manual
   * Review. Nor does this make a genuine two-person job self-bookable — a declared
   * helper need (manual_review.vehicle_helper_needs) or a multi_person handling
   * flag is refused in assess() before the courier branch is reached at all, and
   * the weight ceiling is untouched.
   */
  private function singleDriverHandling(array $values, array $map, string $serviceType): bool {
    if ($serviceType !== ''
      && $this->bookingProfiles->resolve($serviceType)->singleDriverByDefinition()) {
      return TRUE;
    }

    return $this->boolean($values, $map, 'single_driver_handling') === TRUE;
  }

  /**
   * Reads the customer's prohibited-contents affirmation, or NULL for unasked.
   *
   * Two controls can carry it, and they do not share a vocabulary. The simplified
   * Courier UI renders a checkbox whose truthiness is the answer. Historic
   * submissions - and the canonical value the handler writes on save - carry the
   * older radio, where only one option value means "nothing prohibited" and the
   * rest ('restricted', 'unsure') are the customer telling us something.
   *
   * The vocabularies stay separate rather than being merged into one permissive
   * list, because a crafted checkbox value of 'none' must not pass a check that
   * the quote calculator would refuse: the offer and the quote have to reach the
   * same answer about the same package. The rule here is deliberately the same one
   * QuoteCalculator applies.
   *
   * @return bool|null
   *   TRUE when a mapped control affirms it, FALSE when a mapped control answered
   *   without affirming, NULL when nothing is mapped or nothing was answered.
   *   Only TRUE is an affirmation; the eligibility rules decide what the other two
   *   cost.
   */
  private function contentsAttested(array $values, array $map): ?bool {
    $answered = FALSE;

    $checkbox = $this->boolean($values, $map, 'contents_attestation');
    if ($checkbox === TRUE) {
      return TRUE;
    }
    $answered = $answered || $checkbox !== NULL;

    $element = trim((string) ($map['contents_attestation_legacy'] ?? ''));
    if ($element !== '') {
      $legacy = $this->scalar($values, $element);
      if (is_string($legacy) && trim($legacy) !== '') {
        return trim($legacy) === self::CONTENTS_SAFE_VALUE;
      }
    }

    return $answered ? FALSE : NULL;
  }

  /**
   * Reads the configured fact-to-element mapping.
   *
   * @return array<string, mixed>
   */
  private function fieldMap(): array {
    $map = $this->configFactory->get(self::SETTINGS)->get('request_fields');

    return is_array($map) ? $map : [];
  }

  /**
   * Returns the raw value of the element a fact is mapped to.
   *
   * An unmapped fact and a mapped element with no value are the same thing to
   * every caller below: unknown.
   */
  private function mapped(array $values, array $map, string $fact): mixed {
    $element = trim((string) ($map[$fact] ?? ''));

    return $element === '' ? NULL : $this->scalar($values, $element);
  }

  /**
   * Reads one element value, refusing anything that is not a single value.
   *
   * A composite or multi-value element reaching a fact that expects one answer is
   * unknown rather than its first item, because picking an item would be the
   * planner deciding which of several answers the customer meant.
   */
  private function scalar(array $values, string $element): mixed {
    $value = $values[$element] ?? NULL;

    return is_scalar($value) ? $value : NULL;
  }

  /**
   * Converts a declared weight to pounds, or returns NULL for unknown.
   *
   * Only 'lb' and 'kg' are accepted. The quote path treats any other unit as
   * kilograms, which is a reasonable default for a price; it is not a reasonable
   * default for a hard capacity limit, where guessing the unit wrong by a factor
   * of 2.2 either refuses a legitimate booking or accepts a package the driver
   * cannot carry. Unknown here means the weight is undeclared, which the
   * eligibility rules already route to Manual Review.
   *
   * For a service the profile times as Courier the unit is not read at all, for
   * the same reason the one-driver fact is not read: it is a property of the
   * service rather than an answer the customer gives. The Courier UI has no unit
   * selector, the weight question is labelled in pounds, and
   * AddressDistanceHandler canonicalises the stored unit to 'lb' on save through
   * this same profile test. Reading the element here as well left the offer
   * endpoint trusting a value no one can set, and the browser is not a safe source
   * for it: a wizard that visited another service first stores the hidden unit
   * element empty, the server hands that empty answer back in its own context
   * defaults, and an empty unit read as unknown made a complete, in-limit Courier
   * request answer "incomplete" — a request the customer had finished, refused for
   * a question they were never asked. A crafted 'kg' is refused the same way, by
   * ignoring it: the offered, priced, and saved weight are then the same number by
   * construction rather than by three readers agreeing.
   */
  private function weightLbs(array $values, array $map, string $serviceType): ?float {
    $weight = $this->mapped($values, $map, 'declared_weight');
    if (!is_numeric($weight)) {
      return NULL;
    }
    $weight = (float) $weight;
    if ($weight <= 0.0) {
      return NULL;
    }

    if ($this->weighsInPoundsByDefinition($serviceType)) {
      return $weight;
    }

    $unit = strtolower(trim((string) $this->mapped($values, $map, 'declared_weight_unit')));

    return match ($unit) {
      'lb', 'lbs' => $weight,
      'kg', 'kgs' => $weight * self::POUNDS_PER_KILOGRAM,
      default => NULL,
    };
  }

  /**
   * Whether this service's declared weight is pounds by definition.
   *
   * The same profile test AddressDistanceHandler uses to canonicalise the stored
   * unit, asked through the resolver rather than duplicated as a service-type list,
   * so the offer endpoint and the saved submission cannot come to different
   * conclusions about what a Courier weight means. A service the profile does not
   * time as Courier still has to declare its unit.
   */
  private function weighsInPoundsByDefinition(string $serviceType): bool {
    return $serviceType !== ''
      && $this->bookingProfiles->resolve($serviceType)->durationResolver
        === BookingProfile::DURATION_COURIER;
  }

  /**
   * Reads a tri-state answer: TRUE, FALSE, or unanswered.
   *
   * An unchecked checkbox is a definite no and reads as FALSE. An unmapped or
   * absent element was never asked and reads as NULL, which is not a no — the
   * distinction matters because Store Pickup requires an explicit yes.
   *
   * A checkbox does not always arrive as a scalar. Depending on the path a value
   * travels — a multi-value element, a re-populated wizard step, a stored draft —
   * the same yes can arrive as '1' or as ['1']. A one-element array says exactly
   * one thing, so it is unwrapped to that element; every other shape (empty,
   * multi-value, nested, an object) is an answer this reader cannot attribute and
   * stays unknown. is_scalar() guards every cast, so no shape can emit an "Array
   * to string conversion" warning.
   */
  private function boolean(array $values, array $map, string $fact): ?bool {
    $element = trim((string) ($map[$fact] ?? ''));
    if ($element === '' || !array_key_exists($element, $values)) {
      return NULL;
    }
    $value = $values[$element];
    if (is_array($value)) {
      $only = count($value) === 1 ? reset($value) : NULL;

      return is_scalar($only) ? $this->strictBoolean($only) : NULL;
    }
    if (!is_scalar($value)) {
      return NULL;
    }
    if (is_string($value)) {
      $value = trim($value);
      if ($value === '') {
        return NULL;
      }
      return !in_array(strtolower($value), ['0', 'false', 'no', 'off'], TRUE);
    }

    return (bool) $value;
  }

  /**
   * Reads one unwrapped scalar strictly: only an unambiguous yes or no.
   *
   * The scalar path in boolean() is deliberately permissive — any non-empty value
   * that is not a recognised negative reads TRUE, which is how a checkbox's '1'
   * and a select's 'yes' both work. That is safe for a value the browser posted as
   * a scalar, but it must not be extended to a value this reader had to reshape:
   * an unrecognised string inside an array is not evidence of a yes, so it stays
   * unknown rather than being promoted to TRUE.
   *
   * The affirmative set is the one AddressDistanceHandler::attestationChecked()
   * and QuoteCalculator::attestationChecked() accept, so a Courier attestation
   * shaped as ['1'] prices and offers Slots consistently on all three paths.
   * Change them together or not at all.
   */
  private function strictBoolean(string|int|float|bool $value): ?bool {
    $normalized = is_bool($value)
      ? ($value ? '1' : '0')
      : strtolower(trim((string) $value));
    if (in_array($normalized, ['1', 'true', 'yes', 'on'], TRUE)) {
      return TRUE;
    }
    if (in_array($normalized, ['0', 'false', 'no', 'off'], TRUE)) {
      return FALSE;
    }

    return NULL;
  }

  /**
   * Reads a machine value, or NULL when it was not answered.
   */
  private function nullableString(array $values, array $map, string $fact): ?string {
    $value = trim((string) $this->mapped($values, $map, $fact));

    return $value === '' ? NULL : $value;
  }

  /**
   * Reads a count, or NULL when it was not answered.
   *
   * NULL is not zero. "How many specialty items?" left blank is a question the
   * customer did not answer, and answering it as none on their behalf is how a
   * planner ends up scheduling a job it has not sized.
   */
  private function nullableInt(array $values, array $map, string $fact): ?int {
    $value = $this->mapped($values, $map, $fact);
    if (!is_numeric($value)) {
      return NULL;
    }
    $value = (int) $value;

    return $value >= 0 ? $value : NULL;
  }

  /**
   * Collects machine values from every element a list fact is mapped to.
   *
   * The mapping is a list of element keys rather than a single key, because both
   * add-ons and handling markers can be answered by more than one element. Values
   * are taken verbatim: the vocabulary they are compared against lives in
   * configuration, and reinterpreting them here would make a configured Manual
   * Review rule silently stop matching.
   *
   * @return string[]
   */
  private function flagList(array $values, array $map, string $fact): array {
    $elements = $map[$fact] ?? [];
    if (!is_array($elements)) {
      return [];
    }

    $collected = [];
    foreach ($elements as $element) {
      $element = trim((string) $element);
      if ($element === '' || !array_key_exists($element, $values)) {
        continue;
      }
      $value = $values[$element];
      foreach (is_array($value) ? $value : [$value] as $item) {
        if (!is_scalar($item)) {
          continue;
        }
        $item = trim((string) $item);
        if ($item !== '') {
          $collected[] = $item;
        }
      }
    }

    return array_values(array_unique($collected));
  }

  /**
   * Loads a Webform by machine name, refusing anything that is not one.
   */
  private function loadWebform(string $webformId): ?WebformInterface {
    $webformId = trim($webformId);
    if ($webformId === '' || strlen($webformId) > 128 || preg_match('/^[a-z0-9_]+$/', $webformId) !== 1) {
      return NULL;
    }

    $webform = $this->entityTypeManager->getStorage('webform')->load($webformId);

    return $webform instanceof WebformInterface ? $webform : NULL;
  }

}
