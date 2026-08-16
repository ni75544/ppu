(function ($, Drupal, drupalSettings) {
  'use strict';

  Drupal.behaviors.distanceCalculator = {
    attach: function (context, settings) {
      const config = settings.custom_webform_handlers || drupalSettings.custom_webform_handlers || {};
      const apiKey = config.google_maps_browser_api_key || '';
      const previewUrl = config.preview_url || '';
      const csrfTokenUrl = config.csrf_token_url || '';
      const quoteDefaults = config.quote_defaults || {};
      const quoteContextKey = config.quote_context_key || `custom_webform_handlers:${window.location.pathname}`;
      const fieldNames = config.field_names || {};
      const quoteInputNames = [
        'service_type',
        'shipment_weight',
        'shipment_weight_unit',
        'shipment_size',
        'shipment_home_size',
        'item_quantity',
        'item_length',
        'item_width',
        'item_height',
        'dimension_unit',
        'move_size_residential',
        'origin_access_conditions',
        'origin_stairs_flights',
        'destination_access_conditions',
        'destination_stairs_flights',
        'parking_situation',
        'specialty_items_residential',
        'additional_services_commercial',
        'building_requirements',
        'timing_of_move',
        'scope_of_move',
        'specialty_items_long_distance',
        'item_to_be_moved',
        'truck_size',
        'rental_duration',
        'estimated_mileage',
        'diy_addons',
        'vehicle_helper_needs',
        'service_speed',
        'packing_services',
        'cleaning_services',
        'disposal_donation_interest',
      ];
      const quoteContextInputNames = [...new Set([
        ...quoteInputNames,
        fieldNames.origin,
        fieldNames.destination,
      ].filter(Boolean))];

      function readStoredQuoteInput() {
        try {
          const value = window.sessionStorage.getItem(quoteContextKey);
          const parsed = value ? JSON.parse(value) : {};
          return parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? parsed : {};
        } catch (error) {
          console.warn('Stored quote context could not be read.');
          return {};
        }
      }

      function collectFieldsFromForm(form, initial = {}, names = quoteInputNames) {
        const quoteInput = JSON.parse(JSON.stringify(initial));
        names.forEach((name) => {
          const elements = form.querySelectorAll(`[name="${name}"], [name^="${name}["]`);
          if (!elements.length) {
            return;
          }
          delete quoteInput[name];
          elements.forEach((element) => {
            if ((element.type === 'checkbox' || element.type === 'radio') && !element.checked) {
              return;
            }
            const value = element.value;
            if (element.type === 'checkbox' || element.name.includes('[')) {
              if (!Array.isArray(quoteInput[name])) {
                quoteInput[name] = [];
              }
              quoteInput[name].push(value);
            } else {
              quoteInput[name] = value;
            }
          });
        });
        return quoteInput;
      }

      once('shipping-quote-context', 'form.webform-submission-form, form[id^="webform-submission-"]', context).forEach((form) => {
        const persistQuoteInput = () => {
          try {
            const quoteInput = collectFieldsFromForm(form, readStoredQuoteInput(), quoteContextInputNames);
            window.sessionStorage.setItem(quoteContextKey, JSON.stringify(quoteInput));
          } catch (error) {
            console.warn('Quote context could not be stored for this wizard page.');
          }
        };
        form.addEventListener('input', persistQuoteInput);
        form.addEventListener('change', persistQuoteInput);
        persistQuoteInput();
      });

      if (!apiKey || !fieldNames.origin || !fieldNames.destination) {
        console.error('Address autocomplete is missing its browser API key or address-field mappings.');
        return;
      }

      const previewEnabled = Boolean(previewUrl && csrfTokenUrl && fieldNames.distance);

      const originSelector = `input[name="${fieldNames.origin}"]`;
      once('distance-calculator', originSelector, context).forEach((fromInput) => {
        const form = fromInput.closest('form');
        const toInput = form ? form.querySelector(`input[name="${fieldNames.destination}"]`) : null;
        const distanceField = form ? form.querySelector(`input[name="${fieldNames.distance}"]`) : null;

        if (!form || !toInput) {
          console.error('The configured origin and destination inputs were not found in the same webform.');
          return;
        }

        if (!previewEnabled || !distanceField) {
          console.info('Address autocomplete is enabled; distance preview is waiting for its URL, CSRF token, or distance-field mapping.');
        }

        fromInput.setAttribute('autocomplete', 'street-address');
        toInput.setAttribute('autocomplete', 'street-address');

        const initAutocompleteAndService = async () => {
          let PlaceAutocompleteElement = null;
          let LegacyAutocomplete = null;

          if (typeof google.maps.importLibrary === 'function') {
            ({PlaceAutocompleteElement} = await google.maps.importLibrary('places'));
          } else if (google.maps.places && typeof google.maps.places.Autocomplete === 'function') {
            LegacyAutocomplete = google.maps.places.Autocomplete;
            console.info('Using the compatible Google Places Autocomplete interface because importLibrary() is unavailable.');
          } else {
            throw new Error('The Google Maps API loaded without a supported Places autocomplete interface. Ensure the first Maps script on this page includes libraries=places.');
          }
          let requestSequence = 0;
          let lastRequestKey = '';
          let activeRequest = null;
          let quoteTimer = null;
          let previewToken = '';

          async function getPreviewToken(forceRefresh = false) {
            if (previewToken && !forceRefresh) {
              return previewToken;
            }
            const response = await fetch(csrfTokenUrl, {
              method: 'GET',
              credentials: 'same-origin',
              cache: 'no-store',
              headers: {'Accept': 'text/plain'},
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

          async function requestDistancePreview(payload, signal) {
            for (let attempt = 0; attempt < 2; attempt += 1) {
              const token = await getPreviewToken(attempt > 0);
              const response = await fetch(previewUrl, {
                method: 'POST',
                credentials: 'same-origin',
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

          function collectQuoteInput() {
            return collectFieldsFromForm(form, {
              ...quoteDefaults,
              ...readStoredQuoteInput(),
            });
          }

          function setMappedField(key, value) {
            const name = fieldNames[key];
            if (!name) {
              return;
            }
            const element = form.querySelector(`[name="${name}"]`);
            if (element) {
              element.value = value;
              element.dispatchEvent(new Event('input', {bubbles: true}));
            }
          }

          function formatMoney(value, currency) {
            try {
              return new Intl.NumberFormat('en-US', {style: 'currency', currency}).format(Number(value));
            } catch (error) {
              return `${currency} ${Number(value).toFixed(2)}`;
            }
          }

          function renderQuote(quote) {
            if (!quote) {
              return;
            }
            setMappedField('quote_total', quote.total || '');
            setMappedField('quote_currency', quote.currency || '');
            setMappedField('quote_status', quote.status || '');
            setMappedField('quote_rate_version', quote.rate_version || '');
            setMappedField('quote_breakdown', JSON.stringify(quote));
            form.dispatchEvent(new CustomEvent('custom-webform-quote-updated', {
              bubbles: true,
              detail: {quote},
            }));

            const preview = form.querySelector('[data-custom-quote-preview]');
            if (!preview) {
              return;
            }
            preview.replaceChildren();
            const heading = document.createElement('h3');
            heading.textContent = 'Preliminary Estimate';
            preview.appendChild(heading);

            const distanceMiles = Number(quote.distance && quote.distance.distance_miles);
            if (Number.isFinite(distanceMiles) && distanceMiles > 0) {
              const distance = document.createElement('p');
              distance.textContent = `Estimated driving distance: ${distanceMiles.toFixed(2)} mi`;
              preview.appendChild(distance);
            }

            const list = document.createElement('ul');
            (quote.line_items || []).forEach((item) => {
              const row = document.createElement('li');
              row.textContent = `${item.label}: ${formatMoney(item.amount, quote.currency)}`;
              list.appendChild(row);
            });
            if (list.childElementCount) {
              preview.appendChild(list);
            }

            if (quote.status === 'manual_review') {
              const badge = document.createElement('p');
              badge.className = 'custom-webform-manual-review-badge';
              badge.setAttribute('role', 'status');
              badge.setAttribute('aria-live', 'polite');
              badge.textContent = '⚠️ Pending Manual Review';
              preview.appendChild(badge);
            } else if (Number(quote.total) > 0) {
              const total = document.createElement('p');
              const strong = document.createElement('strong');
              strong.textContent = `Estimated total: ${formatMoney(quote.total, quote.currency)}`;
              total.appendChild(strong);
              preview.appendChild(total);
            }

            if (quote.status === 'manual_review') {
              const notice = document.createElement('p');
              notice.textContent = Number(quote.total) > 0
                ? 'Some details require manual review before a final quote can be provided.'
                : 'A price cannot be calculated automatically and requires manual review.';
              preview.appendChild(notice);
            }
            const disclaimer = document.createElement('p');
            disclaimer.textContent = 'This is a preliminary, non-binding estimate. Final pricing is confirmed after review.';
            preview.appendChild(disclaimer);
          }

          function clearPreview(message) {
            if (!previewEnabled || !distanceField) {
              return;
            }
            distanceField.value = '';
            setMappedField('distance_meters', '');
            setMappedField('quote_total', '');
            setMappedField('quote_currency', '');
            setMappedField('quote_status', '');
            setMappedField('quote_rate_version', '');
            setMappedField('quote_breakdown', '');
            form.dispatchEvent(new CustomEvent('custom-webform-quote-cleared', {
              bubbles: true,
              detail: {message},
            }));
            form.dispatchEvent(new CustomEvent('custom-webform-route-cleared', {
              bubbles: true,
              detail: {message},
            }));
            const preview = form.querySelector('[data-custom-quote-preview]');
            if (preview) {
              preview.replaceChildren();
              const notice = document.createElement('p');
              notice.textContent = message;
              preview.appendChild(notice);
            }
            distanceField.dispatchEvent(new Event('change', {bubbles: true}));
          }

          async function calculateDistance() {
            if (!previewEnabled || !distanceField) {
              return;
            }
            const fromValue = fromInput.value.trim();
            const toValue = toInput.value.trim();
            if (!fromValue || !toValue) {
              lastRequestKey = '';
              clearPreview('Enter both addresses to calculate a preliminary estimate.');
              return;
            }

            const quoteInput = collectQuoteInput();
            const requestKey = `${fromValue}\u0000${toValue}\u0000${JSON.stringify(quoteInput)}`;
            if (requestKey === lastRequestKey) {
              return;
            }
            lastRequestKey = requestKey;

            const currentRequest = ++requestSequence;
            if (activeRequest) {
              activeRequest.abort();
            }
            activeRequest = new AbortController();

            try {
              const response = await requestDistancePreview({
                origin: fromValue,
                destination: toValue,
                quote_input: quoteInput,
              }, activeRequest.signal);
              const data = await response.json();
              if (currentRequest !== requestSequence) {
                return;
              }
              if (!response.ok || data.status !== 'success' || !data.distance) {
                throw new Error(data.code || 'distance_preview_failed');
              }

              const distanceMiles = Number(data.distance.distance_miles);
              if (!Number.isFinite(distanceMiles) || distanceMiles <= 0) {
                throw new Error('invalid_distance_preview');
              }

              distanceField.value = `${distanceMiles.toFixed(2)} mi`;
              setMappedField('distance_meters', String(data.distance.distance_meters));
              renderQuote(data.quote);
              form.dispatchEvent(new CustomEvent('custom-webform-route-validated', {
                bubbles: true,
                detail: {
                  origin: fromValue,
                  destination: toValue,
                  distance: data.distance,
                  encodedPolyline: data.distance.encoded_polyline || '',
                },
              }));
              distanceField.dispatchEvent(new Event('input', {bubbles: true}));
              distanceField.dispatchEvent(new Event('change', {bubbles: true}));
            } catch (error) {
              if (error.name === 'AbortError') {
                return;
              }
              lastRequestKey = '';
              console.warn('Distance preview failed.');
              clearPreview('The estimate could not be calculated. Check the addresses or try again.');
            }
          }

          async function createAddressWidget(input, placeholder) {
            if (LegacyAutocomplete) {
              input.setAttribute('placeholder', input.getAttribute('placeholder') || placeholder);
              const autocomplete = new LegacyAutocomplete(input, {
                fields: ['formatted_address'],
                types: ['address'],
              });

              autocomplete.addListener('place_changed', () => {
                const place = autocomplete.getPlace();
                const address = place && place.formatted_address
                  ? place.formatted_address
                  : input.value.trim();
                if (!address) {
                  return;
                }
                input.value = address;
                input.dispatchEvent(new Event('input', {bubbles: true}));
                input.dispatchEvent(new Event('change', {bubbles: true}));
                calculateDistance();
              });
              return;
            }

            const widget = new PlaceAutocompleteElement();
            widget.id = `${input.id || input.name}--place-autocomplete`;
            widget.placeholder = input.value || placeholder;
            input.insertAdjacentElement('afterend', widget);

            const label = input.id ? form.querySelector(`label[for="${input.id}"]`) : null;
            if (label) {
              label.setAttribute('for', widget.id);
            }
            input.classList.add('visually-hidden');
            input.setAttribute('aria-hidden', 'true');
            input.setAttribute('tabindex', '-1');
            input.removeAttribute('required');

            const status = document.createElement('p');
            status.className = 'custom-address-autocomplete-status visually-hidden';
            status.setAttribute('aria-live', 'polite');
            widget.insertAdjacentElement('afterend', status);

            widget.addEventListener('input', () => {
              input.value = '';
              lastRequestKey = '';
              clearPreview('Select a complete address from the suggestions.');
            });
            widget.addEventListener('gmp-select', async ({placePrediction}) => {
              try {
                const place = placePrediction.toPlace();
                await place.fetchFields({fields: ['formattedAddress']});
                input.value = place.formattedAddress || '';
                input.dispatchEvent(new Event('input', {bubbles: true}));
                input.dispatchEvent(new Event('change', {bubbles: true}));
                status.textContent = 'Address selected.';
                calculateDistance();
              } catch (error) {
                console.error('Google Places returned a prediction but its address could not be loaded.', error);
                status.classList.remove('visually-hidden');
                status.textContent = 'The selected address could not be loaded. Please try again.';
              }
            });
          }

          await createAddressWidget(fromInput, 'Enter origin address');
          await createAddressWidget(toInput, 'Enter destination address');
          if (fromInput.value && toInput.value) {
            calculateDistance();
          }
          const scheduleQuoteRefresh = (event) => {
            const baseName = event.target && event.target.name
              ? event.target.name.replace(/\[.*$/, '')
              : '';
            if (!quoteInputNames.includes(baseName)) {
              return;
            }
            clearTimeout(quoteTimer);
            quoteTimer = setTimeout(calculateDistance, 400);
          };
          form.addEventListener('input', scheduleQuoteRefresh);
          form.addEventListener('change', scheduleQuoteRefresh);
        };

        const googlePlacesReady = () => typeof google !== 'undefined'
          && google.maps
          && (typeof google.maps.importLibrary === 'function'
            || (google.maps.places && typeof google.maps.places.Autocomplete === 'function'));

        if (googlePlacesReady()) {
          initAutocompleteAndService().catch((error) => console.error('Google Places could not initialize.', error));
          return;
        }

        function waitForGoogleMaps(callback, timeout = 10000) {
          const start = Date.now();
          const interval = setInterval(() => {
            if (googlePlacesReady()) {
              clearInterval(interval);
              Promise.resolve(callback()).catch((error) => console.error('Google Places could not initialize.', error));
            } else if (Date.now() - start > timeout) {
              clearInterval(interval);
              console.warn('Google Maps API timed out.');
            }
          }, 200);
        }

        if (document.querySelector('script[src*="maps.googleapis.com/maps/api/js"]')) {
          waitForGoogleMaps(initAutocompleteAndService);
          return;
        }

        const script = document.createElement('script');
        script.src = `https://maps.googleapis.com/maps/api/js?key=${encodeURIComponent(apiKey)}&v=weekly&loading=async&libraries=places`;
        script.async = true;
        script.defer = true;
        script.onerror = () => console.error('Google Maps JavaScript API failed to load. Check the API key, enabled APIs, billing, Content Security Policy, and HTTP-referrer restrictions.');
        // With loading=async, the script load event may fire before the Maps
        // bootstrap exposes importLibrary() or the legacy Places namespace.
        // Wait for the actual API surface instead of treating load as ready.
        script.onload = () => waitForGoogleMaps(initAutocompleteAndService);
        document.head.appendChild(script);
      });
    }
  };
})(jQuery, Drupal, drupalSettings);
