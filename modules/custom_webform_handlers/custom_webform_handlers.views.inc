<?php

/**
 * @file
 * Exposes the Dispatch reservation ledger to Views, read-only.
 *
 * One base table and nothing else. The ledger
 * custom_webform_handlers_dispatch_reservation is the schedule authority, so a
 * dashboard built on it must read the same rows the planner writes rather than
 * reconstruct a schedule from Webform submissions — two answers to "what is
 * booked" is the defect this file exists to avoid.
 *
 * Three rules are enforced here rather than in the View that consumes it,
 * because a rule that lives in a View can be edited away by anyone holding
 * 'administer views':
 *
 * 1. The two secret columns, hold_token_hash and owner_hash, are not declared
 *    at all. A column Views has never heard of cannot be added as a field, a
 *    filter, a sort, or an argument, in this View or in one somebody writes
 *    later. Absence is the guarantee; an access callback would not be.
 * 2. Protected columns declare a field handler and nothing else. No filter, no
 *    sort, no argument on an address, a price, or an order pointer, so a role
 *    without the disclosure permission cannot infer a withheld value by
 *    filtering or ordering by it — which a permission check on rendering alone
 *    would not prevent.
 * 3. Every stored instant is an integer of UTC seconds, and every field that
 *    renders one uses the dispatch_local_time handler, which formats in the
 *    row's own recorded timezone. Core's date field would format in the
 *    viewer's timezone, and personal timezones are configurable on this site.
 *
 * @see custom_webform_handlers_views_data()
 * @see \Drupal\custom_webform_handlers\Plugin\views\field\DispatchProtectedFieldBase
 */

use Drupal\custom_webform_handlers\Dispatch\Storage\ReservationStateLabels;

/**
 * Implements hook_views_data().
 */
