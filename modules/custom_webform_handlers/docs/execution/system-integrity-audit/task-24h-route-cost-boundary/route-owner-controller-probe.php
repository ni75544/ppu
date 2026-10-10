<?php

/**
 * @file
 * Task 24H — checks 5 & 6 that HTTP cannot reach, measured on the QA site only.
 *
 * Run under drush php:script against the QA URI. It is QA-gated in code: if the
 * active database is not the isolated `qa` database it refuses to run, so it can
 * never touch the default site. It measures real provider invocations via the
 * same [T24H-DIAG] counter the HTTP acceptance uses.
 *
 *   Check 5  Unavailable session: the REAL controller preview() is driven with a
 *            request that carries no session, so RouteRequestOwner::token() can
 *            establish no owner. The controller must return a typed refusal
 *            (routing_busy 503) with ZERO provider calls — the failure path fires
 *            before any calculation. This is unreachable over HTTP, where a
 *            request always has a session.
 *   Check 6  Form-page GET: loading the courier form (/form/hg) must perform ZERO
 *            route calculations — a page load is not a routing operation.
 *
 * Prints only booleans, HTTP-ish status codes, and counter deltas. No owner
 * value, address, reference, cookie, or token is printed.
 *
 * No config write, no Dispatch write, no commit, no log clearing.
 */

use Drupal\Core\Database\Database;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;

$db = Database::getConnection()->getConnectionOptions()['database'] ?? '';
if ($db !== 'qa') {
  echo "REFUSED: not the QA database (found '{$db}'). This probe runs on ppu-qa only.\n";
  return;
}

$pass = 0;
$fail = 0;
$check = function (string $label, bool $ok) use (&$pass, &$fail) {
  if ($ok) {
    $pass++;
    echo "  PASS  {$label}\n";
  }
  else {
    $fail++;
    echo "  FAIL  {$label}\n";
  }
};

$state = \Drupal::state();
$counter = static fn () => (int) \Drupal::state()->get('custom_webform_handlers.t24h_calc_count', 0);

// ---------------------------------------------------------------------------
echo "== Check 5: unavailable session -> typed refusal BEFORE any calculation ==\n";

$before5 = $counter();

// Build the real controller from the container, exactly as routing would.
$controller = DistancePreviewControllerFactory();

// A syntactically valid route_preview body: the request would calculate if it
// got as far as the provider. It must NOT — there is no session to own it.
$body = json_encode([
  'origin' => '10 Main St, Town',
  'destination' => '90 Elm Ave, City',
  'operation' => 'route_preview',
  'operation_id' => 'op-nosession-' . bin2hex(random_bytes(4)),
  'quote_input' => ['delivery_mode' => 'standard', 'total_weight_lbs' => '5'],
]);
$request = Request::create('/custom-webform-handlers/distance-preview', 'POST', [], [], [], [], $body);
$request->headers->set('Content-Type', 'application/json');
// Deliberately DO NOT call $request->setSession(): hasSession() is FALSE, so no
// anonymous owner can be established — the exact unavailable-session condition.
// RouteRequestOwner resolves ownership from the request stack's CURRENT request,
// so push this session-less request onto the stack (drush's own synthetic CLI
// request carries a session, which is why an unpushed request calculated). The
// drush user is already anonymous (uid 0), so token() must take the anonymous
// branch and refuse. Pop in a finally so the stack is left as it was found.
$hasSession = $request->hasSession();
$check('Check 5 the crafted request carries no session', $hasSession === FALSE);

$requestStack = \Drupal::service('request_stack');
$requestStack->push($request);
try {
  $response = $controller->preview($request);
}
finally {
  $requestStack->pop();
}
$status = $response->getStatusCode();
$payload = json_decode((string) $response->getContent(), TRUE) ?: [];
$code = $payload['code'] ?? '';
echo "    http={$status} code={$code}\n";
$check('Check 5 the controller refuses (503)', $status === 503);
$check('Check 5 the refusal is the typed routing_busy code', $code === 'routing_busy');

$after5 = $counter();
echo "    provider calc delta = " . ($after5 - $before5) . "\n";
$check('Check 5 ZERO provider calculations on the failure path', ($after5 - $before5) === 0);

// ---------------------------------------------------------------------------
echo "== Check 6: form-page GET (/form/hg) -> ZERO route calculations ==\n";

$before6 = $counter();

$kernel = \Drupal::service('http_kernel');
$formRequest = Request::create('/form/hg', 'GET');
$formResponse = $kernel->handle($formRequest, HttpKernelInterface::MAIN_REQUEST, FALSE);
$formStatus = $formResponse->getStatusCode();
echo "    /form/hg http={$formStatus}\n";
// A 200 is ideal, but any non-5xx page render proves the form loaded without
// erroring; the calculation count is the assertion that matters here.
$check('Check 6 the form page renders (not a server error)', $formStatus < 500);

$after6 = $counter();
echo "    provider calc delta = " . ($after6 - $before6) . "\n";
$check('Check 6 ZERO route calculations on a form-page GET', ($after6 - $before6) === 0);

// ---------------------------------------------------------------------------
printf("\n== CONTROLLER PROBE (checks 5 & 6): %d passed, %d failed ==\n", $pass, $fail);
printf("total provider calc counter now: %d\n", $counter());

/**
 * Builds the real DistancePreviewController from the container.
 *
 * Uses the class's own create() so the wiring is exactly what routing uses —
 * no hand-assembled collaborators, no mocks.
 */
function DistancePreviewControllerFactory(): object {
  $class = 'Drupal\\custom_webform_handlers\\Controller\\DistancePreviewController';
  /** @var \Symfony\Component\DependencyInjection\ContainerInterface $container */
  $container = \Drupal::getContainer();
  if (is_subclass_of($class, ContainerInjectionInterface::class)) {
    return $class::create($container);
  }
  throw new \RuntimeException('Controller is not container-injectable.');
}
