<?php

namespace Drupal\custom_webform_handlers\Form;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\custom_webform_handlers\Dispatch\DispatchBlackout;
use Drupal\custom_webform_handlers\Dispatch\DispatchOperations;
use Drupal\custom_webform_handlers\Dispatch\ResourceProfile;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Lifts one Blackout, returning its span to booking.
 *
 * Withdrawing is the widening operation on this screen and the only one, which is
 * why it is a confirmation step rather than an operation link: the span it releases
 * becomes offerable to the public again the moment it commits, and an operator
 * should read the interval they are reopening before that happens.
 *
 * The row is not deleted. It is marked withdrawn, keeps its reason and its
 * timestamps, and stops consuming capacity — so the record of who closed which
 * afternoon, and that it was later reopened, survives the reopening. A delete
 * would leave the day looking as though it had never been withheld at all.
 *
 * Nothing is decided here. The store withdraws the row under the same Resource
 * Calendar Guard every other mutation locks, so a withdrawal cannot interleave with
 * a booking landing in the span being freed.
 */
final class DispatchBlackoutWithdrawForm extends ConfirmFormBase {

  /**
   * The Blackout being lifted.
   */
  private ?DispatchBlackout $blackout = NULL;

  /**
   * The Resource whose time it withholds.
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
    return 'custom_webform_handlers_dispatch_blackout_withdraw';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $dispatch_blackout_id = NULL) {
    // Same order as the revision form, for the same reason: the Resource decides
    // the zone the stored interval is described in, so it is resolved before the
    // row is read. A Blackout belonging to another Resource answers NULL rather
    // than being rendered against these working hours.
    $requested = $this->getRequest()->query->get('resource', '');
    $this->profile = $this->operations->profile(is_string($requested) ? $requested : '');
    if ($this->profile === NULL) {
      throw new NotFoundHttpException();
    }

    $this->blackout = $this->operations->blackout((int) $dispatch_blackout_id, $this->profile->resourceId);
    if ($this->blackout === NULL) {
      throw new NotFoundHttpException();
    }

    $form = parent::buildForm($form, $form_state);

    $rows = [
      [$this->t('Resource'), $this->blackout->resourceId],
      [$this->t('Local date'), $this->blackout->localDate()],
      [
        $this->t('Withheld'),
        $this->t('@from until @until (@zone)', [
          '@from' => $this->blackout->localStart()->format('H:i'),
          '@until' => $this->blackout->localEnd()->format('H:i'),
          '@zone' => $this->blackout->timezone,
        ]),
      ],
      [$this->t('Reason'), $this->blackout->reason],
      [
        $this->t('State'),
        $this->blackout->active ? $this->t('In force') : $this->t('Already withdrawn'),
      ],
    ];

    $form['blackout'] = [
      '#type' => 'table',
      '#header' => [$this->t('Field'), $this->t('Value')],
      '#rows' => $rows,
      '#weight' => -10,
    ];

    // An already-withdrawn Blackout has nothing to lift, and a confirm button that
    // does nothing is worse than no button: an operator would click it and believe
    // something changed. The page stays readable as the audit record it is.
    if (!$this->blackout->active) {
      unset($form['actions']['submit']);
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    if ($this->blackout === NULL) {
      return $this->t('Lift this Blackout?');
    }

    return $this->t('Return @from–@until on @date to booking?', [
      '@from' => $this->blackout->localStart()->format('H:i'),
      '@until' => $this->blackout->localEnd()->format('H:i'),
      '@date' => $this->blackout->localDate(),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    if ($this->blackout === NULL) {
      return parent::getDescription();
    }

    if (!$this->blackout->active) {
      return $this->t('This Blackout has already been withdrawn. It is shown because withdrawn rows are kept as the history of the day.');
    }

    if ($this->blackout->isPast($this->time->getRequestTime())) {
      return $this->t('This span has already passed, so lifting it frees no capacity anyone can book. The row will be marked withdrawn and kept as history.');
    }

    return $this->t('The span becomes offerable again as soon as this commits, including to the public. The row is kept and marked withdrawn rather than deleted, so the record of the closure survives.');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Lift this Blackout');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    $query = $this->profile === NULL ? [] : ['resource' => $this->profile->resourceId];

    return Url::fromRoute('custom_webform_handlers.dispatch_blackouts', [], ['query' => $query]);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    if ($this->blackout === NULL || $this->profile === NULL) {
      return;
    }

    // The state is re-read by the store inside its own lock. This check only keeps
    // a stale confirmation page from reporting success for a row somebody else
    // already lifted while it sat open.
    if (!$this->blackout->active) {
      $this->messenger()->addWarning($this->t('That Blackout had already been withdrawn, so nothing changed.'));
    }
    elseif ($this->operations->withdrawBlackout($this->blackout->blackoutId)) {
      $this->messenger()->addStatus($this->t('@from–@until on @date is open for booking again.', [
        '@from' => $this->blackout->localStart()->format('H:i'),
        '@until' => $this->blackout->localEnd()->format('H:i'),
        '@date' => $this->blackout->localDate(),
      ]));
    }
    else {
      $this->messenger()->addError($this->t('That Blackout could not be lifted, so nothing changed. Reload the list to see what it holds now.'));
    }

    $form_state->setRedirect('custom_webform_handlers.dispatch_blackouts', [], [
      'query' => ['resource' => $this->profile->resourceId],
    ]);
  }

}
