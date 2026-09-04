<?php

namespace Drupal\custom_webform_handlers\Dispatch\Notify;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\custom_webform_handlers\Dispatch\DeliveryMode;
use Drupal\custom_webform_handlers\Dispatch\DispatchRequestFactory;
use Drupal\webform\WebformSubmissionInterface;
use Psr\Log\LoggerInterface;

/**
 * Turns one committed fact into the text a person reads.
 *
 * The load-bearing decision is the placeholder vocabulary, and it is a privacy
 * boundary rather than a convenience. Every value this class can substitute is either
 * an instant, an identifier, a value from a closed enum, or a string a site
 * administrator typed into the settings form. Not one of them is a customer's name,
 * address, telephone number or answer. So a template cannot be written — by accident or
 * by an administrator experimenting — that puts one customer's detail into any message,
 * and the staff copy of an event is safe by construction rather than by review.
 *
 * The single piece of customer data involved is the address the customer copy is sent
 * to. It is read here, handed to the dispatcher, and dropped; it reaches no template,
 * no log row and no board.
 *
 * Composing never throws. A missing template, an unconfigured recipient or a submission
 * that has since been deleted all produce a skipped message naming the reason, because
 * the worker downstream has a real message to account for either way and "why nothing
 * was sent" is the more useful thing to record than an exception in the log.
 */
final class DispatchNotificationComposer {

  /**
   * Notification settings, including recipients and templates.
   */
  const SETTINGS = 'custom_webform_handlers.notifications';

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly DispatchRequestFactory $requestFactory,
    private readonly LanguageManagerInterface $languageManager,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Composes one audience's copy of one event.
   *
   * @param \Drupal\custom_webform_handlers\Dispatch\Notify\DispatchNotification $notification
   *   The committed fact.
   * @param \Drupal\custom_webform_handlers\Dispatch\Notify\DispatchAudience $audience
   *   Who this copy is for.
   *
   * @return \Drupal\custom_webform_handlers\Dispatch\Notify\DispatchMessage
   *   Something to send, or a skipped message naming why not.
   */
  public function compose(DispatchNotification $notification, DispatchAudience $audience): DispatchMessage {
    $settings = $this->configFactory->get(self::SETTINGS);

    $template = $this->template($settings, $notification->event, $audience);
    if ($template === NULL) {
      return DispatchMessage::skipped($audience, 'no_template');
    }

    // Loaded once and shared between the recipient lookup and the substitutions, so a
    // customer copy costs one entity load rather than two.
    $submission = $this->loadSubmission($notification);

    $recipient = $audience === DispatchAudience::Customer
      ? $this->customerAddress($settings, $submission)
      : trim((string) $settings->get('recipients.staff'));

    if ($recipient === '') {
      return DispatchMessage::skipped($audience, 'no_recipient');
    }

    $replacements = $this->replacements($notification, $submission, $settings);

    return DispatchMessage::to(
      $audience,
      $recipient,
      $this->langcode($submission, $audience),
      strtr($template['subject'], $replacements),
      strtr($template['body'], $replacements),
    );
  }

  /**
   * The subject and body configured for one event and audience, or NULL.
   *
   * Both must be present and non-empty. A half-configured template is treated as no
   * template: a subject with an empty body would be mailed as a blank message, and a
   * blank message from a moving company is worse than silence.
   *
   * @return array{subject: string, body: string}|null
   *   The template, or NULL when it is unusable.
   */
  private function template(ImmutableConfig $settings, DispatchEvent $event, DispatchAudience $audience): ?array {
    $template = $settings->get('templates.' . $event->value . '.' . $audience->value);
    if (!is_array($template)) {
      return NULL;
    }

    $subject = trim((string) ($template['subject'] ?? ''));
    $body = trim((string) ($template['body'] ?? ''));

    if ($subject === '' || $body === '') {
      return NULL;
    }

    return ['subject' => $subject, 'body' => $body];
  }

  /**
   * Loads the submission a payload names, or NULL.
   *
   * NULL is an ordinary outcome, not an error: submissions are purgeable, and a
   * confirmation queued shortly before a purge names a row that is legitimately gone.
   * The load failure is logged by exception type only — a broken storage layer is worth
   * knowing about, and the identifiers are all that is safe to write to a log.
   */
  private function loadSubmission(DispatchNotification $notification): ?WebformSubmissionInterface {
    if (!$notification->hasSubmissionReference()) {
      return NULL;
    }

    try {
      $submission = $this->entityTypeManager
        ->getStorage('webform_submission')
        ->load($notification->submissionId);
    }
    catch (\Throwable $exception) {
      $this->logger->error('A dispatch notification could not load submission @submission: @type', [
        '@submission' => $notification->submissionId,
        '@type' => get_class($exception),
      ]);

      return NULL;
    }

    if (!$submission instanceof WebformSubmissionInterface) {
      return NULL;
    }

    // The payload names the Webform it was queued against. A submission whose Webform
    // does not match is not the row this event is about, and reading contact data out of
    // it would mean mailing somebody else.
    return $submission->getWebform()->id() === $notification->webformId ? $submission : NULL;
  }

  /**
   * The customer's own address, read from the element the site nominated.
   *
   * Read straight out of the submission rather than from any cached or copied place,
   * and only from the one element an administrator named. There is no fallback to the
   * site address and no fallback to the account that happened to submit: a confirmation
   * that cannot find the customer's address is not sent, because the alternative is
   * mailing somebody else's arrival window to whoever the fallback resolved to.
   */
  private function customerAddress(ImmutableConfig $settings, ?WebformSubmissionInterface $submission): string {
    $element = trim((string) $settings->get('recipients.customer_email_field'));
    if ($element === '' || $submission === NULL) {
      return '';
    }

    $value = $submission->getElementData($element);
    if (!is_string($value)) {
      // A multi-value or composite element is not an address. Guessing which part of it
      // to mail is exactly the kind of guess that mails the wrong person.
      return '';
    }

    $value = trim($value);

    // One address, and a real one. A comma is how a second recipient would be smuggled
    // into a customer copy through form input, so a value carrying one is refused
    // outright rather than split.
    if ($value === '' || str_contains($value, ',') || filter_var($value, FILTER_VALIDATE_EMAIL) === FALSE) {
      return '';
    }

    return $value;
  }

