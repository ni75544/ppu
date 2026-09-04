<?php

namespace Drupal\custom_webform_handlers\Dispatch;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Symfony\Component\Routing\Route;

/**
 * Refuses a booking route whose workflow does not own the Webform it names.
 *
 * The controllers consult BookingWorkflowGate themselves, and they are the
 * authority: this checker cannot see a Webform named in a request body, so it
 * cannot guard the three fixed-slot mutation routes at all. It runs on the six
 * routes that carry the Webform in the path, where the answer is available before
 * the controller is built, so a request for the wrong workflow is refused before
 * any of the module's own code sees it.
 *
 * Core combines every applicable access check with AccessResult::andIf(), so
 * adding this requirement to a route can only ever narrow it — the existing
 * "_access: TRUE" and CSRF requirements keep their effect, and a denial here is
 * final.
 *
 * The route declares which workflow it belongs to, rather than this class
 * inferring it from the path. A checker that guesses from a URL is one rename
 * away from silently opening a route to the workflow it was meant to exclude.
 */
final class BookingWorkflowAccessCheck implements AccessInterface {

  /**
   * The route requirement that names the workflow a route serves.
   */
  const REQUIREMENT = '_custom_webform_handlers_booking_workflow';

  public function __construct(
    private readonly BookingWorkflowGate $gate,
  ) {}

  /**
   * Allows the route only when the named workflow owns the named Webform.
   */
  public function access(Route $route, RouteMatchInterface $route_match): AccessResult {
    $required = BookingWorkflow::tryFrom(trim((string) $route->getRequirement(self::REQUIREMENT)));
    $webformId = trim((string) $route_match->getRawParameters()->get('webform'));

    // A route that names no workflow this class understands, or that names
    // Disabled, is not a route this class may open. Declaring a workflow is the
    // opt-in, and a mistyped requirement must close the route rather than
    // quietly stop guarding it.
    $allowed = $required !== NULL
      && $required !== BookingWorkflow::Disabled
      && $this->gate->allows($webformId, $required);

    $result = $allowed ? AccessResult::allowed() : AccessResult::forbidden();
    $result->addCacheTags(['config:' . BookingWorkflowGate::SETTINGS]);
    if ($webformId !== '') {
      $result->addCacheTags(['config:webform.webform.' . $webformId]);
    }

    return $result;
  }

}
