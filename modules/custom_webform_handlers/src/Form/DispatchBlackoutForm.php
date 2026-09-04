<?php

namespace Drupal\custom_webform_handlers\Form;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\custom_webform_handlers\Dispatch\DispatchBlackout;
use Drupal\custom_webform_handlers\Dispatch\DispatchOperations;
use Drupal\custom_webform_handlers\Dispatch\DispatchReasonText;
use Drupal\custom_webform_handlers\Dispatch\LocalWallClock;
use Drupal\custom_webform_handlers\Dispatch\ResourceProfile;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Withholds a span of one Resource's day from booking, or revises one.
 *
 * The form owns the operator's experience and none of the policy. It checks that
 * what was typed names a real local moment and that the end follows the start,
 * because those are things an operator can fix on the spot and should be told
 * about before a page reload. Everything that depends on the schedule — whether
 * the Resource works those hours at all, and whether anything live already
 * occupies the span — is answered by the planner and the storage seam, under the
 * Resource Calendar Guard rows, and this form reports that answer rather than
 * anticipating it. A form that anticipated it would be a second copy of the rules
 * and would eventually disagree with the one that writes.
 *
 * The Resource is not a form field. It comes from the route on a revision and
 * from the board's link or the operating profile on a new Blackout, so there is
 * no input here that could move a closure onto a different vehicle, and no way to
 * name a Resource this installation does not operate.
 *
 * A revision is not an edit either. It is withdraw-then-insert inside one locked
 * transaction, so the Resource is never briefly open in a span the operator
 * believes is closed, and the withdrawn row stays as the audit trail.
 */
final class DispatchBlackoutForm extends FormBase {

  /**
   * The Blackout being revised, or NULL when a new one is being created.
   */
  private ?DispatchBlackout $blackout = NULL;

  /**
   * The Resource whose time is withheld.
   */
  private ?ResourceProfile $profile = NULL;

