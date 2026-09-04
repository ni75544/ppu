<?php

/**
 * @file
 * Adds Express Courier to the project-specific hg Webform.
 *
 * Three things, all of them Task 4:
 *
 * 1. Presents the already-installed `courier` Service Type as Express Courier and
 *    pins it first. The machine value is not renamed — configuration, the
 *    eligibility policy, and the quote all key on `courier`.
 * 2. Adds the Delivery Mode and package-fact elements the dispatch profile's
 *    request_fields mapping already names, so the facts it expects finally exist.
 * 3. Moves contact identity to Step 3, so address, Service Type, Delivery Mode,
 *    and item facts are all answered before a quote or Slot is calculated.
 *
 * Element keys are moved between wizard pages rather than rewritten, and no key is
 * renamed, so stored submissions stay readable.
 *
 * Idempotent: re-running rewrites the same elements and re-applies the same page
 * layout instead of duplicating anything.
 *
 * Run from the Drupal root:
 * vendor/bin/drush scr modules/custom_webform_handlers/scripts/update_hg_express_courier.php
 */

use Drupal\webform\Entity\Webform;

$webform = Webform::load('hg');
if (!$webform) {
  throw new RuntimeException('Webform hg was not found. No changes were made.');
}

$backup = '/tmp/hg-elements-before-express-courier.yml';
if (!file_exists($backup) && file_put_contents($backup, $webform->getElementsRaw()) === FALSE) {
  throw new RuntimeException("Could not create backup at {$backup}. No changes were made.");
}

$elements = $webform->getElementsDecoded();

/**
 * Finds one element anywhere in the tree and hands it to a callback by reference.
 */
$update = static function (array &$tree, string $target, callable $callback) use (&$update): bool {
  foreach ($tree as $key => &$value) {
    if ($key === $target && is_array($value)) {
      $callback($value);
      return TRUE;
    }
    if (is_array($value) && $update($value, $target, $callback)) {
      return TRUE;
    }
  }
  return FALSE;
};

// Express Courier is one service among several on a shared form, so a required
// state added for it must not replace one another service already relies on.
// Drupal expresses alternatives as [$a, 'or', $b], and re-running is a no-op
// because an identical branch is recognised rather than appended again.
$add_required_state = static function (array &$element, array $condition): void {
  $existing = $element['#states']['required'] ?? NULL;
  if ($existing === NULL || $existing === []) {
    $element['#states']['required'] = $condition;
    return;
  }
  // A numerically indexed value is already an OR chain; a keyed one is a single
  // condition that has to become the first branch of a new chain.
  if (array_key_exists(0, $existing)) {
    foreach ($existing as $branch) {
      if ($branch === $condition) {
        return;
      }
    }
    $existing[] = 'or';
    $existing[] = $condition;
    $element['#states']['required'] = $existing;
    return;
  }
  if ($existing === $condition) {
    return;
  }
  $element['#states']['required'] = [$existing, 'or', $condition];
};

$is_courier = [':input[name="service_type"]' => ['value' => 'courier']];
$is_store_pickup = $is_courier + [':input[name="delivery_mode"]' => ['value' => 'courier_store_pickup']];
$not_store_pickup = $is_courier + [':input[name="delivery_mode"]' => ['!value' => 'courier_store_pickup']];

// Delivery Mode sits directly under Service Type, before the address, because the
// mode decides which end of the job is a counter and which is a doorstep. The
// machine values are the DeliveryMode enum's backing values; the planner reads them
// through DeliveryMode::tryFrom(), so a label change here is safe and a value
// change is not.
$delivery_mode = [
  '#type' => 'radios',
  '#title' => 'Delivery Mode',
  '#options' => [
    'courier_send' => 'Send — we collect from you and deliver it',
    'courier_receive' => 'Receive — we collect from someone else and bring it to you',
    'courier_store_pickup' => 'Store Pickup — we collect an order you have already paid for',
  ],
  '#description' => 'Express Courier runs as one driver, one pickup, and one delivery.',
  '#states' => ['visible' => $is_courier, 'required' => $is_courier],
];