function custom_webform_handlers_views_data(): array {
  $data = [];
  $table = 'custom_webform_handlers_dispatch_reservation';

  $data[$table]['table']['group'] = t('Dispatch reservation');
  $data[$table]['table']['base'] = [
    'field' => 'dispatch_reservation_id',
    'title' => t('Dispatch Reservations'),
    'help' => t('The authoritative Dispatch reservation ledger: Holds, confirmed Reservations, and the rows they became. Read-only.'),
  ];

  // The identifier doubles as the link to the existing detail page, and the
  // handler publishes that link only for the state the detail route actually
  // serves. See DispatchReservationLink.
  $data[$table]['dispatch_reservation_id'] = [
    'title' => t('Reservation ID'),
    'help' => t('The ledger identifier. Links to the Dispatch Planner detail page for a confirmed Reservation.'),
    'field' => ['id' => 'dispatch_reservation_link'],
    'filter' => ['id' => 'numeric'],
    'argument' => ['id' => 'numeric'],
    'sort' => ['id' => 'standard'],
  ];

  // The five live and closed states, offered as a select rather than as free
  // text: an operator filtering for "confirmed" should not have to know that
  // the column stores a machine name, and a typo should not silently return an
  // empty board that looks like an empty day. Core's machine_name field renders
  // the same labels, and falls back to printing an unmapped machine name
  // verbatim — which is the right answer for a state this module has not been
  // taught, because a row in an unknown state is one an operator must see.
  $data[$table]['state'] = [
    'title' => t('Booking state'),
    'help' => t('held, confirmed, released, expired, cancelled, or replanned. Only held and confirmed occupy time on the grid.'),
    'field' => [
      'id' => 'machine_name',
      'options callback' => ReservationStateLabels::class . '::options',
    ],
    'filter' => [
      'id' => 'in_operator',
      'options callback' => ReservationStateLabels::class . '::options',
    ],
    'argument' => ['id' => 'string'],
    'sort' => ['id' => 'standard'],
  ];

  $data[$table]['webform_id'] = [
    'title' => t('Service form'),
    'help' => t('The Webform the request came from. The Dispatch workflow answers for the forms in its allowlist only.'),
    'field' => ['id' => 'standard'],
    'filter' => ['id' => 'string'],
    'argument' => ['id' => 'string'],
    'sort' => ['id' => 'standard'],
  ];

  $data[$table]['resource_id'] = [
    'title' => t('Resource'),
    'help' => t('The Resource whose capacity this row consumes, for example a driver.'),
    'field' => ['id' => 'standard'],
    'filter' => ['id' => 'string'],
    'argument' => ['id' => 'string'],
    'sort' => ['id' => 'standard'],
  ];

  // An opaque pointer, not a disclosure: knowing the number grants nothing,
  // because reading the submission itself is governed by Webform's own
  // permissions. It is here because reconciling a job to its request is
  // day-to-day scheduling work.
  $data[$table]['submission_id'] = [
    'title' => t('Submission ID'),
    'help' => t('The Webform submission this row was confirmed against, when there is one. A Hold taken before checkout has none.'),
    'field' => ['id' => 'numeric'],
    'filter' => ['id' => 'numeric'],
    'argument' => ['id' => 'numeric'],
    'sort' => ['id' => 'standard'],
  ];

  // Every instant below is stored as UTC seconds and rendered by the one local
  // time handler. The filters and sorts are core's, which compare integers and
  // so are timezone-neutral; only the reading of a boundary typed into an
  // exposed date filter is interpreted in the viewer's own zone.
  $instants = [
    'pickup_arrival' => [
      t('Pickup arrival'),
      t('The arrival instant the Slot promised. The one time a customer was told.'),
    ],
    'window_start' => [
      t('Window start'),
      t('The start of the booked Dispatch Window, before repositioning space.'),
    ],
    'window_end' => [
      t('Window end'),
      t('The end of the booked Dispatch Window, before repositioning space.'),
    ],
    'expires_at' => [
      t('Hold expires'),
      t('When an unfinished Hold lapses and stops consuming capacity. Empty on a confirmed Reservation.'),
    ],
    'created' => [
      t('Created'),
      t('When the row was first written.'),
    ],
    'changed' => [
      t('Changed'),
      t('When the row last moved state.'),
    ],
  ];
  foreach ($instants as $column => [$title, $help]) {
    $data[$table][$column] = [
      'title' => $title,
      'help' => $help,
      'field' => ['id' => 'dispatch_local_time'],
      'filter' => ['id' => 'date'],
      'argument' => ['id' => 'date'],
      'sort' => ['id' => 'date'],
    ];
  }

  // The zone each row was written in, exposed so that a page claiming to show
  // New York time can be checked against what was actually stored.
  $data[$table]['timezone'] = [
    'title' => t('Recorded timezone'),
    'help' => t("The Resource's timezone at the moment of booking. Local times on this page are formatted in it."),
    'field' => ['id' => 'standard'],
    'filter' => ['id' => 'string'],
    'sort' => ['id' => 'standard'],
  ];

  // Repositioning and route figures are plain integers in their stored units.
  // Neither is converted here: a summary in minutes and kilometres already
  // exists on the board, and a second implementation of the same arithmetic in
  // a Views handler is the duplication this dashboard was asked not to add.
  // 'empty_zero' must stay FALSE in the View, so a measured zero is not shown
  // as the same thing as a route nobody measured.
  $integers = [
    'inbound_reposition_seconds' => [
      t('Inbound repositioning (s)'),
      t('Travel space held before the window, in seconds.'),
    ],
    'outbound_reposition_seconds' => [
      t('Outbound repositioning (s)'),
      t('Travel space held after the window, in seconds.'),
    ],
    'route_seconds' => [
      t('Route duration (s)'),
      t('The measured driving time for the job, in seconds. Empty when no route was measured.'),
    ],
    'route_meters' => [
      t('Route distance (m)'),
      t('The measured driving distance for the job, in metres. Empty when no route was measured.'),
    ],
  ];
  foreach ($integers as $column => [$title, $help]) {
    $data[$table][$column] = [
      'title' => $title,
      'help' => $help,
      'field' => ['id' => 'numeric'],
      'filter' => ['id' => 'numeric'],
      'sort' => ['id' => 'standard'],
    ];
  }

  // Protected disclosures. Each declares a field handler and a permission, and
  // deliberately declares no filter, no sort, and no argument: the value is not
  // orderable or searchable by anybody, which is what keeps a role that may not
  // read an address from inferring one.
  $data[$table]['pickup_location'] = [
    'title' => t('Pickup address'),
    'help' => t('The exact pickup address recorded on the claim. Shown only to roles that may read exact addresses.'),
    'field' => [
      'id' => 'dispatch_protected_text',
      'dispatch permission' => 'view dispatch exact addresses',
      'click sortable' => FALSE,
    ],
  ];

  $data[$table]['delivery_location'] = [
    'title' => t('Delivery address'),
    'help' => t('The exact delivery address recorded on the claim. Shown only to roles that may read exact addresses.'),
    'field' => [
      'id' => 'dispatch_protected_text',
      'dispatch permission' => 'view dispatch exact addresses',
      'click sortable' => FALSE,
    ],
  ];

  // One money field, assembled from the three snapshot columns, so a figure is
  // never published without the currency it was quoted in or the snapshot it
  // came from. price_snapshot_id and price_snapshot_currency are not declared
  // as fields of their own: alone they disclose nothing useful, and together
  // with a stray numeric field they would reassemble the price this permission
  // exists to gate.
  $data[$table]['price_snapshot_cents'] = [
    'title' => t('Price snapshot'),
    'help' => t('The amount, currency, and snapshot identifier recorded when the claim was made. Shown only to roles that may read financial detail.'),
    'field' => [
      'id' => 'dispatch_price_snapshot',
      'dispatch permission' => 'view dispatch financial details',
      'additional fields' => [
        'price_snapshot_currency' => 'price_snapshot_currency',
        'price_snapshot_id' => 'price_snapshot_id',
      ],
      'click sortable' => FALSE,
    ],
  ];

  $data[$table]['order_id'] = [
    'title' => t('Order'),
    'help' => t('The Commerce order this claim was paid through, when there is one. A pointer, not an authority.'),
    'field' => [
      'id' => 'dispatch_protected_text',
      'dispatch permission' => 'view dispatch financial details',
      'click sortable' => FALSE,
    ],
  ];

  // Three columns the ledger does not have. The ledger stores a submission
  // pointer, not a customer, so each of these reads exactly one element from
  // exactly one submission entity through Webform's own API — never the whole
  // payload, and never a join across submission data. They are pseudo-fields
  // over submission_id, so they cannot be filtered or sorted: doing either
  // would need the value in SQL, and the value is not in this table.
  $contact = [
    'customer_name' => ['full_name', t('Customer name')],
    'customer_phone' => ['phone_number', t('Customer phone')],
    'customer_email' => ['email', t('Customer email')],
  ];
  foreach ($contact as $key => [$element, $title]) {
    $data[$table][$key] = [
      'title' => $title,
      'help' => t('Read from the linked Webform submission. Shown only to roles that may read customer contact detail.'),
      'field' => [
        'id' => 'dispatch_customer_contact',
        'real field' => 'submission_id',
        'dispatch permission' => 'view dispatch customer contact',
        'dispatch contact element' => $element,
        'click sortable' => FALSE,
      ],
    ];
  }

  return $data;
}
