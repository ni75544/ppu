<?php

namespace Drupal\custom_webform_handlers\Distance;

/**
 * Represents a safe, classified route-provider failure.
 *
 * Two vocabularies live here, and they are deliberately not the same one.
 *
 * The *classification* is internal. It is what a provider chain needs in order to
 * decide whether another provider could do better: an address the customer has
 * not finished typing will fail identically everywhere, while one provider's
 * coverage gap or spent allowance says nothing at all about the next provider's.
 * Only server code reads classification(); nothing renders it.
 *
 * The *public code* is what a browser is allowed to see, and it is still the same
 * four strings the preview endpoint has always returned, because
 * js/distance_calculator.js switches on them. Several classifications share one
 * public code on purpose: a timeout, a spent allowance and an unparseable body are
 * all "the provider did not answer" from outside, and telling a visitor which one
 * it was would describe our infrastructure to them.
 *
 * Nothing here can carry an upstream message. The named constructors accept a
 * classification and, at most, an allowlisted provider id and a retry delay —
 * there is no parameter through which a response body, a request URL or a key
 * could arrive. The original constructor still accepts a $previous for the one
 * existing throw site that chains a transport exception; adapters written from
 * Task 20E onwards use the named constructors instead, so a Guzzle message
 * carrying a signed URL never becomes part of this exception's chain.
 */
final class DistanceProviderException extends \RuntimeException {

  /**
   * The address could not be used: incomplete, ambiguous, or not an address.
   *
   * Terminal. Every provider would reach the same conclusion, so a chain that
   * sees this must stop rather than spend another provider's allowance on it.
   */
  public const ADDRESS_UNUSABLE = 'address_unusable';

  /**
   * The provider does not cover the area the addresses fall in.
   */
  public const OUTSIDE_COVERAGE = 'outside_coverage';

  /**
   * The provider covers the area and found no drivable route between the points.
   */
  public const NO_ROUTE = 'no_route';

  /**
   * The provider did not answer inside its own timeout.
   */
  public const TIMEOUT = 'timeout';

  /**
   * The provider refused the request for rate reasons and may say when to retry.
   */
  public const THROTTLED = 'throttled';

  /**
   * The credential or endpoint this provider needs is absent or rejected.
   *
   * Disables the provider for the rest of the request cycle: a missing token or a
   * rejected key fails identically on the next attempt, so retrying it only adds
   * latency to a request that is already degraded.
   */
  public const NOT_CONFIGURED = 'not_configured';

  /**
   * The provider answered with something this module cannot read as a route.
   */
  public const MALFORMED_RESPONSE = 'malformed_response';

  /**
   * The configured request allowance for this provider and period is spent.
   *
   * A refusal that never left the server. It is not a provider failure and must
   * not be counted as one against a circuit breaker.
   */
  public const BUDGET_EXHAUSTED = 'budget_exhausted';

  /**
   * A failure the thrower did not classify.
   *
   * This exists because the legacy 'provider_request_failed' message covers a
   * timeout, a 5xx and a transport error without distinguishing them, and
   * inventing one of those three for it would be a guess presented as a fact.
   * Treated as retryable on the next provider, like the failures it stands in for.
   */
  public const UNCLASSIFIED = 'unclassified';

  /**
   * Public code: the request did not describe two usable addresses.
   */
  public const CODE_INVALID_INPUT = 'invalid_route_input';

  /**
   * Public code: this site cannot currently ask any provider.
   */
  public const CODE_NOT_CONFIGURED = 'provider_not_configured';

  /**
   * Public code: a provider was asked and did not produce an answer.
   */
  public const CODE_REQUEST_FAILED = 'provider_request_failed';

  /**
   * Public code: the addresses are usable and no route was returned for them.
   */
  public const CODE_NOT_FOUND = 'route_not_found';

  /**
   * Maps every classification onto the code a browser may be shown.
   *
   * The four values are a closed set. Adding a classification must not add a fifth
   * public string, because that string would reach a switch statement in
   * js/distance_calculator.js that was written against these four.
   */
  private const PUBLIC_CODES = [
    self::ADDRESS_UNUSABLE => self::CODE_INVALID_INPUT,
    self::OUTSIDE_COVERAGE => self::CODE_NOT_FOUND,
    self::NO_ROUTE => self::CODE_NOT_FOUND,
    self::TIMEOUT => self::CODE_REQUEST_FAILED,
    self::THROTTLED => self::CODE_REQUEST_FAILED,
    self::NOT_CONFIGURED => self::CODE_NOT_CONFIGURED,
    self::MALFORMED_RESPONSE => self::CODE_REQUEST_FAILED,
    self::BUDGET_EXHAUSTED => self::CODE_REQUEST_FAILED,
    self::UNCLASSIFIED => self::CODE_REQUEST_FAILED,
  ];

  /**
   * Recovers a classification from the message the existing adapter throws.
   *
   * Google's adapter is not rewired until Task 20E/20F, so its six throw sites
   * still pass one of the four legacy strings. Reading them back here means a
   * chain can classify a failure it did not raise itself.
   */
  private const LEGACY_CLASSIFICATIONS = [
    self::CODE_INVALID_INPUT => self::ADDRESS_UNUSABLE,
    self::CODE_NOT_CONFIGURED => self::NOT_CONFIGURED,
    self::CODE_NOT_FOUND => self::NO_ROUTE,
    self::CODE_REQUEST_FAILED => self::UNCLASSIFIED,
  ];

