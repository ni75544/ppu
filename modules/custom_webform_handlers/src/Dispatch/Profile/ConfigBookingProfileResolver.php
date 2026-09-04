<?php

namespace Drupal\custom_webform_handlers\Dispatch\Profile;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\custom_webform_handlers\Dispatch\DispatchReason;

/**
 * Resolves booking profiles from custom_webform_handlers.dispatch.
 *
 * Two layers, and the order matters. `booking_profiles.services.<type>` is read
 * first and wins outright for any fact it names. Anything it does not name is
 * derived from the configuration that already governed that fact before this
 * seam existed — `self_bookable.service_types`, `manual_review.service_types`,
 * `self_bookable.single_driver_service_types`, `require_declared_weight`, the
 * `resource` mapping.
 *
 * That fallback is not politeness. It is what lets the five Manual Review
 * services nobody is piloting keep their exact current behaviour without an
 * entry each: a service the operator listed as Manual Review still refuses with
 * DispatchReason::ManualReview, a service in no list at all still refuses with
 * UnsupportedService, and a service's price book still defaults to its own name,
 * which is the key the quote calculator already switched on. Adding an entry
 * changes a service; adding none changes nothing.
 *
 * Fail-closed throughout. An unreadable mode, an unknown duration resolver name
 * and an absent service all resolve to Manual Review with no duration resolver,
 * because the cost of that is a booking channel and the cost of the opposite is
 * a published Slot for a job nobody can time.
 */
final class ConfigBookingProfileResolver implements BookingProfileResolverInterface {

  /**
   * The policy that assesses self-bookable services when config names none.
   */
  private const DEFAULT_POLICY = 'custom_webform_handlers.dispatch_eligibility';

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function resolve(string $serviceType): BookingProfile {
    $service = trim($serviceType);
    $config = $this->configFactory->get('custom_webform_handlers.dispatch');

    $requestFields = $config->get('request_fields');
    $requestFields = is_array($requestFields) ? $requestFields : [];
    $element = static fn (string $fact): string => trim((string) ($requestFields[$fact] ?? ''));

    // Indexed rather than fetched with a dotted key, so a service type that
    // arrived from a request body can never be read as a configuration path.
    $declared = $config->get('booking_profiles.services');
    $entry = (is_array($declared) && isset($declared[$service]) && is_array($declared[$service]))
      ? $declared[$service]
      : [];

    $selfBookable = $this->stringList($config->get('self_bookable.service_types'));
    $listedSelf = $service !== '' && in_array($service, $selfBookable, TRUE);
    $listedManual = $service !== ''
      && in_array($service, $this->stringList($config->get('manual_review.service_types')), TRUE);

    // A service in both lists is Manual Review. The refusal is the operator's
    // decision and outranks the opening.
    $mode = array_key_exists('mode', $entry)
      ? BookingMode::fromConfig($entry['mode'])
      : (($listedSelf && !$listedManual) ? BookingMode::SelfBookable : BookingMode::ManualReview);

    return new BookingProfile(
      $service,
      $mode,
      $element('requested_date'),
      $this->slotField($config),
      $this->requiredFacts($config, $entry, $mode, $element),
      [
        'policy' => $selfBookable === []
          ? ''
          : trim((string) ($config->get('booking_profiles.eligibility_policy') ?: self::DEFAULT_POLICY)),
        'single_driver_service' => $this->singleDriverService($config, $entry, $service),
      ],
      $this->durationResolver($config, $entry, $mode, $service, $selfBookable),
      $this->capacityRule($config, $entry, $mode),
      array_key_exists('quote_strategy', $entry) ? trim((string) $entry['quote_strategy']) : $service,
      $this->reviewResult($entry, $mode, $service, $listedManual),
    );
  }

  /**
   * The one element that carries a chosen arrival instant.
   *
   * Shell-level, not per-service: the pilot renders the same widget as Courier,
   * and two services writing different Slot elements is how a stale selection
   * survives a service change.
   */
  private function slotField($config): string {
    $field = trim((string) ($config->get('booking_profiles.selected_slot_field') ?? ''));
    return $field !== '' ? $field : 'dispatch_slot';
  }

  /**
   * The facts whose absence makes a request unfinished rather than refused.
   *
   * Derived from the mode by default rather than listed per service, so that
   * `self_bookable.require_declared_weight` keeps deciding whether weight is
   * required instead of being restated here where the two could disagree. A
   * Manual Review service has none: it is refused outright, and telling a
   * visitor to finish a question would be the wrong sentence.
   */
  private function requiredFacts($config, array $entry, BookingMode $mode, callable $element): array {
    if (array_key_exists('required_facts', $entry)) {
      $facts = [];
      foreach ($this->stringList($entry['required_facts']) as $fact) {
        $facts[$fact] = $element($fact);
      }
      return $facts;
    }

    if ($mode !== BookingMode::SelfBookable) {
      return [];
    }

    // A move request has no Delivery Mode to give; an unmapped fact resolves to
    // '' and is dropped by BookingProfile::requiredFactElements().
    $facts = [
      'service_type' => $element('service_type'),
      'delivery_mode' => $element('delivery_mode'),
    ];
    if ((bool) $config->get('self_bookable.require_declared_weight')) {
      $facts['declared_weight'] = $element('declared_weight');
    }

    return $facts;
  }