  public function __construct(
    private readonly DispatchOperations $operations,
    private readonly TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('custom_webform_handlers.dispatch_operations'),
      $container->get('datetime.time'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'custom_webform_handlers_dispatch_blackout';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $dispatch_blackout_id = NULL) {
    // The Resource is resolved before anything else, because it decides the zone
    // both typed times are read in. An identifier that names no Resource this
    // installation operates is a 404 rather than a fallback to the configured one:
    // an operator must not be shown a form that closes a different vehicle's day.
    $requested = (string) $this->getRequest()->query->get('resource', '');
    $this->profile = $this->operations->profile(is_string($requested) ? $requested : '');
    if ($this->profile === NULL) {
      throw new NotFoundHttpException();
    }

    $blackoutId = (int) $dispatch_blackout_id;
    if ($blackoutId > 0) {
      $this->blackout = $this->operations->blackout($blackoutId, $this->profile->resourceId);
      if ($this->blackout === NULL) {
        throw new NotFoundHttpException();
      }
    }

    $form['resource'] = [
      '#type' => 'item',
      '#title' => $this->t('Resource'),
      '#markup' => $this->t('@resource · times below are read in @zone, the Resource’s own zone.', [
        '@resource' => $this->profile->resourceId,
        '@zone' => $this->profile->timezone,
      ]),
    ];

    // A withdrawn Blackout has nothing to revise: a revision replaces an active
    // closure, and this one already stopped consuming capacity. It is still shown,
    // because it is the audit trail an operator came here to read.
    if ($this->blackout !== NULL && !$this->blackout->active) {
      $form['withdrawn'] = [
        '#theme' => 'item_list',
        '#title' => $this->t('This Blackout has already been withdrawn'),
        '#items' => [
          $this->t('It closed @from until @until and no longer withholds anything.', [
            '@from' => $this->blackout->localStart()->format('Y-m-d H:i'),
            '@until' => $this->blackout->localEnd()->format('H:i'),
          ]),
          $this->t('Withdrawn rows are kept so the history of a day stays readable. Create a new Blackout if the span needs closing again.'),
        ],
      ];
      $form['cancel'] = $this->cancelLink();

      return $form;
    }

    // A span the form cannot represent is not edited into something else. Both
    // times are read on one local date here, so a stored row crossing midnight —
    // which nothing this form writes can produce — is shown and left alone rather
    // than silently truncated to its first day.
    if ($this->blackout !== NULL
      && $this->blackout->localStart()->format('Y-m-d') !== $this->blackout->localEnd()->format('Y-m-d')) {
      $form['spans'] = [
        '#theme' => 'item_list',
        '#title' => $this->t('This Blackout crosses midnight'),
        '#items' => [
          $this->t('It closes @from until @until. This form works on one local date, so it will not rewrite that span.', [
            '@from' => $this->blackout->localStart()->format('Y-m-d H:i'),
            '@until' => $this->blackout->localEnd()->format('Y-m-d H:i'),
          ]),
          $this->t('Lift it and record the closures you want, one local date at a time.'),
        ],
      ];
      $form['cancel'] = $this->cancelLink();

      return $form;
    }

    $localToday = (new \DateTimeImmutable('@' . $this->time->getRequestTime()))
      ->setTimezone($this->profile->timezoneObject())
      ->format('Y-m-d');

    $form['local_date'] = [
      '#type' => 'date',
      '#title' => $this->t('Local date'),
      '#required' => TRUE,
      '#default_value' => $this->blackout === NULL ? $this->defaultDate($localToday) : $this->blackout->localDate(),
      '#description' => $this->t('The date on the Resource’s own calendar. Today there is @today.', [
        '@today' => $localToday,
      ]),
    ];

    // Plain text rather than a browser time control, and validated on the server
    // either way. Browsers disagree about whether a time input submits seconds,
    // and a Blackout boundary carrying a second nobody typed is a boundary nobody
    // can reason about afterwards.
    $form['start_time'] = [
      '#type' => 'textfield',
      '#title' => $this->t('From'),
      '#required' => TRUE,
      '#size' => 5,
      '#maxlength' => 5,
      '#default_value' => $this->blackout === NULL ? '' : $this->blackout->localStart()->format('H:i'),
      '#description' => $this->t('24-hour local time, as HH:MM.'),
    ];

    $form['end_time'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Until'),
      '#required' => TRUE,
      '#size' => 5,
      '#maxlength' => 5,
      '#default_value' => $this->blackout === NULL ? '' : $this->blackout->localEnd()->format('H:i'),
      '#description' => $this->t('Exclusive: work starting exactly at this time is unaffected.'),
    ];

    $form['reason'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Reason'),
      '#required' => TRUE,
      '#maxlength' => 255,
      '#default_value' => $this->blackout === NULL ? '' : $this->blackout->reason,
      '#description' => $this->t('Shown to staff on the board. Keep customer detail out of it.'),
    ];

    $form['actions'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->blackout === NULL ? $this->t('Withhold this time') : $this->t('Revise this Blackout'),
        '#button_type' => 'primary',
      ],
      'cancel' => $this->cancelLink(),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    if ($this->profile === NULL) {
      return;
    }

    $date = trim((string) $form_state->getValue('local_date'));
    if (!LocalWallClock::isDate($date)) {
      // A shape check alone accepts 2026-02-31, which PHP would roll forward into
      // March. A date that does not survive being reformatted is refused, never
      // corrected into a day the operator did not choose.
      $form_state->setErrorByName('local_date', $this->t('That is not a date on the calendar.'));

      return;
    }

    $start = $this->normalisedTime($form_state, 'start_time');
    $end = $this->normalisedTime($form_state, 'end_time');
    if ($start === NULL || $end === NULL) {
      return;
    }

    $zone = $this->profile->timezoneObject();
    $startAt = LocalWallClock::instant($date, $start, $zone);
    $endAt = LocalWallClock::instant($date, $end, $zone);

    // A local time this zone skips for daylight saving is refused here rather than
    // moved an hour, which is the other thing PHP would do willingly.
    if ($startAt === NULL) {
      $form_state->setErrorByName('start_time', $this->t('@time does not exist on @date in @zone.', [
        '@time' => $start,
        '@date' => $date,
        '@zone' => $this->profile->timezone,
      ]));
    }
    if ($endAt === NULL) {
      $form_state->setErrorByName('end_time', $this->t('@time does not exist on @date in @zone.', [
        '@time' => $end,
        '@date' => $date,
        '@zone' => $this->profile->timezone,
      ]));
    }

    if ($startAt !== NULL && $endAt !== NULL && $endAt <= $startAt) {
      $form_state->setErrorByName('end_time', $this->t('The end must come after the start. A Blackout is one span on one local date, so it cannot run past midnight.'));
    }
  }

  /**
   * Reads one typed time, normalising 9:00 to 09:00 without accepting nonsense.
   *
   * The shape rule itself lives in LocalWallClock, so this form cannot drift from
   * the Replan form about what a typed time is. What belongs here is only the
   * sentence the operator reads and which field it lands on.
   */
  private function normalisedTime(FormStateInterface $form_state, string $key): ?string {
    $normalised = LocalWallClock::normaliseTime((string) $form_state->getValue($key));
    if ($normalised === NULL) {
      $form_state->setErrorByName($key, $this->t('Give a 24-hour local time as HH:MM, for example 09:30.'));

      return NULL;
    }

    $form_state->setValue($key, $normalised);

    return $normalised;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    if ($this->profile === NULL) {
      return;
    }

    // The revision path is expressed by naming the Blackout being replaced, not by
    // withdrawing it here and inserting a new one afterwards. Two calls could not
    // be made atomic from a submit handler, and the gap between them is a moment
    // when the Resource is open in a span the operator believes is closed.
    $result = $this->operations->withholdTime(
      $this->profile->resourceId,
      trim((string) $form_state->getValue('local_date')),
      trim((string) $form_state->getValue('start_time')),
      trim((string) $form_state->getValue('end_time')),
      trim((string) $form_state->getValue('reason')),
      $this->blackout?->blackoutId,
    );

    if ($result->isScheduled()) {
      $this->messenger()->addStatus($result->withdrewBlackoutId === NULL
        ? $this->t('That time is now withheld from booking on @resource.', ['@resource' => $this->profile->resourceId])
        : $this->t('The Blackout has been revised on @resource. The version it replaced is kept as history.', ['@resource' => $this->profile->resourceId]));
      $form_state->setRedirect('custom_webform_handlers.dispatch_blackouts', [], [
        'query' => ['resource' => $this->profile->resourceId],
      ]);

      return;
    }

    // Nothing was written. The refusal is reported in the planner's own terms and
    // the form is rebuilt with the operator's input intact, so the span can be
    // adjusted without typing it again.
    $this->messenger()->addError(DispatchReasonText::staff($result->reason));
    if ($result->conflictingReservationIds !== []) {
      $this->messenger()->addError($this->t('Reservations @ids already own part of that span. Free or move each one first, then withhold the time.', [
        '@ids' => implode(', ', $result->conflictingReservationIds),
      ]));
    }
    elseif (!DispatchReasonText::anotherTimeMayWork($result->reason)) {
      $this->messenger()->addWarning($this->t('Changing the span will not help with this refusal.'));
    }

    $form_state->setRebuild(TRUE);
  }

  /**
   * The way back to the Blackout listing, carrying the Resource with it.
   *
   * Cancelling returns to the list for the same Resource rather than to whichever
   * one configuration names first, because an operator who came here from a
   * vehicle's board should land back on that vehicle's closures.
   */
  private function cancelLink(): array {
    $query = $this->profile === NULL ? [] : ['resource' => $this->profile->resourceId];

    return [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => Url::fromRoute('custom_webform_handlers.dispatch_blackouts', [], ['query' => $query]),
      '#attributes' => ['class' => ['button']],
    ];
  }

  /**
   * The date a new Blackout opens on.
   *
   * The board links here from one day's heading, so a date offered in the query
   * fills the field in — it saves retyping the day the operator is already looking
   * at. It is validated before it is trusted, and anything else falls back to today
   * in the Resource's own zone rather than the site's.
   */
  private function defaultDate(string $localToday): string {
    $requested = $this->getRequest()->query->get('date', '');
    $requested = is_string($requested) ? trim($requested) : '';

    return LocalWallClock::isDate($requested) ? $requested : $localToday;
  }

}
