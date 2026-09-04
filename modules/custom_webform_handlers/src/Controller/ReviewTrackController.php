<?php

namespace Drupal\custom_webform_handlers\Controller;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Link;
use Drupal\Core\PageCache\ResponsePolicy\KillSwitch;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\custom_webform_handlers\Review\ReviewManagerInterface;
use Drupal\custom_webform_handlers\Review\ReviewStatus;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The page a tracking code buys, and the only thing it buys.
 *
 * Specification item 4's customer-facing half. This controller holds no access
 * check of its own and needs none: the 192-bit code in the path is the credential,
 * and possession of it is the authorisation. What that possession is worth is
 * decided in the manager, which answers with a ReviewStatus — a projection built by
 * naming each publishable fact — so there is nothing here for this page to over-
 * disclose. It renders what it is given and asks for nothing more.
 *
 * Non-disclosure is the reason the shape of this method is what it is. A code that
 * matches nothing and a code whose request was purged both come back NULL from the
 * manager, and both leave here as the site's ordinary 404 — the same exception, with
 * no message, no log line, and no distinguishing header. A code the router's own
 * pattern refuses never reaches this method and produces that same 404 from the
 * router. Three ways to be wrong, one answer, so an enumerator learns nothing about
 * whether some other code exists.
 *
 * Caching is refused three times over, because each mechanism is a different cache.
 * The route carries no_cache, which keeps the page out of Drupal's internal and
 * dynamic page caches; the render array is max-age 0 with no cache tags, so no
 * fragment of it can be reused; and ReviewTrackerCacheSubscriber sets no-store on the
 * response, which is the only one of the three that reaches a shared proxy or a
 * browser's back-forward cache after a link has been forwarded.
 */
final class ReviewTrackController implements ContainerInjectionInterface {

  use StringTranslationTrait;

  public function __construct(
    private readonly ReviewManagerInterface $reviews,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly KillSwitch $killSwitch,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get(ReviewManagerInterface::class),
      $container->get('date.formatter'),
      $container->get('page_cache_kill_switch'),
    );
  }

  /**
   * Answers one tracking code.
   *
   * @param string $code
   *   The raw tracking code from the path. Bounded by the route's own pattern to the
   *   character set a code is drawn from, and normalised again by the credential
   *   before it is hashed, so nothing unbounded reaches a query.
   *
   * @return array
   *   The render array for this request's status page.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
   *   When the code names no request. Thrown with no message, so the response body is
   *   the site's ordinary 404 and says nothing about why.
   */
  public function track(string $code): array {
    // Triggered before the lookup, not after a hit. Doing it after would make a hit
    // uncacheable and a miss cacheable, and the difference between those two is
    // observable from outside — which is precisely what item 4 forbids being able to
    // tell apart.
    $this->killSwitch->trigger();

    $status = $this->reviews->trackByCode($code);
    if ($status === NULL) {
      throw new NotFoundHttpException();
    }

    return $this->page($status);
  }

  /**
   * Renders one status, publishing nothing the status does not carry.
   *
   * Every free-text value goes out through #plain_text. The staff message and the
   * service summary are normalised and bounded before storage, but they are still
   * text somebody typed, and #plain_text is what makes "safe to show without being
   * trusted" true of the rendering rather than only of the storage.
   *
   * The cache metadata is max-age 0 with no tags and no contexts. Not "cached per
   * user": not cached. A cache context would imply this page varies by something the
   * site knows about the reader, and it does not — it varies by a credential, which
   * is not a thing the cache system can key on safely.
   */
  private function page(ReviewStatus $status): array {
    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['cwh-review-track', 'cwh-review-track--' . $status->state]],
      '#cache' => ['max-age' => 0],
    ];

    $build['headline'] = [
      '#type' => 'html_tag',
      '#tag' => 'h2',
      '#value' => $status->headline,
    ];

    $build['detail'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $status->detail,
    ];

    if ($status->serviceSummary !== '') {
      $build['summary'] = $this->labelled($this->t('Your request'), $status->serviceSummary);
    }

    // Shown as what it is — a note from a person — rather than merged into the
    // detail, so a customer can tell the site's wording from a colleague's.
    if ($status->staffMessage !== '') {
      $build['staff_message'] = $this->labelled($this->t('Note from our team'), $status->staffMessage);
    }

    if ($status->quote !== NULL) {
      $build['quote'] = $this->labelled($this->t('Price'), $status->quote);
    }

    // The payment action is published as the manager's own URL or not at all. This
    // page never decides that a request is payable, and never composes the link from
    // anything it was not handed, which is why it can offer a way to pay without ever
    // holding an order identifier.
    if ($status->isPayable()) {
      $build['pay'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['cwh-review-track__action']],
        'link' => Link::fromTextAndUrl(
          $this->t('Accept and pay'),
          Url::fromUri($status->paymentUrl),
        )->toRenderable(),
      ];
    }

    $build['updated'] = $this->labelled(
      $this->t('Last updated'),
      $this->dateFormatter->format($status->updated, 'short'),
    );

    return $build;
  }

  /**
   * One label-and-value pair, with the value rendered as text and not as markup.
   *
   * A helper rather than six near-identical arrays, because the thing that must be
   * true of all of them — #plain_text, never #markup and never html_tag's #value —
   * should be written once where it can be checked once. html_tag would put the
   * value through Xss::filterAdmin(), which permits a good deal of markup; the staff
   * message and the service summary are text somebody typed, and #plain_text is what
   * makes "safe to show without being trusted" true of the rendering as well as of
   * the storage.
   */
  private function labelled(string|\Stringable $label, string $value): array {
    return [
      '#type' => 'container',
      'label' => [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#attributes' => ['class' => ['cwh-review-track__label']],
        '#value' => (string) $label,
      ],
      'value' => [
        '#prefix' => '<span class="cwh-review-track__value">',
        '#plain_text' => $value,
        '#suffix' => '</span>',
      ],
    ];
  }

}