  private string $classification;

  private ?string $providerId = NULL;

  private ?int $retryAfter = NULL;

  /**
   * {@inheritdoc}
   *
   * Kept byte-compatible with the six existing throw sites. $previous survives
   * only for those: it is the seam through which a transport exception's message
   * can reach a log, so classified failures raised from Task 20E onwards use the
   * named constructors below, which have no such parameter.
   */
  public function __construct(string $message, int $code = 0, ?\Throwable $previous = NULL) {
    parent::__construct($message, $code, $previous);
    $this->classification = self::LEGACY_CLASSIFICATIONS[$message] ?? self::UNCLASSIFIED;
  }

  /**
   * Raises a classified failure.
   *
   * @param string $classification
   *   One of this class's classification constants. An unrecognized value is
   *   recorded as UNCLASSIFIED rather than trusted, so a caller cannot invent a
   *   classification that the chain has no rule for.
   * @param string|null $providerId
   *   The provider that failed, which must be one of the allowlisted routing
   *   provider ids. Anything else is dropped — an id is the only provider detail
   *   this exception carries, and it must never become a hostname.
   * @param int|null $retryAfter
   *   Seconds the provider asked to be left alone for, when it said so.
   */
  public static function classified(string $classification, ?string $providerId = NULL, ?int $retryAfter = NULL): self {
    if (!isset(self::PUBLIC_CODES[$classification])) {
      $classification = self::UNCLASSIFIED;
    }
    $exception = new self(self::PUBLIC_CODES[$classification]);
    $exception->classification = $classification;
    if ($providerId !== NULL && in_array($providerId, RoutingPolicy::PROVIDERS, TRUE)) {
      $exception->providerId = $providerId;
    }
    if ($retryAfter !== NULL) {
      $exception->retryAfter = max(0, $retryAfter);
    }

    return $exception;
  }

  /**
   * Returns the internal classification of this failure.
   */
  public function classification(): string {
    return $this->classification;
  }

  /**
   * Returns the only string this failure may be shown as.
   *
   * Safer than getMessage() by construction: it can only ever be one of four
   * fixed tokens, whereas a message is whatever the thrower passed. The preview
   * endpoint still returns getMessage() — moving it onto this accessor belongs
   * with the chain cutover in Task 20G, so that the change of source and the
   * change of thrower land together.
   */
  public function publicCode(): string {
    return self::PUBLIC_CODES[$this->classification];
  }

  /**
   * Returns the allowlisted id of the provider that failed, when known.
   */
  public function providerId(): ?string {
    return $this->providerId;
  }

  /**
   * Returns the delay the provider asked for, when it asked for one.
   */
  public function retryAfterSeconds(): ?int {
    return $this->retryAfter;
  }

  /**
   * Whether trying another provider is pointless.
   */
  public function isTerminal(): bool {
    return $this->classification === self::ADDRESS_UNUSABLE;
  }

  /**
   * Whether a chain may fall through to the next eligible provider.
   */
  public function mayAdvanceChain(): bool {
    return !$this->isTerminal();
  }

  /**
   * Whether this provider should be skipped for the rest of the request cycle.
   *
   * A rejected credential or an absent endpoint is not a transient condition, so
   * a chain that has already met one on this provider gains nothing by asking it
   * again a few milliseconds later.
   */
  public function disablesProvider(): bool {
    return $this->classification === self::NOT_CONFIGURED;
  }

  /**
   * Whether this failure is evidence that the provider itself is unhealthy.
   *
   * Three classifications are, and they are named rather than excepted. Written the
   * other way round — "everything but an unusable address and a spent allowance" —
   * this method counted every classification added after it as a fault, and four of
   * them are not one. An allowlist makes the default answer no and forces the question
   * to be asked again the next time a classification is added.
   *
   * Coverage and no-route outcomes are the provider answering correctly. "I do not
   * cover that area" and "there is no road between these two points" are facts about
   * the request, reported by a provider working exactly as intended. A breaker that
   * opened on them would take a healthy provider out of the chain for having been
   * asked a question it was entitled to refuse — and it would open hardest on the
   * provider whose coverage is honestly declared.
   *
   * Throttling is already answered, and answered better, by the cooldown and the
   * provider's own Retry-After, which retryAfterSeconds() carries. The breaker has
   * nothing to add there, and counting a throttle as ill health would blame the
   * provider for this site's request rate, which is our decision and not its fault.
   *
   * A configuration or authentication failure is dealt with separately and more
   * firmly: disablesProvider() removes the provider for the rest of the request cycle.
   * It is a fault in this site's settings, so a breaker would be measuring the wrong
   * thing, and its cooldown would expire while the credential was still wrong.
   *
   * A spent allowance is a refusal this module made and an unusable address is the
   * customer's; neither is the provider's doing.
   *
   * What is left is transport and upstream health — a request that did not answer in
   * time, a response that could not be parsed, and an unclassified failure. The last
   * one counts because a failure nobody has classified yet is most often a new way for
   * a provider to be broken, and of the two ways to be wrong about that, opening a
   * breaker on a healthy provider is the cheaper one.
   */
  public function indicatesProviderFault(): bool {
    return in_array($this->classification, [
      self::TIMEOUT,
      self::MALFORMED_RESPONSE,
      self::UNCLASSIFIED,
    ], TRUE);
  }

}