$courier_package = [
  // Retired from the create form, not deleted. The element and its stored answers
  // stay so historic submissions still render, but Express Courier no longer asks
  // the question, so it must not be rendered or required here either. Emptying
  // '#access_create_roles' makes '#access' FALSE on the create form, which is the
  // same idiom the two retired Courier questions use, and it is why a stale or
  // crafted value cannot block the quote or Slot flow. Keep this in step with
  // custom_webform_handlers_update_10031(); re-adding a required condition here
  // would put the step 1 blocker back.
  'package_description' => [
    '#type' => 'textarea',
    '#title' => 'What are we carrying?',
    '#rows' => 3,
    '#description' => 'Describe the package plainly, for example "a laptop in a padded box" or "two boxes of documents".',
    '#access_create_roles' => [],
  ],
  // The unit matters more than it looks. The request factory accepts only lb and
  // kg and treats anything else as an undeclared weight, because guessing the unit
  // wrong by a factor of 2.2 either refuses a legitimate booking or accepts a
  // package the driver cannot carry alone.
  'total_weight_lbs' => [
    '#type' => 'number',
    '#title' => 'Total weight',
    '#min' => 0,
    '#step' => 0.1,
    '#description' => 'The weight of everything together, not per package.',
    '#states' => ['required' => $is_courier],
  ],
  'total_weight_unit' => [
    '#type' => 'select',
    '#title' => 'Weight unit',
    '#options' => ['lb' => 'Pounds (lb)', 'kg' => 'Kilograms (kg)'],
    '#default_value' => 'lb',
    '#states' => ['required' => $is_courier],
  ],
  // Retired from the create form, not deleted, for the same reason
  // package_description above is: Express Courier does not ask this any more.
  //
  // One driver is not the customer's opinion, it is the definition of the service
  // — one driver, one vehicle, and a 30 lb ceiling chosen so one person can carry
  // the package unaided. Asking turned that into something a visitor could answer
  // "wrong", and a stale wizard value, a resumed draft, or a crafted `0` then
  // refused an entirely valid in-limit request with no visible cause. The fact is
  // now derived server-side from self_bookable.single_driver_service_types in
  // DispatchRequestFactory and written canonically by
  // AddressDistanceHandler::canonicalizeCourierInputs(), so the element carries no
  // '#states' and no required condition here. Keep this in step with
  // custom_webform_handlers_update_10035(); restoring a condition here would put
  // the hidden fixture dependency back.
  //
  // The element and its options stay so the historic answers still render with
  // their own labels, and a genuine two-person job is still refused — by a declared
  // vehicle_helper_needs or a multi_person handling flag, both of which the
  // eligibility policy checks before the courier branch.
  'single_driver_handling' => [
    '#type' => 'radios',
    '#title' => 'Can one person carry this without help?',
    '#options' => [
      '1' => 'Yes, one person can carry it',
      '0' => 'No, it needs two people',
    ],
    '#description' => 'Express Courier sends a single driver. Anything needing two people is reviewed by staff instead of being booked online.',
    '#access_create_roles' => [],
  ],
];

// Contacts at both ends. For Send the pickup contact is usually the customer, and
// for Receive it is a third party, so the same two fields serve both. Store Pickup
// has a merchant instead of a person, which is why the pickup pair is not required
// for that mode.
$courier_contacts = [
  'pickup_contact_name' => [
    '#type' => 'textfield',
    '#title' => 'Pickup contact name',
    '#states' => ['visible' => $not_store_pickup, 'required' => $not_store_pickup],
  ],
  'pickup_contact_phone' => [
    '#type' => 'tel',
    '#title' => 'Pickup contact phone',
    '#pattern' => '(?:\\+?1[ .\\-]?)?(?:\\([0-9]{3}\\)|[0-9]{3})[ .\\-]?[0-9]{3}[ .\\-]?[0-9]{4}',
    '#pattern_error' => 'Enter a valid 10-digit US phone number.',
    '#states' => ['visible' => $not_store_pickup, 'required' => $not_store_pickup],
  ],
  'pickup_instructions' => [
    '#type' => 'textarea',
    '#title' => 'Pickup instructions',
    '#rows' => 2,
    '#description' => 'Buzzer, unit number, gate code, or where to wait. Optional.',
    '#states' => ['visible' => $is_courier],
  ],
  'delivery_contact_name' => [
    '#type' => 'textfield',
    '#title' => 'Delivery contact name',
    '#states' => ['visible' => $is_courier, 'required' => $is_courier],
  ],
  'delivery_contact_phone' => [
    '#type' => 'tel',
    '#title' => 'Delivery contact phone',
    '#pattern' => '(?:\\+?1[ .\\-]?)?(?:\\([0-9]{3}\\)|[0-9]{3})[ .\\-]?[0-9]{3}[ .\\-]?[0-9]{4}',
    '#pattern_error' => 'Enter a valid 10-digit US phone number.',
    '#states' => ['visible' => $is_courier, 'required' => $is_courier],
  ],
  'delivery_instructions' => [
    '#type' => 'textarea',
    '#title' => 'Delivery instructions',
    '#rows' => 2,
    '#description' => 'Buzzer, unit number, gate code, or where to leave it. Optional.',
    '#states' => ['visible' => $is_courier],
  ],
];

