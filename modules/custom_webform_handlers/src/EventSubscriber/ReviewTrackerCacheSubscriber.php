<?php

namespace Drupal\custom_webform_handlers\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Keeps the two customer-facing review pages out of every cache there is.
 *
 * Specification item 4 asks that a tracker response be no-store, and a controller
 * cannot deliver that on its own. A themed page is a CacheableResponseInterface, and
 * for one of those FinishResponseSubscriber::onRespond() overwrites Cache-Control
 * unconditionally when the response is not cacheable — the customised-header check
 * guards only the cacheable branch and the non-cacheable non-CacheableResponse
 * branch. What core writes there is 'no-cache, must-revalidate', which permits a
 * shared cache to hold the page and merely revalidate it. That is not no-store.
 *
 * So the header is set here instead, after core has had its say. Core's onRespond()
 * runs at priority 0; this runs far below it, which is the whole reason it works.
 * Both public routes are covered from one place, so the controller and the payment
 * form cannot come to disagree about what the contract is — and neither of them has
 * to remember it.
 *
 * Referrer-Policy is the other half, and it is not incidental. The tracking code is
 * in the path, so the URL is itself the credential: without this header, any outbound
 * link the theme happens to render would hand a whole working tracking link to a
 * third-party site in the Referer of the next request.
 *
 * Nothing else on the site is touched. The routes are matched by name against a
 * closed list, so this cannot broaden by accident — adding a page to it is an edit
 * here, deliberately.
 */
final class ReviewTrackerCacheSubscriber implements EventSubscriberInterface {

  /**
   * The routes whose responses must not be stored anywhere.
   *
   * Keyed rather than a list so the lookup is an isset() and not a search, and so a
   * duplicate entry is impossible.
   */
  const ROUTES = [
    'custom_webform_handlers.review_track' => TRUE,
    'custom_webform_handlers.review_pay' => TRUE,
  ];

  /**
   * The Cache-Control a page about one customer's job is served with.
   *
   * no-store is the directive that matters and the one core will not write: it tells
   * every cache, shared or private, not to keep the response at all. The rest are
   * belt-and-braces for intermediaries that predate it or ignore it.
   */
  const CACHE_CONTROL = 'no-store, no-cache, must-revalidate, max-age=0, private';

  /**
   * Rewrites the caching headers of a review page, and of nothing else.
   */
  public function onRespond(ResponseEvent $event): void {
    // Sub-requests are fragments of some other page and are never the tracker; a
    // rendered block must not be able to pull these headers onto an unrelated route.
    if (!$event->isMainRequest()) {
      return;
    }

    $route = $event->getRequest()->attributes->get('_route');
    if (!is_string($route) || !isset(self::ROUTES[$route])) {
      return;
    }

    $response = $event->getResponse();
    $response->headers->set('Cache-Control', self::CACHE_CONTROL);
    $response->headers->set('Pragma', 'no-cache');
    $response->headers->set('Expires', '0');

    // The URL carries the credential, so the Referer must not carry the URL.
    $response->headers->set('Referrer-Policy', 'no-referrer');

    // A validator is only useful to a cache that is allowed to keep the response,
    // and none is. Leaving them would invite exactly the revalidation round trip
    // no-store exists to prevent.
    $response->headers->remove('Etag');
    $response->headers->remove('Last-Modified');
    $response->headers->remove('Vary');
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Core's FinishResponseSubscriber::onRespond() is registered at priority 0 and
    // writes the Cache-Control this replaces. Anything below it will do; -256 is
    // low enough to stay below a contrib module that also went negative.
    return [KernelEvents::RESPONSE => [['onRespond', -256]]];
  }

}