  /**
   * The language to render site strings in for one audience.
   *
   * A customer reads their own submission's language; staff read the site's. Neither is
   * negotiated from a mail header, because there is no request here — this runs on a
   * queue worker with no browser attached.
   */
  private function langcode(?WebformSubmissionInterface $submission, DispatchAudience $audience): string {
    if ($audience === DispatchAudience::Customer && $submission !== NULL) {
      return $submission->language()->getId();
    }

    return $this->languageManager->getDefaultLanguage()->getId();
  }

  /**
   * The fixed placeholder vocabulary, with this event's values.
   *
   * Fixed is the point. A template may use these and nothing else, so the worst a
   * mistaken template can do is leave a bracketed word un-substituted in a message —
   * never disclose something. Read the class docblock before adding one: every entry
   * here has to be an instant, an identifier, an enum value, or administrator-authored
   * text, and a customer's own answer qualifies as none of those.
   *
   * @return array<string, string>
   *   Placeholder to replacement.
   */
  private function replacements(
    DispatchNotification $notification,
    ?WebformSubmissionInterface $submission,
    ImmutableConfig $settings,
  ): array {
    $unknown = (string) new TranslatableMarkup('not recorded');
    $promised = $notification->pickupArrival !== NULL;
    // Checked separately from the arrival on purpose. A Blackout has an interval and no
    // arrival — nobody is being collected — so a template that names the closed hours
    // must not read "not recorded" merely because there is no pickup to promise.
    $interval = $notification->windowStart !== NULL && $notification->windowEnd !== NULL;

    return [
      '[event]' => (string) DispatchNotificationText::event($notification->event),
      '[resource]' => $notification->resourceId,
      '[timezone]' => $notification->timezone !== '' ? $notification->timezone : 'UTC',
      '[date]' => $promised ? $this->localise($notification->pickupArrival, 'D j M Y', $notification->timezone) : $unknown,
      '[arrival]' => $promised ? $this->localise($notification->pickupArrival, 'D j M Y, g:i a', $notification->timezone) : $unknown,
      '[arrival_window]' => $interval
        ? $this->localise($notification->windowStart, 'g:i a', $notification->timezone)
          . ' – ' . $this->localise($notification->windowEnd, 'g:i a', $notification->timezone)
        : $unknown,
      '[window_date]' => $interval
        ? $this->localise($notification->windowStart, 'D j M Y', $notification->timezone)
        : $unknown,
      '[delivery_mode]' => $this->deliveryMode($submission),
      '[reservation]' => $notification->dispatchReservationId === NULL
        ? $unknown
        : (string) $notification->dispatchReservationId,
      '[superseded]' => $notification->supersededReservationId === NULL
        ? $unknown
        : (string) $notification->supersededReservationId,
      '[submission]' => $notification->submissionId === NULL
        ? $unknown
        : (string) $notification->submissionId,
      '[webform]' => $notification->webformId ?? $unknown,
      '[detail]' => $notification->detail ?? '',
      '[contact]' => trim((string) $settings->get('recipients.contact_line')),
      '[site_name]' => trim((string) $this->configFactory->get('system.site')->get('name')),
    ];
  }

  /**
   * How the delivery runs, in words a customer recognises.
   *
   * Read back through DispatchRequestFactory rather than from a stored copy, so it is
   * the same mapping the planner used when it decided the job was eligible. The value
   * comes out of a closed enum, which is why it is safe to put in a message: there is no
   * path by which a customer's free text becomes this string.
   */
  private function deliveryMode(?WebformSubmissionInterface $submission): string {
    if ($submission === NULL) {
      return (string) new TranslatableMarkup('not recorded');
    }

    $request = $this->requestFactory->fromSubmission($submission);
    $mode = $request?->deliveryMode;

    if ($mode === NULL) {
      return (string) new TranslatableMarkup('not recorded');
    }

    // Matched into a variable and cast on the next line rather than casting the match
    // itself. Identical behaviour; the split is for the AST tooling this repository
    // takes its dependency evidence from, which does not parse a cast applied straight
    // to a match expression and drops the rest of the file when it meets one.
    $label = match ($mode) {
      DeliveryMode::Send => new TranslatableMarkup('handed over at the pickup'),
      DeliveryMode::Receive => new TranslatableMarkup('collected on your behalf at the pickup'),
      DeliveryMode::StorePickup => new TranslatableMarkup('collected from the store counter'),
    };

    return (string) $label;
  }

  /**
   * Formats one instant in the operating timezone the event was captured with.
   *
   * The timezone travels with the payload rather than being read now, so a profile
   * edited between the booking and the mail cannot reword a promise that has already
   * been made. An unusable name falls back to UTC, which is the same thing
   * DispatchReservationReleaseForm does with the same reasoning: a wrong-but-labelled
   * time beats a fatal error in a message somebody is waiting for.
   */
  private function localise(int $timestamp, string $format, string $timezone): string {
    $moment = new \DateTimeImmutable('@' . $timestamp);

    try {
      $zone = new \DateTimeZone($timezone === '' ? 'UTC' : $timezone);
    }
    catch (\Exception) {
      $zone = new \DateTimeZone('UTC');
    }

    return $moment->setTimezone($zone)->format($format);
  }

}
