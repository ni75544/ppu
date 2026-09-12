# Address input validation fix

Recorded 2026-09-10 (America/New_York) for distance calculator `1.1.14`.

## Symptom and cause

The public Webform displayed Google `PlaceAutocompleteElement` controls while
Drupal submitted separate visually hidden `origin_address` and
`destination_address` inputs. The custom elements are not successful HTML form
controls. Until a completed selection had finished an asynchronous Place Details
request, both submitted values remained empty; the next wizard action therefore
returned **From field is required** and **To field is required**.

The deployed aggregate contained the current `gmp-select` listener, and that
listener matches Google's current Place Autocomplete contract. The failure was
control ownership and asynchronous write timing, not a stale event name.

## Correction

- Prefer Google's compatible input-bound `Autocomplete` class when the imported
  Places library exposes it. The visible control is now the actual Webform input,
  so typed text, blur, preview, validation, persistence, and submission all read
  one value.
- Retain the modern custom-element path for environments where the compatible
  class is unavailable. It writes the selected prediction into the Webform input
  before awaiting Place Details, preventing a quick Next action from racing an
  empty field.
- Ignore widget input clearing while a selection is resolving.
- Restore the normal required text input when the modern widget emits
  `gmp-error`, so autocomplete failure cannot make the form impossible to use.
- Bump the calculator library version from `1.1.13` to `1.1.14`.

## Bounded verification

The live browser loop first reproduced both reported validation errors. After the
change and a Drupal cache rebuild:

- the delivered production-style JavaScript aggregate created no duplicate
  `gmp-place-autocomplete` controls on this site;
- the visible origin and destination controls were the named Webform inputs;
- a public route from `350 5th Ave, New York, NY 10118` to
  `1 Times Square, New York, NY 10036, USA` produced a 1.29-mile OSRM preview and
  a $19.00 preliminary Express Courier quote;
- Next advanced to **2. Package & Quote** with neither required-field error; and
- the Step 2 estimate retained the validated distance and price.

The independent active-configuration problem found during the same loop was also
corrected: local OSRM had reverted to disabled and was restored to enabled. The
private endpoint remained present and healthy. Mapbox and Google Routes remained
disabled with zero paid routing allowance.

No final submission, Slot claim, Commerce order, payment, Mapbox request, or
Google Routes request was performed.