// Store Pickup. The paid-and-ready confirmation is the load-bearing one: the
// eligibility policy requires an explicit yes for this mode, and the request factory
// reads an unchecked box as a definite no rather than as unknown. A driver sent to
// collect an order that has not been paid for cannot complete the job.
$store_pickup = [
  '#type' => 'fieldset',
  '#title' => 'Store Pickup details',
  '#states' => ['visible' => $is_store_pickup],
  'store_pickup_merchant' => [
    '#type' => 'textfield',
    '#title' => 'Store or merchant name',
    '#states' => ['required' => $is_store_pickup],
  ],
  'store_pickup_order_reference' => [
    '#type' => 'textfield',
    '#title' => 'Order or confirmation number',
    '#description' => 'What the counter staff will look the order up by.',
    '#states' => ['required' => $is_store_pickup],
  ],
  'store_pickup_name' => [
    '#type' => 'textfield',
    '#title' => 'Name the order is held under',
    '#description' => 'Only needed if it differs from the name on this request.',
  ],
  'order_is_paid_and_ready' => [
    '#type' => 'checkbox',
    '#title' => 'The order is already paid for and ready to collect',
    '#description' => 'Our driver collects only. They cannot pay for the order, wait for it to be prepared, or place it.',
    '#states' => ['required' => $is_store_pickup],
  ],
];

// Handoff, urgency, and contents. prohibited_items_ack is the contents screening:
// its option values are the handling markers themselves, matched against the
// dispatch profile's manual_review.handling_flags. "Not sure" is listed there
// alongside "restricted" on purpose — a customer who does not know what is in the
// package has told us something, and uncertainty is not permission.
$courier_handling = [
  'handoff_preference' => [
    '#type' => 'radios',
    '#title' => 'How should the driver hand it over?',
    '#options' => [
      'in_person' => 'In person, signature on delivery',
      'leave_at_door' => 'Leave at the door if nobody answers',
      'reception' => 'Leave with reception or a concierge',
    ],
    '#default_value' => 'in_person',
    '#states' => ['visible' => $is_courier, 'required' => $is_courier],
  ],
  'courier_urgency' => [
    '#type' => 'radios',
    '#title' => 'How soon do you need it there?',
    '#options' => [
      'standard' => 'Standard',
      'rush' => 'Rush',
      'expedited' => 'Expedited',
    ],
    '#default_value' => 'standard',
    '#states' => ['visible' => $is_courier],
  ],
  'prohibited_items_ack' => [
    '#type' => 'radios',
    '#title' => 'Does the package contain anything restricted?',
    '#options' => [
      'none' => 'No — no cash, weapons, hazardous, perishable, live, or illegal contents',
      'restricted' => 'Yes, one or more of those',
      'unsure' => 'I am not sure',
    ],
    '#description' => 'We cannot carry cash, weapons, hazardous or flammable materials, live animals, perishable goods, or anything illegal. Say so here and staff will review the request instead of booking it online.',
    '#states' => ['visible' => $is_courier, 'required' => $is_courier],
  ],
];

// The whole courier question set as one fieldset. Nested rather than flat so a
// customer who chose another service never scrolls past it, and so the mode-specific
// Store Pickup block can appear and disappear as a unit.
$courier_details = array_merge(
  [
    '#type' => 'fieldset',
    '#title' => 'Express Courier Details',
    '#description' => 'Express Courier runs as one driver, one pickup, and one delivery. A request beyond the currently configured weight, route, or handling limits is reviewed by staff instead of being booked online.',
    '#states' => ['visible' => $is_courier],
    '#collapsible' => TRUE,
    '#collapsed' => FALSE,
  ],
  $courier_package,
  ['store_pickup_details' => $store_pickup],
  $courier_contacts,
  $courier_handling,
);

$missing = [];
$applied = [];

// Item 1. Express Courier is pinned ahead of the other services and the machine
// value stays `courier`, because the dispatch profile, the eligibility policy, and
// the quote all key on it. Unsetting before prepending is what makes a re-run
// idempotent instead of leaving two courier options behind.
$found = $update($elements, 'service_type', static function (array &$element): void {
  $options = $element['#options'] ?? [];
  $placeholder = [];
  if (array_key_exists('', $options)) {
    $placeholder[''] = $options[''];
  }
  unset($options[''], $options['courier']);
  $element['#options'] = $placeholder + ['courier' => 'Express Courier'] + $options;
});
$found ? $applied[] = 'service_type: Express Courier pinned first, machine value unchanged' : $missing[] = 'service_type';

// The preliminary estimate currently sits inside the address fieldset, which puts it
// above the package facts that feed it. The sidebar locates this element by its
// data-custom-quote-preview attribute rather than by position, so moving it is safe.
$quote_preview = NULL;
$update($elements, 'address', static function (array &$element) use (&$quote_preview): void {
  if (isset($element['quote_preview'])) {
    $quote_preview = $element['quote_preview'];
    unset($element['quote_preview']);
  }
});