  /**
   * Which duration resolver can time this service.
   *
   * A declared entry ends the question, which is the point: Express Courier now
   * says it is timed by the courier resolver instead of being inferred from
   * `count(self_bookable.service_types) === 1`. That inference is kept only as
   * the fallback for a service with no entry, so nothing silently changes for a
   * configuration this task did not touch — but it no longer decides the shipped
   * case, and it can no longer break the moment a second service is opened.
   */
  private function durationResolver($config, array $entry, BookingMode $mode, string $service, array $selfBookable): string {
    if (array_key_exists('duration_resolver', $entry)) {
      return match (trim((string) $entry['duration_resolver'])) {
        BookingProfile::DURATION_COURIER => BookingProfile::DURATION_COURIER,
        BookingProfile::DURATION_MOVE => BookingProfile::DURATION_MOVE,
        default => BookingProfile::DURATION_NONE,
      };
    }

    if ($mode !== BookingMode::SelfBookable) {
      return BookingProfile::DURATION_NONE;
    }

    $courierParent = $this->stringList($config->get('self_bookable.delivery_modes')) !== []
      && in_array($service, $selfBookable, TRUE)
      && count($selfBookable) === 1;

    return $courierParent ? BookingProfile::DURATION_COURIER : BookingProfile::DURATION_MOVE;
  }

  /**
   * Whether one-driver handling is true by definition of this service.
   */
  private function singleDriverService($config, array $entry, string $service): bool {
    if (array_key_exists('single_driver_service', $entry)) {
      return (bool) $entry['single_driver_service'];
    }

    return $service !== '' && in_array(
      $service,
      $this->stringList($config->get('self_bookable.single_driver_service_types')),
      TRUE,
    );
  }

  /**
   * Whether this service competes for the shared resource, and which one.
   *
   * The resource itself is read from the existing `resource` mapping rather than
   * copied per service, because there is one calendar and a second copy of its
   * id or capacity would be a second booking authority. All the profile decides
   * is whether this service reaches it at all: a service that claims no capacity
   * writes no ledger row and cannot take grid time from one that does.
   */
  private function capacityRule($config, array $entry, BookingMode $mode): array {
    $claims = array_key_exists('claims_capacity', $entry)
      ? (bool) $entry['claims_capacity']
      : $mode === BookingMode::SelfBookable;

    return [
      'resource_id' => $claims ? trim((string) ($config->get('resource.id') ?? '')) : '',
      'capacity' => $claims ? max(0, (int) ($config->get('resource.capacity') ?? 0)) : 0,
      'claims_capacity' => $claims,
    ];
  }

  /**
   * How a refusal for this service is worded, and which reason it reports.
   *
   * The reason preserves a distinction the old pair of list lookups made and a
   * single mode flag would lose: a service the operator listed as Manual Review
   * reports ManualReview, and a service in no list at all reports
   * UnsupportedService. Those two produce different public sentences, so
   * flattening them would have changed copy for services this task must not
   * touch. An empty service type reports IncompleteFacts, because no service has
   * been chosen yet and the visitor is owed "finish the form", not "a person will
   * schedule this".
   */
  private function reviewResult(array $entry, BookingMode $mode, string $service, bool $listedManual): array {
    if ($mode === BookingMode::SelfBookable) {
      $reason = DispatchReason::Available;
    }
    elseif ($service === '') {
      $reason = DispatchReason::IncompleteFacts;
    }
    elseif ($listedManual || array_key_exists('mode', $entry)) {
      $reason = DispatchReason::ManualReview;
    }
    else {
      $reason = DispatchReason::UnsupportedService;
    }

    return [
      'policy' => trim((string) ($entry['review_policy'] ?? '')) === BookingProfile::REVIEW_ALWAYS
        ? BookingProfile::REVIEW_ALWAYS
        : BookingProfile::REVIEW_OFFER_ASSESSED,
      'message' => trim((string) ($entry['review_message'] ?? '')),
      'reason' => $reason,
    ];
  }

  /**
   * Trimmed, non-empty strings from a configuration value of unknown shape.
   *
   * Configuration arriving as a string, NULL or a nested array is a mistake, not
   * an emergency: read what can be read and ignore the rest, so a malformed key
   * narrows what is bookable instead of throwing inside a form render.
   */
  private function stringList(mixed $value): array {
    if (!is_array($value)) {
      return [];
    }

    $items = array_map(
      static fn ($item): string => is_scalar($item) ? trim((string) $item) : '',
      $value,
    );

    return array_values(array_filter($items, static fn (string $item): bool => $item !== ''));
  }

}
