(function (Drupal, once, drupalSettings) {
  'use strict';

  const quoteInputNames = new Set([
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
  ]);

  function money(value, currency) {
    const amount = Number(value);
    if (!Number.isFinite(amount)) {
      return '';
    }

    try {
      return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: currency || 'USD',
      }).format(amount);
    } catch (error) {
      return `${currency || 'USD'} ${amount.toFixed(2)}`;
    }
  }

  function parseQuote(value) {
    if (!value || typeof value !== 'string') {
      return null;
    }

    try {
      const quote = JSON.parse(value);
      return quote && typeof quote === 'object' && !Array.isArray(quote) ? quote : null;
    } catch (error) {
      return null;
    }
  }

  function createElement(tag, className, text) {
    const element = document.createElement(tag);
    if (className) {
      element.className = className;
    }
    if (text) {
      element.textContent = text;
    }
    return element;
  }

  function baseName(element) {
    return element && element.name ? element.name.replace(/\[.*$/, '') : '';
  }

  Drupal.behaviors.quoteSummarySidebar = {
    attach(context, settings) {
      const config = settings.custom_webform_handlers || drupalSettings.custom_webform_handlers || {};
      const fieldNames = config.field_names || {};
      const quoteBreakdownName = fieldNames.quote_breakdown || '';
      const previewUrl = config.preview_url || '';
      const csrfTokenUrl = config.csrf_token_url || '';
      const quoteDefaults = config.quote_defaults || {};
      const quoteContextKey = config.quote_context_key || '';
      const quoteStorageKey = quoteContextKey ? `${quoteContextKey}:preview` : '';

      once(
        'quote-summary-sidebar',
        'form.webform-submission-form, form[id^="webform-submission-"]',
        context,
      ).forEach((form) => {
        let latestQuote = null;
        let previewToken = '';
        let activeRequest = null;
        let requestSequence = 0;
        let refreshTimer = null;
        const panel = createElement('aside', 'custom-quote-summary-sidebar');
        panel.setAttribute('aria-label', 'Live quote summary');
        panel.setAttribute('data-quote-summary-sidebar', '');

        const preview = form.querySelector('[data-custom-quote-preview]');
        if (preview) {
          const experience = createElement('div', 'custom-webform-quote-experience');
          experience.setAttribute('data-custom-quote-experience', '');
          const main = createElement('div', 'custom-webform-quote-experience__main');
          main.setAttribute('data-custom-quote-experience-main', '');
          const routePreview = form.querySelector('#route-map-preview');

          preview.insertAdjacentElement('beforebegin', experience);
          main.appendChild(preview);
          if (routePreview) {
            main.appendChild(routePreview);
          }
          experience.append(main, panel);
        } else {
          const progress = form.querySelector('.webform-progress');
          if (progress) {
            progress.insertAdjacentElement('afterend', panel);
          } else {
            form.insertAdjacentElement('afterbegin', panel);
          }
        }

        function render(quote = null, state = 'waiting', message = '') {
          panel.classList.toggle('is-updating', state === 'updating');
          panel.dataset.state = state;
          panel.setAttribute('aria-busy', state === 'updating' ? 'true' : 'false');
          panel.replaceChildren();

          const header = createElement('div', 'custom-quote-summary-sidebar__header');
          header.appendChild(createElement('p', 'custom-quote-summary-sidebar__eyebrow', 'Live quote'));
          header.appendChild(createElement('h2', 'custom-quote-summary-sidebar__title', 'Your estimate'));
          const status = createElement('p', 'custom-quote-summary-sidebar__status');
          status.setAttribute('aria-live', 'polite');
          status.setAttribute('role', 'status');
          header.appendChild(status);
          panel.appendChild(header);

          if (!quote) {
            status.textContent = state === 'updating' ? 'Updating estimate…' : 'Waiting for route details';
            panel.appendChild(createElement(
              'p',
              'custom-quote-summary-sidebar__empty',
              message || 'Enter both addresses and your move details to see an itemized preliminary estimate.',
            ));
            return;
          }

          const distanceMiles = Number(quote.distance && quote.distance.distance_miles);
          status.textContent = state === 'updating'
            ? 'Updating estimate…'
            : state === 'error'
              ? 'Unable to update estimate'
              : quote.status === 'manual_review'
                ? 'Estimate requires review'
                : 'Estimate updated';

          if (Number.isFinite(distanceMiles) && distanceMiles > 0) {
            panel.appendChild(createElement(
              'p',
              'custom-quote-summary-sidebar__distance',
              `Route: ${distanceMiles.toFixed(2)} mi`,
            ));
          }

          const lineItems = Array.isArray(quote.line_items) ? quote.line_items : [];
          if (lineItems.length) {
            const list = createElement('ul', 'custom-quote-summary-sidebar__items');
            lineItems.forEach((item) => {
              if (!item || !item.label) {
                return;
              }
              const row = createElement('li', 'custom-quote-summary-sidebar__item');
              row.appendChild(createElement('span', 'custom-quote-summary-sidebar__item-label', item.label));
              row.appendChild(createElement(
                'span',
                'custom-quote-summary-sidebar__item-amount',
                money(item.amount, quote.currency),
              ));
              list.appendChild(row);
            });
            if (list.childElementCount) {
              panel.appendChild(list);
            }
          }

          const total = Number(quote.total);
          if (quote.status === 'manual_review') {
            const badge = createElement(
              'p',
              'custom-webform-manual-review-badge custom-quote-summary-sidebar__manual-review-badge',
              '⚠️ Pending Manual Review',
            );
            badge.setAttribute('role', 'status');
            badge.setAttribute('aria-live', 'polite');
            panel.appendChild(badge);
          } else if (Number.isFinite(total) && total >= 0) {
            const totalRow = createElement('div', 'custom-quote-summary-sidebar__total');
            totalRow.appendChild(createElement('span', '', 'Estimated total'));
            totalRow.appendChild(createElement(
              'strong',
              'custom-quote-summary-sidebar__total-amount',
              money(total, quote.currency),
            ));
            panel.appendChild(totalRow);
          }

          panel.appendChild(createElement(
            'p',
            'custom-quote-summary-sidebar__disclaimer',
            'Preliminary, non-binding estimate. Final pricing is confirmed after review.',
          ));
        }

        function loadQuoteFromField() {
          if (!quoteBreakdownName) {
            return null;
          }
          const field = form.querySelector(`[name="${quoteBreakdownName}"]`);
          return field ? parseQuote(field.value) : null;
        }

        function readStoredQuote() {
          if (!quoteStorageKey) {
            return null;
          }
          try {
            const value = window.sessionStorage.getItem(quoteStorageKey);
            return value ? parseQuote(value) : null;
          } catch (error) {
            return null;
          }
        }

        function readStoredQuoteInput() {
          if (!quoteContextKey) {
            return {};
          }
          try {
            const value = window.sessionStorage.getItem(quoteContextKey);
            const quoteInput = value ? JSON.parse(value) : {};
            return quoteInput && typeof quoteInput === 'object' && !Array.isArray(quoteInput) ? quoteInput : {};
          } catch (error) {
            return {};
          }
        }

        function storeQuote(quote) {
          if (!quoteStorageKey) {
            return;
          }
          try {
            if (quote) {
              window.sessionStorage.setItem(quoteStorageKey, JSON.stringify(quote));
            } else {
              window.sessionStorage.removeItem(quoteStorageKey);
            }
          } catch (error) {
            // Session storage is optional; the current form still updates live.
          }
        }

        function collectQuoteInput() {
          const quoteInput = {
            ...quoteDefaults,
            ...readStoredQuoteInput(),
          };
          quoteInputNames.forEach((name) => {
            const elements = form.querySelectorAll(`[name="${name}"], [name^="${name}["]`);
            if (!elements.length) {
              return;
            }
            delete quoteInput[name];
            elements.forEach((element) => {
              if ((element.type === 'checkbox' || element.type === 'radio') && !element.checked) {
                return;
              }
              if (element.type === 'checkbox' || element.name.includes('[')) {
                if (!Array.isArray(quoteInput[name])) {
                  quoteInput[name] = [];
                }
                quoteInput[name].push(element.value);
              } else {
                quoteInput[name] = element.value;
              }
            });
          });
          return quoteInput;
        }

        function formContainsAddressInputs() {
          return Boolean(
            fieldNames.origin
            && fieldNames.destination
            && form.querySelector(`[name="${fieldNames.origin}"]`)
            && form.querySelector(`[name="${fieldNames.destination}"]`),
          );
        }

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

        async function requestPreview(payload, signal) {
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

        async function refreshFromPreview() {
          if (formContainsAddressInputs() || !previewUrl || !csrfTokenUrl) {
            return;
          }
          const quoteInput = collectQuoteInput();
          const origin = String(quoteInput[fieldNames.origin] || '').trim();
          const destination = String(quoteInput[fieldNames.destination] || '').trim();
          if (!origin || !destination) {
            render(latestQuote, latestQuote ? 'ready' : 'waiting');
            return;
          }

          const currentRequest = ++requestSequence;
          if (activeRequest) {
            activeRequest.abort();
          }
          activeRequest = new AbortController();

          try {
            const response = await requestPreview({
              origin,
              destination,
              quote_input: quoteInput,
            }, activeRequest.signal);
            const data = await response.json();
            if (currentRequest !== requestSequence) {
              return;
            }
            if (!response.ok || data.status !== 'success' || !data.quote) {
              throw new Error(data.code || 'quote_preview_failed');
            }
            latestQuote = data.quote;
            storeQuote(latestQuote);
            render(latestQuote, 'ready');
          } catch (error) {
            if (error.name === 'AbortError') {
              return;
            }
            render(latestQuote, latestQuote ? 'error' : 'waiting');
          }
        }

        function markUpdating(event) {
          const name = baseName(event.target);
          if (!quoteInputNames.has(name) && name !== fieldNames.origin && name !== fieldNames.destination) {
            return;
          }
          render(latestQuote, 'updating');
          if (!formContainsAddressInputs()) {
            window.clearTimeout(refreshTimer);
            refreshTimer = window.setTimeout(refreshFromPreview, 400);
          }
        }

        form.addEventListener('input', (event) => {
          if (quoteBreakdownName && event.target.name === quoteBreakdownName) {
            latestQuote = parseQuote(event.target.value);
            render(latestQuote, latestQuote ? 'ready' : 'waiting');
            return;
          }
          markUpdating(event);
        });
        form.addEventListener('change', markUpdating);
        form.addEventListener('custom-webform-quote-updated', (event) => {
          latestQuote = event.detail && event.detail.quote ? event.detail.quote : null;
          storeQuote(latestQuote);
          render(latestQuote, latestQuote ? 'ready' : 'waiting');
        });
        form.addEventListener('custom-webform-quote-cleared', (event) => {
          latestQuote = null;
          storeQuote(null);
          render(null, 'waiting', event.detail && event.detail.message ? event.detail.message : 'Update your route details to calculate an estimate.');
        });

        latestQuote = loadQuoteFromField() || readStoredQuote();
        render(latestQuote, latestQuote ? 'ready' : 'waiting');
      });
    },
  };
})(Drupal, once, drupalSettings);
