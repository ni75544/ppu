<?php

namespace Drupal\custom_webform_handlers\Form;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\PageCache\ResponsePolicy\KillSwitch;
use Drupal\Core\Url;
use Drupal\custom_webform_handlers\Checkout\DispatchCheckoutStarter;
use Drupal\custom_webform_handlers\Review\ReviewManagerInterface;
use Drupal\custom_webform_handlers\Review\ReviewStatus;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Where a customer accepts a staff-priced quote, and the only place they can.
 *
 * This is a form and not a link, and that is the whole point of it. Accepting an
 * offer opens a Commerce order and takes a Hold on the Resource's day, so it is a
 * mutation; a mutation reachable by GET is one a prefetching browser, a link scanner,
 * an antivirus proxy, or a mail client can perform simply by looking at a page. The
 * GET here renders the figure and a button. Only the POST acts.
 *
 * What it holds is a credential, not an amount. The tracking code arrives in the
 * path; the price is read from the Review Request row a member of staff wrote, and
 * locked by QuoteSnapshot, whose constructor is private. There is no field on this
 * form that names a figure, no query parameter that could, and no code path by which
 * a posted value becomes a total — which is CONTEXT.md's rule that the browser may
 * display a price but never sets one, kept true at the one place a customer submits
 * something that leads to a charge.
 *
 * The authority for "may this be paid" is asked twice, on purpose. buildForm() asks
 * the publishing question, so a request that is not payable renders as its own status
 * with no button; submitForm() asks the authorising question again against the row as
 * it stands at that instant, because an offer can be withdrawn, paid, or lapse while
 * the page is open. The second ask is the one that counts, and it is the reason the
 * first one being stale is harmless.
 *
 * There is no CSRF token on this form for an anonymous customer — core issues one
 * only to an authenticated account — and none is needed. Forging this POST requires
 * knowing the URL, the URL contains the 192-bit code, and anybody holding the code
 * can simply pay the quote themselves. The credential is the anti-forgery property.
 */
final class ReviewPaymentForm extends FormBase {

  /**
   * The status this build published, kept for the message a refusal prints.
   */
  private ?ReviewStatus $status = NULL;

  /**
   * The raw tracking code this request arrived with.
   *
   * Held on the object rather than in a form element, which is the same reason the
   * module's other confirm forms hold their subject this way: the same form instance
   * serves build, validate, and submit within one request, so nothing has to be echoed
   * into the HTML and posted back. A hidden field carrying this code would print the
   * credential into the page's source, and the page's source is the one place a
   * forwarded link is read from.
   */
  private string $code = '';

  public function __construct(
    private readonly ReviewManagerInterface $reviews,
    private readonly DispatchCheckoutStarter $checkout,
    private readonly TimeInterface $time,
    private readonly KillSwitch $killSwitch,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get(ReviewManagerInterface::class),
      $container->get('custom_webform_handlers.dispatch_checkout_starter'),
      $container->get('datetime.time'),
      $container->get('page_cache_kill_switch'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'custom_webform_handlers_review_payment';
  }

  /**
   * {@inheritdoc}
   *
   * @param string|null $code
   *   The tracking code, from the route. Bounded by the route's own pattern and
   *   normalised again by the credential before it is hashed.
   */
  public function buildForm(array $form, FormStateInterface $form_state, $code = NULL) {
    // Triggered before the lookup for the same reason the tracker does it: a hit and
    // a miss must be indistinguishable from outside, and "one of them was cached" is
    // a difference an enumerator can see.
    $this->killSwitch->trigger();
    $this->code = is_string($code) ? $code : '';

    $this->status = $this->reviews->trackByCode($this->code);
    if ($this->status === NULL) {
      // The same bare 404 an unknown code gets from the tracker, thrown from the same
      // exception with the same empty message.
      throw new NotFoundHttpException();
    }

    $form['#cache'] = ['max-age' => 0];

    $form['headline'] = [
      '#type' => 'html_tag',
      '#tag' => 'h2',
      '#value' => $this->status->headline,
    ];

    // Not payable: withdrawn, already paid, lapsed, or never priced. The page says so
    // in the customer's own wording and offers no button, rather than offering one
    // that would be refused a moment later.
    if (!$this->status->isPayable()) {
      $form['detail'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->status->detail,
      ];
      $form['back'] = $this->trackerLink($this->t('Back to your quote'));

      return $form;
    }

    return $this->offer($form);
  }

  /**
   * The figure, what paying it does, and the button that does it.
   *
   * The amount is printed from the status the manager formatted, so this form does not
   * decide how money reads and never holds it as a number it could round. There is no
   * editable field anywhere in here: the only input this form takes is the fact that
   * the button was pressed.
   */
  private function offer(array $form): array {
    $form['quote'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Total to pay: @amount', ['@amount' => $this->status->quote]),
    ];

    if ($this->status->serviceSummary !== '') {
      $form['summary'] = [
        '#prefix' => '<p>',
        '#plain_text' => $this->status->serviceSummary,
        '#suffix' => '</p>',
      ];
    }

    $form['what_happens'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Continuing takes you to a secure payment page. Your booking is confirmed once payment completes; nothing is held in our schedule until then.'),
    ];

    $form['actions'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Continue to payment'),
        '#button_type' => 'primary',
      ],
      'cancel' => $this->trackerLink($this->t('Cancel')),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   *
   * Re-authorises against the row as it stands, then hands an approved value object
   * to the checkout starter. Note the direction of the handoff: this form reads the
   * request and passes it; the starter never holds the review manager and cannot read
   * one by code, so the ability to turn a credential into a payable offer lives in
   * exactly one method of one class.
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $request = $this->reviews->payableByCode($this->code);
    if ($request === NULL) {
      // Withdrawn, lapsed, or already paid between the page loading and the button
      // being pressed. The tracker will say which, in the customer's own wording, so
      // there is nothing to add here beyond sending them to read it.
      $this->messenger()->addError($this->t('This quote can no longer be paid. Your current status is below.'));
      $this->redirectToTracker($form_state);

      return;
    }

    $url = $this->checkout->startFromApprovedReview($request, $this->time->getRequestTime());
    if ($url === NULL) {
      // The starter refused: no arrival was ever chosen, the operating profile is
      // fail-closed, the Slot is no longer available, or capacity could not be held.
      // Nothing was charged and nothing was consumed — a refusal there undoes whatever
      // it began — so the offer stands and the customer may try again.
      $this->messenger()->addError($this->t('We could not start payment for this quote just now. Nothing has been charged. Please try again, or contact us if it keeps happening.'));
      $this->redirectToTracker($form_state);

      return;
    }

    $form_state->setRedirectUrl($url);
  }

  /**
   * Sends the customer back to their own tracking page.
   */
  private function redirectToTracker(FormStateInterface $form_state): void {
    $form_state->setRedirect('custom_webform_handlers.review_track', ['code' => $this->code]);
  }

  /**
   * A link back to this customer's tracking page.
   */
  private function trackerLink(string|\Stringable $title): array {
    return [
      '#type' => 'link',
      '#title' => $title,
      '#url' => Url::fromRoute('custom_webform_handlers.review_track', ['code' => $this->code]),
      '#attributes' => ['class' => ['button']],
    ];
  }

}
