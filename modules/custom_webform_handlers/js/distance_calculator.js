/**
 * @file
 * Address autocomplete and server-authoritative distance/quote preview.
 *
 * This behavior is the only client-side owner of the preview request: it reads
 * the wizard's pricing inputs, asks the site's own endpoint for a distance and
 * preliminary quote, and broadcasts the result as DOM events. The quote summary
 * and route map render those events; they never call the endpoint themselves.
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

  /** How long to wait for a third-party Maps bootstrap before giving up. */
  const MAPS_WAIT_MS = 10000;

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

  function mapsPlacesReady() {
    return (
      typeof google !== 'undefined' &&
      google.maps &&
      (typeof google.maps.importLibrary === 'function' ||
        (google.maps.places &&
          typeof google.maps.places.Autocomplete === 'function'))
    );
  }

  /**
   * Loads the Maps JavaScript API once per page and resolves when it is usable.
   *
   * With loading=async the script's load event can fire before importLibrary()
   * or the legacy Places namespace exist, so readiness is polled rather than
   * inferred from the script event.
   */
  function loadMaps(apiKey) {
    if (mapsLoader) {
      return mapsLoader;
    }

    mapsLoader = new Promise((resolve, reject) => {
      const waitForReady = () => {
        if (mapsPlacesReady()) {
          resolve();
          return;
        }
        const startedAt = Date.now();
        const timer = window.setInterval(() => {
          if (mapsPlacesReady()) {
            window.clearInterval(timer);
            resolve();
          } else if (Date.now() - startedAt > MAPS_WAIT_MS) {
            window.clearInterval(timer);
            reject(new Error('maps_timeout'));
          }
        }, 200);
      };

      if (
        mapsPlacesReady() ||
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
      script.src = `https://maps.googleapis.com/maps/api/js?key=${encodeURIComponent(apiKey)}&v=weekly&loading=async&libraries=places`;
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

    // Addresses are persisted too: later wizard pages no longer render them but
    // still need them to refresh the preview after a pricing answer changes.
    const persistedNames = contextInputNames
      .concat([fieldNames.origin, fieldNames.destination])
      .filter(Boolean);
    const previewEnabled = Boolean(previewUrl && csrfTokenUrl);

    let previewToken = '';
    let requestSequence = 0;
    let activeAbort = null;
    let refreshTimer = null;
    let lastRequestKey = '';

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

    function setMappedField(key, value) {
      const name = fieldNames[key];
      if (!name) {
        return;
      }
      const element = form.querySelector(`[name="${attr(name)}"]`);
      if (!element) {
        return;
      }
      element.value = value;
      element.dispatchEvent(new Event('input', { bubbles: true }));
      element.dispatchEvent(new Event('change', { bubbles: true }));
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

    async function refreshPreview() {
      if (!previewEnabled) {
        return;
      }

      const input = currentInput();
      const route = addresses(input);
      if (!route.origin || !route.destination) {
        invalidateActivePreview();
        lastRequestKey = '';
        clearPreview(
          Drupal.t('Enter both addresses to see a preliminary estimate.'),
          'incomplete_addresses',
        );
        return;
      }

      const quoteInput = pricingPayload(input);
      const requestKey = JSON.stringify([
        route.origin,
        route.destination,
        quoteInput,
      ]);
      if (requestKey === lastRequestKey) {
        return;
      }
      lastRequestKey = requestKey;

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
      });

      try {
        const response = await postPreview(
          {
            origin: route.origin,
            destination: route.destination,
            quote_input: quoteInput,
          },
          abort.controller.signal,
        );

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

        const distanceMiles = Number(data.distance.distance_miles);
        if (!Number.isFinite(distanceMiles) || distanceMiles <= 0) {
          throw Object.assign(new Error('provider_request_failed'), {
            code: 'provider_request_failed',
          });
        }

        setMappedField('distance', `${distanceMiles.toFixed(2)} mi`);
        setMappedField(
          'distance_meters',
          String(data.distance.distance_meters),
        );
        setMappedField('quote_total', data.quote.total || '');
        setMappedField('quote_currency', data.quote.currency || '');
        setMappedField('quote_status', data.quote.status || '');
        setMappedField('quote_rate_version', data.quote.rate_version || '');
        setMappedField('quote_breakdown', JSON.stringify(data.quote));

        emit('custom-webform-quote-updated', { quote: data.quote });
        emit('custom-webform-route-validated', {
          origin: route.origin,
          destination: route.destination,
          distance: data.distance,
          encodedPolyline: data.distance.encoded_polyline || '',
        });
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
        // Allow the next identical edit to retry a recoverable failure.
        lastRequestKey = '';
        clearPreview(messageFor(code), code);
      } finally {
        window.clearTimeout(abort.timer);
        if (activeAbort === abort) {
          activeAbort = null;
        }
      }
    }

    function scheduleRefresh() {
      window.clearTimeout(refreshTimer);
      refreshTimer = window.setTimeout(refreshPreview, REFRESH_DELAY_MS);
    }

    function isTrackedInput(element) {
      if (!element || !element.name) {
        return false;
      }
      const base = element.name.replace(/\[.*$/, '');
      return persistedNames.indexOf(base) !== -1;
    }

    form.addEventListener('input', (event) => {
      if (!isTrackedInput(event.target)) {
        return;
      }
      persistInput();
      scheduleRefresh();
    });
    form.addEventListener('change', (event) => {
      if (!isTrackedInput(event.target)) {
        return;
      }
      persistInput();
      scheduleRefresh();
    });

    // Seed storage from whatever this wizard page renders. A preview only runs
    // on load when both addresses are already known, so a resumed draft's
    // server-stored breakdown is never wiped before it can be displayed.
    persistInput();
    if (previewEnabled) {
      const known = addresses(currentInput());
      if (known.origin && known.destination) {
        refreshPreview();
      }
    }

    if (hasAddressInputs) {
      originInput.setAttribute('autocomplete', 'street-address');
      destinationInput.setAttribute('autocomplete', 'street-address');
    }

    /**
     * Replaces one address input with a Places widget, keeping the Webform's
     * own input as the submitted value.
     */
    function attachAutocomplete(
      input,
      PlaceAutocompleteElement,
      LegacyAutocomplete,
      placeholder,
    ) {
      if (LegacyAutocomplete) {
        input.setAttribute(
          'placeholder',
          input.getAttribute('placeholder') || placeholder,
        );
        const autocomplete = new LegacyAutocomplete(input, {
          fields: ['formatted_address'],
          types: ['address'],
        });
        autocomplete.addListener('place_changed', () => {
          const place = autocomplete.getPlace();
          const address =
            place && place.formatted_address
              ? place.formatted_address
              : input.value.trim();
          if (!address) {
            return;
          }
          input.value = address;
          input.dispatchEvent(new Event('input', { bubbles: true }));
          input.dispatchEvent(new Event('change', { bubbles: true }));
        });
        return;
      }

      const widget = new PlaceAutocompleteElement();
      widget.id = `${input.id || input.name}--place-autocomplete`;
      widget.placeholder = input.value || placeholder;
      input.insertAdjacentElement('afterend', widget);

      const label = input.id
        ? form.querySelector(`label[for="${attr(input.id)}"]`)
        : null;
      if (label) {
        label.setAttribute('for', widget.id);
      }
      input.classList.add('visually-hidden');
      input.setAttribute('aria-hidden', 'true');
      input.setAttribute('tabindex', '-1');
      input.removeAttribute('required');

      const status = document.createElement('p');
      status.className = 'custom-address-autocomplete-status visually-hidden';
      status.setAttribute('role', 'status');
      status.setAttribute('aria-live', 'polite');
      widget.insertAdjacentElement('afterend', status);

      widget.addEventListener('input', () => {
        // A partially typed address is not a route; drop the stale preview.
        input.value = '';
        invalidateActivePreview();
        lastRequestKey = '';
        persistInput();
        clearPreview(
          Drupal.t('Select a complete address from the suggestions.'),
          'incomplete_addresses',
        );
      });

      widget.addEventListener('gmp-select', async (event) => {
        try {
          const place = event.placePrediction.toPlace();
          await place.fetchFields({ fields: ['formattedAddress'] });
          input.value = place.formattedAddress || '';
          input.dispatchEvent(new Event('input', { bubbles: true }));
          input.dispatchEvent(new Event('change', { bubbles: true }));
          status.textContent = Drupal.t('Address selected.');
        } catch (error) {
          status.classList.remove('visually-hidden');
          status.textContent = Drupal.t(
            'That address could not be loaded. Please choose it again or type it in full.',
          );
        }
      });
    }

    if (!apiKey) {
      return;
    }

    loadMaps(apiKey)
      .then(async () => {
        if (!hasAddressInputs) {
          return;
        }

        let PlaceAutocompleteElement = null;
        let LegacyAutocomplete = null;
        if (typeof google.maps.importLibrary === 'function') {
          ({ PlaceAutocompleteElement } =
            await google.maps.importLibrary('places'));
        } else if (
          google.maps.places &&
          typeof google.maps.places.Autocomplete === 'function'
        ) {
          LegacyAutocomplete = google.maps.places.Autocomplete;
        } else {
          return;
        }
        attachAutocomplete(
          originInput,
          PlaceAutocompleteElement,
          LegacyAutocomplete,
          Drupal.t('Enter origin address'),
        );
        attachAutocomplete(
          destinationInput,
          PlaceAutocompleteElement,
          LegacyAutocomplete,
          Drupal.t('Enter destination address'),
        );
      })
      .catch(() => {
        // Maps and autocomplete are optional; the plain address inputs and the
        // server-side preview keep working when they are unavailable.
      });
  }

  Drupal.behaviors.customWebformDistanceCalculator = {
    attach(context, settings) {
      once(
        'custom-webform-distance-calculator',
        FORM_SELECTOR,
        context,
      ).forEach((form) => {
        const config = settingsFor(form, settings);
        if (config) {
          setupForm(form, config);
        }
      });
    },
  };
})(Drupal, drupalSettings, once);