// Item 2, by reuse rather than by addition. Count and dimensions already exist for
// every service in shipment_measurements; Express Courier needs them answered, not
// duplicated. The merge helper is what keeps another service's required state intact.
foreach (['item_quantity', 'item_length', 'item_width', 'item_height'] as $key) {
  $found = $update($elements, $key, static function (array &$element) use ($add_required_state, $is_courier): void {
    $add_required_state($element, $is_courier);
    $element['#states']['visible'] ??= [':input[name="service_type"]' => ['filled' => TRUE]];
  });
  $found ? $applied[] = $key . ': required for Express Courier' : $missing[] = $key;
}

// The optional package photo is this existing element, not a new one: same private
// scheme, same size limits, one more sentence of copy.
$found = $update($elements, 'universal_item_file', static function (array &$element): void {
  $element['#title'] = 'Upload a photo or video of the package, item(s), or access conditions (optional)';
  $element['#description'] = 'A photo of the package helps us confirm it is a one-person carry, and photos of difficult access points improve any estimate. Maximum file size: 20 MB. Accepted file types: JPG, PNG, GIF, MP4, MOV, and WEBM.';
});
$found ? $applied[] = 'universal_item_file: package photo copy added' : $missing[] = 'universal_item_file';

// Item 3, the reordering itself. Every direct child of the three wizard pages is
// collected into one flat map and then dealt back out. Only page 1 and page 3 state
// their contents; everything else keeps its original relative order on page 2, so an
// element nobody thought to name here cannot be silently dropped.
$page_properties = [];
$children = [];
foreach (['page_1', 'page_2', 'page_3'] as $page) {
  if (!isset($elements[$page]) || !is_array($elements[$page])) {
    $missing[] = $page;
    continue;
  }
  foreach ($elements[$page] as $key => $value) {
    if (str_starts_with((string) $key, '#')) {
      $page_properties[$page][$key] = $value;
      continue;
    }
    $children[$key] = $value;
  }
}

// Item 2's new elements join the same map, so a re-run replaces them in place rather
// than adding a second copy.
$children['delivery_mode'] = $delivery_mode;
$children['courier_details'] = $courier_details;
if ($quote_preview !== NULL) {
  $children['quote_preview'] = $quote_preview;
}

$layout = [
  'page_1' => [
    'service_type', 'delivery_mode', 'address', 'shipment_measurements',
    'courier_details', 'local_residential_move_details', 'local_commercial_move_details',
    'long_distance_move_details', 'specialty_item_move_details', 'diy_truck_rental_details',
    'on_demand_delivery_details', 'universal_item_file', 'quote_preview',
  ],
  'page_3' => [
    'contact_information', 'quote_disclaimer', 'data_consent', 'final_calculated_quote',
    'estimated_quote_currency', 'estimated_quote_status', 'estimated_quote_breakdown',
    'estimated_quote_rate_version', 'disclaimer',
  ],
];
$claimed = array_merge($layout['page_1'], $layout['page_3']);
$layout['page_2'] = array_values(array_diff(array_keys($children), $claimed));

// Contact identity stays on Step 3 per the task, which is also the honest order: we
// ask who you are once there is something to quote.
$titles = [
  'page_1' => [
    '1. Service & Package Details',
    'Choose a service, then give us the addresses and the facts about what is being carried. A preliminary estimate appears once there is enough to calculate one.',
  ],
  'page_2' => [
    '2. Scheduling & Add-ons',
    'When you need it, and anything you would like added.',
  ],
  'page_3' => [
    '3. Your Contact Information',
    'Last step. Where the estimate and confirmation should go.',
  ],
];

foreach ($titles as $page => [$title, $description]) {
  if (!isset($page_properties[$page])) {
    continue;
  }
  $rebuilt = $page_properties[$page];
  $rebuilt['#title'] = $title;
  $rebuilt['#description'] = $description;
  foreach ($layout[$page] as $key) {
    if (!isset($children[$key])) {
      $missing[] = $page . '/' . $key;
      continue;
    }
    $rebuilt[$key] = $children[$key];
  }
  $elements[$page] = $rebuilt;
  $applied[] = $page . ': ' . count($layout[$page]) . ' elements, "' . $title . '"';
}

$webform->setElements($elements);
$webform->save();

print "EXPRESS COURIER ELEMENTS UPDATED\n";
print "Backup: {$backup}\n";
foreach ($applied as $line) {
  print "  - {$line}\n";
}
print '  - delivery_mode and courier_details (' . count($courier_details) . " keys) written\n";
if ($missing !== []) {
  print 'ELEMENTS NOT FOUND: ' . implode(', ', $missing) . "\n";
}

