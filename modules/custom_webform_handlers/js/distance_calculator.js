/**
 * @file
 * Server-authoritative distance/quote preview for committed address pairs.
 *
 * This behavior is the only client-side owner of the preview request. Address
 * completion is separate and grants permission through one committed-pair
 * event; generic input, change, focus, date, and Slot events cannot request a
 * route. The quote summary and route map render its DOM events only.
 *
 * Nothing here is authoritative. Every value written into the form is
 * recalculated server-side on submission, so a disabled, failing, or tampered
 * script cannot change the stored price and must never block submission.
 */
(function (Drupal, drupalSettings, once) {
  'use strict';

  /** Wizard input is only reused for a short window, then treated as stale. */
  const STORAGE_TTL_MS = 30 * 60 * 1000;

  /** Abort a preview that never answers so the UI cannot hang on "updating". */
  const PREVIEW_TIMEOUT_MS = 12000;

  /** Debounce for pricing-input edits. */
  const REFRESH_DELAY_MS = 400;

  /**
   * The shape an address must have before the server is asked to resolve it.
   *
   * This is deliberately a floor, not a validator: the only cost of refusing a
   * preview is that the visitor sees the estimate one commit later, whereas the
   * cost of accepting a fragment is a billed provider request for an address
   * nobody has finished writing. Server-side validation remains authoritative.
   */
  const MIN_ADDRESS_LENGTH = 6;
  const MIN_ADDRESS_TOKENS = 2;

  /** How long to wait for a third-party Maps bootstrap before giving up. */
  const MAPS_WAIT_MS = 10000;

  /**
   * Collapses an address to the form its fingerprint is computed over.
   *
   * A trailing space, a doubled comma, or a change of case is not a new address
   * and must not buy a new provider request. The preview endpoint applies the
   * same normalization to its own fingerprint; the two are computed
   * independently and neither is derived from the other, so a divergence costs
   * at most one duplicate request and can never admit an unnormalized one.
   */
  function normalizeAddress(value) {
    return String(value == null ? '' : value)
      .replace(/\s+/g, ' ')
      .trim()
      .toLowerCase()
      .replace(/\s*,\s*/g, ', ')
      .replace(/(?:,\s*){2,}/g, ', ')
      .replace(/^[\s,]+/, '')
      .replace(/[\s,]+$/, '');
  }

  /**
   * Whether one address is complete enough to be worth a resolution attempt.
   */
  function isResolvableAddress(value) {
    const normalized = normalizeAddress(value);
    if (normalized.length < MIN_ADDRESS_LENGTH) {
      return false;
    }
    if (normalized.split(' ').filter(Boolean).length < MIN_ADDRESS_TOKENS) {
      return false;
    }
    // A street address carries a number, or at least a comma-separated
    // locality. A single bare word is a place name being typed, not a route
    // endpoint.
    return /\d/.test(normalized) || normalized.indexOf(',') !== -1;
  }

  /** Shared Maps bootstrap so several forms never inject competing scripts. */
  let mapsLoader = null;

  /**
   * The Webforms this experience attaches to.
   *
   * The handler marks the form it alters, and publishes the matching
   * webform_id in its settings. Both must agree before anything is wired, so a
   * second Webform sharing the page is never touched.
   */
  const FORM_SELECTOR = 'form[data-custom-webform-quote]';

  /** The `once` identifier this behavior attaches and detaches under. */
  const ONCE_ID = 'custom-webform-distance-calculator';

  /**
   * Returns recoverable, human-readable copy for a classified failure code.
   *
   * Every message tells the visitor the form is still submittable, because the
   * authoritative quote is calculated server-side on submission.
   */
  function messageFor(code) {
    switch (code) {
      case 'rate_limited':
        return Drupal.t(
          'Too many estimate requests. Please wait a moment and change a field to try again — you can still submit the form.',
        );

      case 'invalid_address':
      case 'invalid_route_input':
        return Drupal.t(
          'One of those addresses could not be read. Check them and try again — you can still submit the form.',
        );

      case 'route_not_found':
        return Drupal.t(
          'No driving route was found between those addresses. You can still submit the form and we will confirm the price.',
        );

      case 'provider_not_configured':
        return Drupal.t(
          'Live estimates are unavailable right now. You can still submit the form and we will confirm the price.',
        );

      case 'preview_timeout':
        return Drupal.t(
          'The estimate is taking longer than expected. Change a field to retry, or submit the form and we will confirm the price.',
        );

      case 'refresh_required':
        return Drupal.t(
          'That estimate has expired. Re-enter or re-confirm the addresses to refresh it — you can still submit the form and we will confirm the price.',
        );

      case 'network_unavailable':
        return Drupal.t(
          'The estimate could not be updated while offline. You can still submit the form.',
        );

      default:
        return Drupal.t(
          'The estimate could not be updated. You can still submit the form and we will confirm the price.',
        );
    }
  }

  /**
   * Escapes a value for use inside a double-quoted attribute selector.
   */
  function attr(value) {
    return String(value).replace(/\\/g, '\\\\').replace(/"/g, '\\"');
  }

  /**
   * Builds a selector matching an element name and its array-notation variants.
   */
  function nameSelector(name) {
    const value = attr(name);
    return `[name="${value}"], [name^="${value}["]`;
  }

  /**
   * Returns the settings belonging to one form, or null.
   *
   * The handler publishes one flat bucket per page and stamps the form it
   * altered with the matching webform_id. A bucket that names a different
   * Webform belongs to a different form on this page, so it is refused rather
   * than borrowed.
   */
  function settingsFor(form, settings) {
    const config = Object.assign(
      {},
      drupalSettings.custom_webform_handlers,
      (settings || {}).custom_webform_handlers,
    );
    const id = form.getAttribute('data-custom-webform-quote') || '';
    return id && config.webform_id === id ? config : null;
  }

  /**
   * Reads the persisted wizard input, discarding anything past its TTL.
   */
  function readStoredInput(key) {
    if (!key) {
      return {};
    }
    try {
      const raw = window.sessionStorage.getItem(key);
      if (!raw) {
        return {};
      }
      const parsed = JSON.parse(raw);
      if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) {
        return {};
      }
      const savedAt = Number(parsed.at);
      if (!Number.isFinite(savedAt) || Date.now() - savedAt > STORAGE_TTL_MS) {
        window.sessionStorage.removeItem(key);
        return {};
      }
      const input = parsed.input;
      return input && typeof input === 'object' && !Array.isArray(input)
        ? input
        : {};
    } catch (error) {
      return {};
    }
  }

  /**
   * Replaces the persisted wizard input, stamping it for TTL checks.
   */
  function writeStoredInput(key, input) {
    if (!key) {
      return;
    }
    try {
      window.sessionStorage.setItem(
        key,
        JSON.stringify({ at: Date.now(), input }),
      );
    } catch (error) {
      // Session storage is optional; the current page still recalculates.
    }
  }

  /**
   * Uses the Webform-wide key so an AJAX wizard UUID change cannot buy a route
   * that this same browser session has already requested.
   */
  function routeStorageKey(key) {
    const parts = String(key || '').split(':');
    const root = parts.length > 2 ? parts.slice(0, -1).join(':') : parts.join(':');
    return root ? `${root}:route-attempts` : '';
  }

  /**
   * Reads the bounded per-session route ledger.
   */
  function readRouteLedger(key) {
    if (!key) {
      return [];
    }
    try {
      const parsed = JSON.parse(window.sessionStorage.getItem(key) || '[]');
      if (!Array.isArray(parsed)) {
        return [];
      }
      return parsed.filter(
        (entry) =>
          entry &&
          typeof entry === 'object' &&
          typeof entry.pair === 'string' &&
          typeof entry.status === 'string' &&
          Number.isFinite(Number(entry.at)) &&
          Date.now() - Number(entry.at) <= STORAGE_TTL_MS,
      );
    } catch (error) {
      return [];
    }
  }

  /**
   * Writes at most ten pair attempts. One attempted pair remains attempted even
   * after an AJAX rebuild, and a successful one carries the reusable result.
   */
  function writeRouteLedger(key, ledger) {
    if (!key) {
      return;
    }
    try {
      window.sessionStorage.setItem(key, JSON.stringify(ledger.slice(-10)));
    } catch (error) {
      // Session storage is optional; the in-memory lock still protects this page.
    }
  }

  /**
   * Returns the prior-page context when Drupal has just assigned a draft UUID.
   *
   * A wizard starts under the Webform-wide key and receives its per-submission
   * key only after the first Next action. Merge the short-lived root entry
   * first, then let the more specific entry win. This carries only allowlisted
   * quote and dispatch facts; it never restores a hold, slot, or token.
   */
  function readWizardInput(key) {
    const rootKey = String(key || '').replace(/:[^:]+$/, '');
    if (!rootKey || rootKey === key) {
      return readStoredInput(key);
    }
    return Object.assign({}, readStoredInput(rootKey), readStoredInput(key));
  }

  /**
   * Reads the current value of every allowlisted input present in this form.
   *
   * Values found in the form always replace the persisted copy so a cleared or
   * re-answered question can never leave stale pricing input behind.
   */
  function collectInput(form, names, initial) {
    const collected = Object.assign({}, initial);
    names.forEach((name) => {
      if (!name) {
        return;
      }
      const elements = form.querySelectorAll(nameSelector(name));
      if (!elements.length) {
        return;
      }
      delete collected[name];
      elements.forEach((element) => {
        if (
          (element.type === 'checkbox' || element.type === 'radio') &&
          !element.checked
        ) {
          return;
        }
        // One control answering once is stored as a scalar, even when it is a
        // checkbox. dispatch_slots.js collects the same fields under the same
        // rule, and that agreement is load-bearing: this store is written here
        // and read there, so a field the visitor answers on an earlier wizard
        // page reaches the server in whatever shape was written for it. A lone
        // checkbox stored as ["1"] would arrive where the server expects one
        // answer and it would read none — the attestation would look unanswered
        // and the single-driver assertion unmade, and the request would be sent
        // to staff with no Slots. Bracketed names and repeated same-name
        // checkboxes stay lists, because those do carry several answers, and a
        // radio group stays a scalar however many options it renders.
        const isMultiValue =
          element.name.indexOf('[') !== -1 ||
          (element.type === 'checkbox' && elements.length > 1);
        if (isMultiValue) {
          if (!Array.isArray(collected[name])) {
            collected[name] = [];
          }
          collected[name].push(element.value);
        } else {
          collected[name] = element.value;
        }
      });
    });
    return collected;
  }

  function mapsReady() {
    return (
      typeof google !== 'undefined' &&
      google.maps &&
      (typeof google.maps.importLibrary === 'function' ||
        typeof google.maps.Map === 'function')
    );
  }

  /**
   * Loads the Maps JavaScript API once per page and resolves when it is usable.
   *
   * This loads map rendering only. Address suggestions are self-hosted so Maps
   * JavaScript can never generate a billed Places request while somebody types.
   */
  function loadMaps(apiKey) {
    if (mapsLoader) {
      return mapsLoader;
    }

    mapsLoader = new Promise((resolve, reject) => {
      const waitForReady = () => {
        if (mapsReady()) {
          resolve();
          return;
        }
        const startedAt = Date.now();
        const timer = window.setInterval(() => {
          if (mapsReady()) {
            window.clearInterval(timer);
            resolve();
          } else if (Date.now() - startedAt > MAPS_WAIT_MS) {
            window.clearInterval(timer);
            reject(new Error('maps_timeout'));
          }
        }, 200);
      };

      if (
        mapsReady() ||
        document.querySelector('script[src*="maps.googleapis.com/maps/api/js"]')
      ) {
        waitForReady();
        return;
      }
      if (!apiKey) {
        reject(new Error('maps_key_missing'));
        return;
      }

      const script = document.createElement('script');
      script.src = `https://maps.googleapis.com/maps/api/js?key=${encodeURIComponent(apiKey)}&v=weekly&loading=async`;
      script.async = true;
      script.defer = true;
      script.onerror = () => reject(new Error('maps_script_failed'));
      script.onload = waitForReady;
      document.head.appendChild(script);
    });

    return mapsLoader;
  }

  /**
   * Wires one Webform: persistence, preview requests, and optional autocomplete.
   */
  function setupForm(form, config) {
    const fieldNames = config.field_names || {};
    const quoteInputNames = Array.isArray(config.quote_input_fields)
      ? config.quote_input_fields
      : [];
    const contextInputNames = Array.isArray(config.wizard_context_fields)
      ? config.wizard_context_fields
      : quoteInputNames;
    const storageKey = config.quote_context_key || '';
    const routeLedgerKey = routeStorageKey(storageKey);
    const routeLedger = readRouteLedger(routeLedgerKey);
    const previewUrl = config.preview_url || '';
    const csrfTokenUrl = config.csrf_token_url || '';
    const quoteDefaults = config.quote_defaults || {};
    const apiKey = config.google_maps_browser_api_key || '';

    const originInput = fieldNames.origin
      ? form.querySelector(nameSelector(fieldNames.origin))
      : null;
    const destinationInput = fieldNames.destination
      ? form.querySelector(nameSelector(fieldNames.destination))
      : null;
    const hasAddressInputs = Boolean(originInput && destinationInput);

    const addressNames = [fieldNames.origin, fieldNames.destination].filter(
      Boolean,
    );

    // Addresses are persisted too: later wizard pages no longer render them but
    // still need them to refresh the preview after a pricing answer changes.
    const persistedNames = contextInputNames
      .concat(addressNames)
      .filter(Boolean);
    const previewEnabled = Boolean(previewUrl && csrfTokenUrl);

    let previewToken = '';
    let requestSequence = 0;
    let activeAbort = null;
    let refreshTimer = null;
    let lastRequestKey = '';

    // True from the first keystroke in an address field until that pair is
    // committed. No route request may leave the browser while it is set.
    let addressesDirty = true;

    // Address-pair fingerprints. `lastRequested` is set when a request goes out
    // and `lastSuccessful` when one returns usable facts; the pair is compared
    // separately from the full request key so a pricing-only edit is
    // distinguishable from a route change.
    let lastRequestedPair = '';
    let lastSuccessfulPair = '';

    // The full request key currently on the wire, or ''. An in-flight marker
    // rather than a boolean: a second commit for facts already being requested
    // is dropped, while a pricing edit during the same window still passes.
    let inFlightKey = '';

    // The last usable response, with the key it answered. Replayed when a
    // commit asks for facts already known, so reverting an edit restores the
    // estimate without a request. This is a completed answer for the current
    // facts, not a superseded response being resurrected.
    let lastSuccessfulKey = '';
    let lastSuccessfulData = null;

    // Set while mapped fields are written back, so the input and change events
    // that writing dispatches are never mistaken for visitor edits.
    let writingMappedFields = false;

    // Per-pair route_preview operation ids. A route_preview is idempotent by this
    // id on the server: presenting the same id for the same journey replays the
    // recorded — already billed — result instead of calculating again, which
    // closes the lost-response and concurrent-duplicate gaps the in-flight marker
    // alone cannot. One id per committed pair, minted lazily and reused for every
    // route_preview of that pair, and dropped only when the server reports its
    // fact is gone (refresh_required) so the next commit mints a fresh one. It is
    // in-memory: a reload is a fresh intent, and the server's own dedup window and
    // route cache cover a re-request after one.
    const routeOperationIds = new Map();

    /**
     * Returns the stable operation id for a committed pair, minting one lazily.
     */
    function operationIdForPair(pair) {
      let id = routeOperationIds.get(pair);
      if (!id) {
        id = mintOperationId();
        routeOperationIds.set(pair, id);
      }
      return id;
    }

    /**
     * Mints an opaque operation id within the server's accepted charset/length.
     *
     * A UUID from the platform crypto source when there is one; a random hex
     * string when only getRandomValues is present; and, absent both, a time+random
     * token that is still unique within one browser — which is all that is needed,
     * because the id is scoped to this owner and this journey on the server side.
     */
    function mintOperationId() {
      const source = window.crypto || window.msCrypto;
      if (source && typeof source.randomUUID === 'function') {
        return source.randomUUID();
      }
      if (source && typeof source.getRandomValues === 'function') {
        const bytes = new Uint8Array(16);
        source.getRandomValues(bytes);
        return Array.from(bytes, (byte) =>
          byte.toString(16).padStart(2, '0'),
        ).join('');
      }
      return (
        'op-' +
        Date.now().toString(36) +
        '-' +
        Math.random().toString(36).slice(2, 12)
      );
    }

    function routeEntry(pair) {
      return routeLedger.find((entry) => entry.pair === pair) || null;
    }

    function recordRouteAttempt(pair, status, data) {
      const existing = routeEntry(pair);
      const entry = {
        pair,
        status,
        at: Date.now(),
        data: data || null,
      };
      if (existing) {
        Object.assign(existing, entry);
      } else {
        routeLedger.push(entry);
      }
      writeRouteLedger(routeLedgerKey, routeLedger);
      return entry;
    }

    /**
     * Removes a pair's ledger entry so a re-commit may attempt it afresh.
     *
     * Used only when the server has told us the fact it once held is gone
     * (refresh_required). The one-attempt-per-pair rule exists to stop a browser
     * re-buying the same route, not to strand a visitor whose fact merely
     * expired: forgetting the entry lets one explicit re-commit ask for a new
     * route_preview, while the dirty-gate still bars any incidental event from
     * doing so on its own.
     */
    function forgetRouteAttempt(pair) {
      const index = routeLedger.findIndex((entry) => entry.pair === pair);
      if (index !== -1) {
        routeLedger.splice(index, 1);
        writeRouteLedger(routeLedgerKey, routeLedger);
      }
    }

    function persistInput() {
      const merged = collectInput(
        form,
        persistedNames,
        readWizardInput(storageKey),
      );
      writeStoredInput(storageKey, merged);
      return merged;
    }

    function currentInput() {
      const merged = collectInput(
        form,
        persistedNames,
        Object.assign({}, quoteDefaults, readWizardInput(storageKey)),
      );
      writeStoredInput(storageKey, merged);
      return merged;
    }

    /**
     * Strips the address fields out of the pricing payload.
     */
    function pricingPayload(input) {
      const payload = {};
      quoteInputNames.forEach((name) => {
        if (Object.prototype.hasOwnProperty.call(input, name)) {
          payload[name] = input[name];
        }
      });
      return payload;
    }

    function addresses(input) {
      const origin = hasAddressInputs
        ? originInput.value
        : input[fieldNames.origin];
      const destination = hasAddressInputs
        ? destinationInput.value
        : input[fieldNames.destination];
      return {
        origin: String(origin == null ? '' : origin).trim(),
        destination: String(destination == null ? '' : destination).trim(),
      };
    }

    function emit(type, detail) {
      form.dispatchEvent(
        new CustomEvent(type, { bubbles: true, detail: detail || {} }),
      );
    }

    /**
     * The fingerprint of one ordered address pair.
     *
     * A route is directional, so the pair is ordered and A→B never collides
     * with B→A.
     */
    function pairKey(route) {
      return JSON.stringify([
        normalizeAddress(route.origin),
        normalizeAddress(route.destination),
      ]);
    }

    function isCompletePair(route) {
      return (
        isResolvableAddress(route.origin) &&
        isResolvableAddress(route.destination)
      );
    }

    function setMappedField(key, value) {
      const name = fieldNames[key];
      if (!name) {
        return;
      }
      const element = form.querySelector(`[name="${attr(name)}"]`);
      if (!element) {
        return;
      }
      writingMappedFields = true;
      element.value = value;
      element.dispatchEvent(new Event('input', { bubbles: true }));
      element.dispatchEvent(new Event('change', { bubbles: true }));
      writingMappedFields = false;
    }

    /**
     * Blanks every field the browser mirrors, so a superseded or failed preview
     * can never leave a stale distance or price in the submitted values.
     */
    function clearMappedFields() {
      [
        'distance',
        'distance_meters',
        'quote_total',
        'quote_currency',
        'quote_status',
        'quote_rate_version',
        'quote_breakdown',
        'accepted_quote',
      ].forEach((key) => setMappedField(key, ''));
    }

    function clearPreview(message, code) {
      clearMappedFields();
      emit('custom-webform-quote-cleared', { message, code: code || '' });
      emit('custom-webform-route-cleared', { message, code: code || '' });
    }

    function invalidateActivePreview() {
      requestSequence += 1;
      if (!activeAbort) {
        return;
      }
      window.clearTimeout(activeAbort.timer);
      activeAbort.controller.abort();
      activeAbort = null;
    }

    async function previewCsrfToken(forceRefresh) {
      if (previewToken && !forceRefresh) {
        return previewToken;
      }
      const response = await fetch(csrfTokenUrl, {
        method: 'GET',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { Accept: 'text/plain' },
      });
      if (!response.ok) {
        throw new Error('csrf_token_request_failed');
      }
      previewToken = (await response.text()).trim();
      if (!previewToken) {
        throw new Error('csrf_token_request_failed');
      }
      return previewToken;
    }

    /**
     * Posts to the site's own preview endpoint with a fresh CSRF header,
     * retrying once when the token has expired.
     */
    async function postPreview(payload, signal) {
      for (let attempt = 0; attempt < 2; attempt += 1) {
        const token = await previewCsrfToken(attempt > 0);
        const response = await fetch(previewUrl, {
          method: 'POST',
          credentials: 'same-origin',
          cache: 'no-store',
          signal,
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': token,
          },
          body: JSON.stringify(payload),
        });
        if (response.status !== 403 || attempt === 1) {
          return response;
        }
        previewToken = '';
      }
      throw new Error('csrf_token_request_failed');
    }

    /**
     * Writes one usable preview into the form and announces it.
     *
     * Extracted so a commit for facts already answered can replay the stored
     * response instead of buying it again.
     */
    function applyPreview(data, route) {
      const distanceMiles = Number(data.distance.distance_miles);
      if (!Number.isFinite(distanceMiles) || distanceMiles <= 0) {
        throw Object.assign(new Error('provider_request_failed'), {
          code: 'provider_request_failed',
        });
      }

      setMappedField('distance', `${distanceMiles.toFixed(2)} mi`);
      setMappedField('distance_meters', String(data.distance.distance_meters));
      setMappedField('quote_total', data.quote.total || '');
      setMappedField('quote_currency', data.quote.currency || '');
      setMappedField('quote_status', data.quote.status || '');
      setMappedField('quote_rate_version', data.quote.rate_version || '');
      // The opaque reference to the server-owned route fact travels inside the
      // breakdown so it survives to submission, save and checkout without a new
      // form element. It is the browser's only handle on the exact fact this
      // estimate was priced from; the server re-prices from that same fact by this
      // reference (never by re-selecting any fact whose pair merely matches), which
      // is what guarantees the price accepted, saved and charged is one figure. By
      // now data.route_reference is always a string — minted for a route_preview,
      // carried forward from the last success for a quote-only reprice.
      setMappedField(
        'quote_breakdown',
        JSON.stringify(
          Object.assign({}, data.quote, {
            route_reference:
              typeof data.route_reference === 'string' ? data.route_reference : '',
          }),
        ),
      );
      // The dedicated per-instance reference carrier. Unlike the breakdown (which
      // this form renders only on its last wizard page), this hidden element is
      // present on page one where the commit happens, so the exact reference reaches
      // the acceptance gate on a later page. The server re-prices strictly from this
      // reference and re-validates the pair, so a stale value for a since-changed
      // address is refused, never silently reused.
      setMappedField(
        'route_reference',
        typeof data.route_reference === 'string' ? data.route_reference : '',
      );
      // The dedicated per-instance accepted-quote carrier. Like the reference
      // carrier above, this hidden element is present on every wizard page —
      // including page one, where the estimate is produced — so the server-produced
      // figure the customer saw reaches the acceptance gate, which runs on the
      // page-two advance before the breakdown field is ever rendered. It carries the
      // same quote the breakdown does; the gate compares total, currency and rate
      // version against a fresh server re-price and only equality lets the save
      // continue, so this is evidence of the seen figure, never price authority.
      setMappedField(
        'accepted_quote',
        JSON.stringify(
          Object.assign({}, data.quote, {
            route_reference:
              typeof data.route_reference === 'string' ? data.route_reference : '',
          }),
        ),
      );

      emit('custom-webform-quote-updated', { quote: data.quote });
      emit('custom-webform-route-validated', {
        origin: route.origin,
        destination: route.destination,
        distance: data.distance,
        encodedPolyline: data.distance.encoded_polyline || '',
        pair: pairKey(route),
        fingerprint: typeof data.fingerprint === 'string' ? data.fingerprint : '',
      });
    }

    async function refreshPreview() {
      if (!previewEnabled) {
        return;
      }
      // An uncommitted edit is not a route. This is the single choke point that
      // keeps typing from reaching a paid provider: every caller passes through
      // it, and none of them may bypass this test.
      if (addressesDirty) {
        return;
      }

      const input = currentInput();
      const route = addresses(input);
      const pair = pairKey(route);
      if (!isCompletePair(route)) {
        invalidateActivePreview();
        lastRequestKey = '';
        inFlightKey = '';
        clearPreview(
          route.origin && route.destination
            ? Drupal.t(
                'Enter both addresses in full to see a preliminary estimate.',
              )
            : Drupal.t('Enter both addresses to see a preliminary estimate.'),
          'incomplete_addresses',
        );
        return;
      }

      const storedRoute = routeEntry(pair);
      if (
        storedRoute &&
        storedRoute.status === 'success' &&
        storedRoute.data
      ) {
        lastSuccessfulPair = pair;
        lastSuccessfulData = storedRoute.data;
        addressesDirty = false;
      }
      const routeKnown =
        lastSuccessfulPair === pair && Boolean(lastSuccessfulData);
      if (storedRoute && storedRoute.status !== 'success' && !routeKnown) {
        clearPreview(
          Drupal.t(
            'This address pair has already been checked. Change an address to try a different route.',
          ),
          'route_already_attempted',
        );
        return;
      }

      const quoteInput = pricingPayload(input);
      const requestKey = JSON.stringify([
        route.origin,
        route.destination,
        quoteInput,
      ]);

      // Facts already answered. Replay the stored response: no request, and no
      // period where a valid pair shows no estimate.
      if (requestKey === lastSuccessfulKey && routeKnown) {
        try {
          applyPreview(lastSuccessfulData, route);
          return;
        } catch (error) {
          lastSuccessfulKey = '';
          lastSuccessfulData = null;
        }
      }

      // Already on the wire. A repeated commit for unchanged facts adds nothing.
      if (requestKey === inFlightKey) {
        return;
      }
      if (requestKey === lastRequestKey) {
        return;
      }
      // A known route makes this a quote-only request. The stored distance is
      // sent back to the non-authoritative preview endpoint; it must not call a
      // routing provider again. A new pair gets exactly one route attempt.
      const routeRequest = !routeKnown;
      const routeUnchanged = !routeRequest;
      lastRequestKey = requestKey;
      lastRequestedPair = pair;
      inFlightKey = requestKey;
      if (routeRequest) {
        recordRouteAttempt(pair, 'attempted', null);
      }

      const sequence = ++requestSequence;
      if (activeAbort) {
        activeAbort.controller.abort();
      }
      const abort = { controller: new AbortController(), timedOut: false };
      activeAbort = abort;
      abort.timer = window.setTimeout(() => {
        abort.timedOut = true;
        abort.controller.abort();
      }, PREVIEW_TIMEOUT_MS);

      emit('custom-webform-quote-pending', {
        message: Drupal.t('Updating your estimate…'),
        routeUnchanged,
      });

      try {
        // The operation is stated explicitly, never inferred by the server from
        // which fields are present. A new pair asks for a route_preview; a known
        // pair asks for a quote_only reprice and names the server-held fact by
        // the opaque reference the preview handed back — it sends no distance of
        // its own, because the browser is not the authority for one.
        const payload = {
          operation: routeRequest ? 'route_preview' : 'quote_only',
          origin: route.origin,
          destination: route.destination,
          quote_input: quoteInput,
        };
        if (routeRequest) {
          // A route_preview must be nameable so the server can make it
          // idempotent; the id is stable for this pair, so a retry of the same
          // journey replays rather than re-bills.
          payload.operation_id = operationIdForPair(pair);
        } else {
          payload.route_reference = lastSuccessfulData.route_reference || '';
        }
        const response = await postPreview(payload, abort.controller.signal);

        let data = {};
        try {
          data = await response.json();
        } catch (error) {
          data = {};
        }
        if (sequence !== requestSequence) {
          return;
        }
        if (
          !response.ok ||
          data.status !== 'success' ||
          !data.distance ||
          !data.quote
        ) {
          const code =
            response.status === 429
              ? 'rate_limited'
              : data.code || 'provider_request_failed';
          throw Object.assign(new Error(code), { code });
        }

        if (!routeRequest) {
          // Quote-only responses deliberately omit route geometry and do not
          // re-issue a reference. Preserve the one route result and the one
          // reference already held by this session.
          data.distance = lastSuccessfulData.distance;
          data.fingerprint = lastSuccessfulData.fingerprint || '';
          data.route_reference = lastSuccessfulData.route_reference || '';
        } else {
          // The reference names the fact the server just took into custody. It
          // is the browser's only handle on that fact; a quote-only reprice can
          // present nothing else and be believed.
          data.route_reference =
            typeof data.route_reference === 'string' ? data.route_reference : '';
        }

        applyPreview(data, route);
        lastSuccessfulKey = requestKey;
        lastSuccessfulData = data;
        lastSuccessfulPair = pair;
        recordRouteAttempt(pair, 'success', data);
      } catch (error) {
        if (sequence !== requestSequence && !abort.timedOut) {
          // Superseded by a newer request; that request owns the UI state.
          return;
        }
        let code = error && error.code ? error.code : '';
        if (abort.timedOut) {
          code = 'preview_timeout';
        } else if (error && error.name === 'AbortError') {
          return;
        } else if (!code) {
          code =
            window.navigator && window.navigator.onLine === false
              ? 'network_unavailable'
              : 'provider_request_failed';
        }
        if (routeRequest) {
          recordRouteAttempt(pair, 'failed', null);
        } else if (code === 'refresh_required') {
          // The server no longer holds a usable fact for this pair — its custody
          // expired, or the routing mode changed under it. Repricing again would
          // present the same dead reference and be refused again forever, so the
          // known-route state is torn down and the ledger entry demoted. A fresh
          // estimate now requires re-committing the pair through the address
          // event, which is the only thing allowed to ask for a route_preview:
          // the failure cannot convert itself into a route request, and a
          // pricing edit, focus, date, poll or wizard rebuild cannot either.
          lastRequestKey = '';
          lastSuccessfulKey = '';
          lastSuccessfulData = null;
          lastSuccessfulPair = '';
          forgetRouteAttempt(pair);
          // The fact this journey's operation id vouched for is gone, so the id is
          // spent. Drop it: the re-commit the visitor must now make mints a fresh
          // one and buys a genuinely new route, rather than replaying a dead id.
          routeOperationIds.delete(pair);
          markAddressesDirty(messageFor(code));
          return;
        } else {
          // A quote-only failure did not spend a route request and may be
          // retried after the visitor changes another pricing answer.
          lastRequestKey = '';
        }
        clearPreview(messageFor(code), code);
      } finally {
        window.clearTimeout(abort.timer);
        if (activeAbort === abort) {
          activeAbort = null;
        }
        if (inFlightKey === requestKey) {
          inFlightKey = '';
        }
      }
    }

    function scheduleRefresh() {
      window.clearTimeout(refreshTimer);
      refreshTimer = window.setTimeout(refreshPreview, REFRESH_DELAY_MS);
    }

    function baseName(element) {
      if (!element || !element.name) {
        return '';
      }
      return element.name.replace(/\[.*$/, '');
    }

    function isTrackedInput(element) {
      const base = baseName(element);
      return base !== '' && persistedNames.indexOf(base) !== -1;
    }

    function isQuoteInput(element) {
      const base = baseName(element);
      return base !== '' && quoteInputNames.indexOf(base) !== -1;
    }

    function isAddressInput(element) {
      const base = baseName(element);
      return base !== '' && addressNames.indexOf(base) !== -1;
    }

    /**
     * Records that an address is being edited and takes the stale route down.
     *
     * This is the whole of item 1: the edit cancels pending work, clears the
     * route, the quote and any selected Slot, and dispatches nothing. Aborting
     * is UI cleanup only — a request already transmitted has already been billed
     * upstream and cannot be recalled.
     */
    function markAddressesDirty(message) {
      const wasDirty = addressesDirty;
      addressesDirty = true;
      window.clearTimeout(refreshTimer);
      invalidateActivePreview();
      inFlightKey = '';
      // A later commit may replay a successful ledger entry, but an attempted
      // pair is never sent to the provider twice in this browser session.
      lastRequestKey = '';
      if (wasDirty) {
        return;
      }
      // Tells dispatch_slots to drop the selected Slot and suspend polling. It
      // is a distinct event from `route-cleared`, which also fires for provider
      // failures where the committed pair is still valid.
      emit('custom-webform-address-dirty', {
        message: message || '',
        lastSuccessfulPair,
      });
      clearPreview(
        message ||
          Drupal.t(
            'Finish entering the address to see an updated estimate — you can still submit the form.',
          ),
        'incomplete_addresses',
      );
    }

    /**
     * Treats the current address pair as finished and asks for one preview.
     *
     * An incomplete pair stays dirty: the visitor is still mid-address, and no
     * amount of waiting turns a fragment into a route.
     */
    function commitAddresses() {
      const route = addresses(currentInput());
      if (!isCompletePair(route)) {
        return;
      }
      addressesDirty = false;
      refreshPreview();
    }

    function onFormInput(event) {
      if (writingMappedFields) {
        return;
      }
      if (isAddressInput(event.target)) {
        persistInput();
        markAddressesDirty();
        return;
      }
      if (!isTrackedInput(event.target)) {
        return;
      }
      persistInput();
      // Only price inputs may ask for a quote-only refresh. Dates, Slots and
      // other dispatch inputs never touch the distance endpoint.
      if (isQuoteInput(event.target)) {
        scheduleRefresh();
      }
    }

    function onFormChange(event) {
      if (writingMappedFields) {
        return;
      }
      if (isAddressInput(event.target)) {
        persistInput();
        return;
      }
      if (!isTrackedInput(event.target)) {
        return;
      }
      persistInput();
      if (isQuoteInput(event.target)) {
        scheduleRefresh();
      }
    }

    /**
     * The only event allowed to turn an address pair into a route request.
     */
    function onAddressCommitted(event) {
      persistInput();
      const route = addresses(currentInput());
      if (
        !event.detail ||
        event.detail.pair !== pairKey(route) ||
        !isCompletePair(route)
      ) {
        return;
      }
      commitAddresses();
    }

    /**
     * A submission owns the outcome from here; the server recalculates.
     */
    function onFormSubmit() {
      window.clearTimeout(refreshTimer);
    }

    const listeners = [
      ['input', onFormInput],
      ['change', onFormChange],
      ['custom-webform-address-committed', onAddressCommitted],
      ['submit', onFormSubmit],
    ];
    listeners.forEach(([type, handler]) => {
      form.addEventListener(type, handler);
    });

    /**
     * Releases everything this form holds.
     *
     * Timers and the pending request go first, then the delegated listeners.
     * Widget listeners are bound to the widget element itself and are released
     * with it when an AJAX rebuild replaces the form's markup.
     */
    function teardown() {
      window.clearTimeout(refreshTimer);
      refreshTimer = null;
      invalidateActivePreview();
      inFlightKey = '';
      listeners.forEach(([type, handler]) => {
        form.removeEventListener(type, handler);
      });
    }

    // Seed storage from whatever this wizard page renders. Reattach may replay
    // a successful session result, but attach itself can never request a route.
    persistInput();
    if (previewEnabled) {
      const known = addresses(currentInput());
      const pair = pairKey(known);
      const storedRoute = routeEntry(pair);
      if (
        isCompletePair(known) &&
        storedRoute &&
        storedRoute.status === 'success' &&
        storedRoute.data
      ) {
        lastSuccessfulPair = pair;
        lastSuccessfulData = storedRoute.data;
        lastSuccessfulKey = JSON.stringify([
          known.origin,
          known.destination,
          pricingPayload(currentInput()),
        ]);
        applyPreview(lastSuccessfulData, known);
      }
    }

    if (hasAddressInputs) {
      originInput.setAttribute('autocomplete', 'street-address');
      destinationInput.setAttribute('autocomplete', 'street-address');
    }

    if (!apiKey) {
      return { teardown };
    }

    loadMaps(apiKey).catch(() => {
      // The route map is optional; address entry and the server-side preview
      // keep working when Maps JavaScript is unavailable.
    });

    return { teardown };
  }

  // One live experience per set-up form, so a detach can release its timers,
  // pending request and delegated listeners when an AJAX wizard rebuild removes
  // the form they were attached to.
  const instances = new WeakMap();

  Drupal.behaviors.customWebformDistanceCalculator = {
    attach(context, settings) {
      once(ONCE_ID, FORM_SELECTOR, context).forEach((form) => {
        const config = settingsFor(form, settings);
        if (config) {
          instances.set(form, setupForm(form, config));
        }
      });
    },
    detach(context, settings, trigger) {
      // Only 'unload' means the form is going away. Drupal also detaches with
      // 'serialize' before an AJAX submit, and releasing the listeners then
      // would leave a form that the response does not replace permanently
      // unwired, because its `once` mark survives.
      if (trigger !== 'unload') {
        return;
      }
      once.filter(ONCE_ID, FORM_SELECTOR, context).forEach((form) => {
        const instance = instances.get(form);
        if (instance) {
          instance.teardown();
          instances.delete(form);
        }
      });
    },
  };
})(Drupal, drupalSettings, once);
