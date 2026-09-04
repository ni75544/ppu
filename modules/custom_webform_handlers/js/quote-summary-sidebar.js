/**
 * @file
 * One accessible, non-binding quote summary region per configured Webform.
 *
 * This behavior renders only. It never contacts the preview endpoint: it
 * displays what the distance calculator broadcasts, what the configured hidden
 * quote-breakdown element contains, and — on wizard pages that no longer render
 * the address inputs — the last preview persisted for this Webform.
 *
 * The live-status node is created once and only its text is replaced, so screen
 * readers announce each update instead of losing the region on every render.
 */
(function (Drupal, drupalSettings, once) {
  'use strict';

  /** A persisted preview older than this is treated as stale and dropped. */
  const STORAGE_TTL_MS = 30 * 60 * 1000;

  /** Distinguishes regions when more than one Webform renders on a page. */
  let regionCounter = 0;

  /**
   * The Webforms this experience attaches to.
   *
   * The handler marks the form it alters, and publishes the matching
   * webform_id in its settings. Both must agree before a region is built, so a
   * second Webform sharing the page is never decorated.
   */
  const FORM_SELECTOR = 'form[data-custom-webform-quote]';

  /**
   * Escapes a value for use inside a double-quoted attribute selector.
   */
  function attr(value) {
    return String(value).replace(/\\/g, '\\\\').replace(/"/g, '\\"');
  }

  /**
   * Returns the settings belonging to one form, or null.
   *
   * The handler publishes this bucket whether or not a browser API key exists,
   * because the region renders the stored breakdown either way. A bucket that
   * names a different Webform belongs to a different form on this page, so it
   * is refused rather than borrowed.
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

  function element(tag, className, text) {
    const node = document.createElement(tag);
    if (className) {
      node.className = className;
    }
    if (text) {
      node.textContent = text;
    }
    return node;
  }

  function money(value, currency) {
    const amount = Number(value);
    if (!Number.isFinite(amount)) {
      return '';
    }
    try {
      return new Intl.NumberFormat(document.documentElement.lang || 'en', {
        style: 'currency',
        currency: currency || 'USD',
      }).format(amount);
    } catch (error) {
      return `${currency || 'USD'} ${amount.toFixed(2)}`;
    }
  }

  /**
   * Parses a quote payload, rejecting anything that is not a plain object.
   */
  function parseQuote(value) {
    if (!value || typeof value !== 'string') {
      return null;
    }
    try {
      const quote = JSON.parse(value);
      return quote && typeof quote === 'object' && !Array.isArray(quote)
        ? quote
        : null;
    } catch (error) {
      return null;
    }
  }

  /**
   * Builds the region skeleton, reusing the Webform's own preview container when
   * the form provides one so a page never ends up with two summary regions.
   */
  function buildRegion(form, webformId) {
    const existing = form.querySelector('[data-quote-summary-sidebar]');
    if (existing) {
      return {
        panel: existing,
        status: existing.querySelector('[data-quote-summary-status]'),
        body: existing.querySelector('[data-quote-summary-body]'),
      };
    }

    regionCounter += 1;
    const id = `custom-quote-summary--${webformId}-${regionCounter}`;
    const host = form.querySelector('[data-custom-quote-preview]');
    const panel = host || element('aside');

    if (host) {
      // The Webform ships a static placeholder with its own live region. Adopt
      // the element and take over announcements from the status node below.
      // Its legacy class is dropped so only this component's styles apply.
      host.replaceChildren();
      host.classList.remove('shipping-quote-preview');
      host.removeAttribute('aria-live');
      host.setAttribute('role', 'complementary');
    }

    panel.id = id;
    panel.classList.add('custom-quote-summary-sidebar');
    panel.setAttribute('data-quote-summary-sidebar', '');
    panel.setAttribute('aria-labelledby', `${id}-title`);
    // The panel scrolls independently once it is sticky, so it must be
    // reachable and scrollable by keyboard.
    panel.setAttribute('tabindex', '0');

    const header = element('div', 'custom-quote-summary-sidebar__header');
    header.appendChild(
      element(
        'p',
        'custom-quote-summary-sidebar__eyebrow',
        Drupal.t('Live quote'),
      ),
    );
    const title = element(
      'h2',
      'custom-quote-summary-sidebar__title',
      Drupal.t('Your estimate'),
    );
    title.id = `${id}-title`;
    header.appendChild(title);

    const status = element('p', 'custom-quote-summary-sidebar__status');
    status.id = `${id}-status`;
    status.setAttribute('data-quote-summary-status', '');
    status.setAttribute('role', 'status');
    status.setAttribute('aria-live', 'polite');
    status.setAttribute('aria-atomic', 'true');
    header.appendChild(status);

    const body = element('div', 'custom-quote-summary-sidebar__body');
    body.setAttribute('data-quote-summary-body', '');

    panel.append(header, body);

    // Lay the quote beside the route map when there is room, stacked otherwise.
    // The main slot stays empty until the route-map behavior claims it.
    const experience = element('div', 'custom-webform-quote-experience');
    experience.setAttribute('data-custom-quote-experience', '');
    const main = element('div', 'custom-webform-quote-experience__main');
    main.setAttribute('data-custom-quote-experience-main', '');

    if (host) {
      host.insertAdjacentElement('beforebegin', experience);
    } else {
      const progress = form.querySelector('.webform-progress');
      if (progress) {
        progress.insertAdjacentElement('afterend', experience);
      } else {
        form.insertAdjacentElement('afterbegin', experience);
      }
    }
    experience.append(panel, main);

    return { panel, status, body };
  }

  /**
   * Wires and renders the summary region for one Webform.
   */
  function setupForm(form, config) {
    const webformId = config.webform_id || 'webform';
    const fieldNames = config.field_names || {};
    const breakdownName = fieldNames.quote_breakdown || '';
    // Namespaced off the calculator's own key so the stored preview and the
    // stored wizard input always expire together. Without a configured key
    // nothing is persisted, rather than falling back to a key other forms
    // could collide with.
    const storageKey = config.quote_context_key
      ? `${config.quote_context_key}:preview`
      : '';
    const region = buildRegion(form, webformId);
    const { panel, status, body } = region;

    let quote = null;

    function readStoredQuote() {
      if (!storageKey) {
        return null;
      }
      try {
        const raw = window.sessionStorage.getItem(storageKey);
        if (!raw) {
          return null;
        }
        const parsed = JSON.parse(raw);
        const savedAt = parsed ? Number(parsed.at) : NaN;
        if (
          !Number.isFinite(savedAt) ||
          Date.now() - savedAt > STORAGE_TTL_MS
        ) {
          window.sessionStorage.removeItem(storageKey);
          return null;
        }
        const stored = parsed.quote;
        return stored && typeof stored === 'object' && !Array.isArray(stored)
          ? stored
          : null;
      } catch (error) {
        return null;
      }
    }

    function storeQuote(value) {
      if (!storageKey) {
        return;
      }
      try {
        if (value) {
          window.sessionStorage.setItem(
            storageKey,
            JSON.stringify({ at: Date.now(), quote: value }),
          );
        } else {
          window.sessionStorage.removeItem(storageKey);
        }
      } catch (error) {
        // Session storage is optional; this page still renders live updates.
      }
    }

    function quoteFromField() {
      if (!breakdownName) {
        return null;
      }
      const field = form.querySelector(`[name="${attr(breakdownName)}"]`);
      return field ? parseQuote(field.value) : null;
    }

    /**
     * The Dispatch offer endpoint's latest booking verdict, or NULL.
     *
     * Published by the Slot widget and relayed from the server; this file never
     * derives it. NULL means no Dispatch offer has answered for this form — every
     * Webform without the Slot widget stays exactly as it was.
     */
    let bookingState = null;

    /**
     * The last state and message rendered, so a booking verdict can re-render
     * without inventing a readiness the quote has not reached.
     */
    let lastState = 'waiting';
    let lastMessage = '';

    /**
     * Whether the calculator's only complaint is an answer nobody has given yet.
     *
     * The calculator's reason vocabulary already draws this line: a reason
     * prefixed `missing_` names a field the visitor has not filled in, while
     * every other reason states something about the job itself that a person has
     * to read — restricted contents, a specialty item, an add-on. So an
     * unfinished request is recognised from the server's own words, and no copy
     * of the pricing rules is kept here.
     *
     * An empty reason list is never unfinished: a quote reaches manual_review
     * only by naming at least one reason, so an absent list means the payload is
     * not one this branch understands, and the safe reading is the review it
     * already claims.
     */
    function unfinished() {
      if (!quote || quote.status !== 'manual_review') {
        return false;
      }
      const reasons = Array.isArray(quote.manual_review_reasons)
        ? quote.manual_review_reasons
        : [];
      return (
        reasons.length > 0 &&
        reasons.every((reason) => String(reason).indexOf('missing_') === 0)
      );
    }

    /**
     * Whether this request needs a person, according to either server authority.
     *
     * Two server-side authorities can each send a request to staff, and they
     * answer different questions: the calculator decides whether the price can be
     * derived, and the Dispatch eligibility policy decides whether the job can be
     * self-booked. A request refused by either one is not self-bookable, so the
     * summary must not present it as a finished price beside a Slot list — that
     * contradiction is what this predicate exists to prevent. Neither rule is
     * re-implemented here; both verdicts arrive from the server.
     *
     * A request that is merely unfinished is not one of those refusals. Telling
     * someone who has not yet said what the package weighs that their move
     * cannot be priced automatically states a verdict about a job nobody has
     * described yet, so an unfinished quote is excluded — but only when the
     * booking side has not independently refused the job, whose verdict is about
     * the request as a whole and outranks a half-filled pricing form.
     */
    function needsReview() {
      if (bookingState === 'manual_review') {
        return true;
      }
      return !!(quote && quote.status === 'manual_review') && !unfinished();
    }

    function statusText(state) {
      switch (state) {
        case 'pending':
          return Drupal.t('Updating your estimate…');

        case 'error':
          return Drupal.t('Estimate not updated');

        case 'ready':
          if (unfinished()) {
            return Drupal.t('Estimate not complete');
          }
          return needsReview()
            ? Drupal.t('Estimate needs review')
            : Drupal.t('Estimate updated');

        default:
          return Drupal.t('Waiting for route details');
      }
    }

    /**
     * Renders the current quote with DOM APIs only — no HTML interpolation, so
     * server or stored strings can never become markup.
     */
    function render(state, message) {
      lastState = state;
      lastMessage = message || '';
      panel.dataset.state = state;
      panel.classList.toggle('is-updating', state === 'pending');
      panel.setAttribute('aria-busy', state === 'pending' ? 'true' : 'false');
      status.textContent = statusText(state);

      if (state === 'pending' && body.childElementCount) {
        // Keep the previous figures on screen while the new ones are fetched.
        return;
      }

      body.replaceChildren();

      if (!quote) {
        const empty = element(
          'p',
          state === 'error'
            ? 'custom-quote-summary-sidebar__empty custom-quote-summary-sidebar__error'
            : 'custom-quote-summary-sidebar__empty',
          message ||
            Drupal.t(
              'Enter both addresses and your move details to see an itemised preliminary estimate.',
            ),
        );
        body.appendChild(empty);
        return;
      }

      const distanceMiles = Number(
        quote.distance && quote.distance.distance_miles,
      );
      if (Number.isFinite(distanceMiles) && distanceMiles > 0) {
        body.appendChild(
          element(
            'p',
            'custom-quote-summary-sidebar__distance',
            Drupal.t('Driving route: @miles mi', {
              '@miles': distanceMiles.toFixed(2),
            }),
          ),
        );
      }

      // Figures are withheld while the request is unfinished. The calculator
      // still returns priced lines for the parts it can price — a courier base
      // charge is owed whatever the package weighs — and a partial itemisation
      // reads as a price. The route is a fact the visitor supplied, so it stays.
      const incomplete = unfinished();

      const lineItems = Array.isArray(quote.line_items) ? quote.line_items : [];
      if (!incomplete && lineItems.length) {
        const list = element('ul', 'custom-quote-summary-sidebar__items');
        lineItems.forEach((item) => {
          if (!item || !item.label) {
            return;
          }
          const row = element('li', 'custom-quote-summary-sidebar__item');
          row.appendChild(
            element(
              'span',
              'custom-quote-summary-sidebar__item-label',
              String(item.label),
            ),
          );
          const amount = money(item.amount, quote.currency);
          if (amount) {
            row.appendChild(
              element(
                'span',
                'custom-quote-summary-sidebar__item-amount',
                amount,
              ),
            );
          }
          list.appendChild(row);
        });
        if (list.childElementCount) {
          body.appendChild(list);
        }
      }

      const total = Number(quote.total);
      if (incomplete) {
        body.appendChild(
          element(
            'p',
            'custom-quote-summary-sidebar__note',
            Drupal.t(
              'Complete the remaining required details to see your estimate.',
            ),
          ),
        );
      } else if (needsReview()) {
        // Text plus an icon glyph: the state never depends on colour alone.
        const badge = element(
          'p',
          'custom-webform-manual-review-badge custom-quote-summary-sidebar__manual-review-badge',
        );
        const icon = element(
          'span',
          'custom-webform-manual-review-badge__icon',
          '⚠',
        );
        icon.setAttribute('aria-hidden', 'true');
        badge.appendChild(icon);
        badge.appendChild(
          element('span', '', Drupal.t('Pending manual review')),
        );
        body.appendChild(badge);
        body.appendChild(
          element(
            'p',
            'custom-quote-summary-sidebar__note',
            Number.isFinite(total) && total > 0
              ? Drupal.t(
                  'Some details need a person to confirm them before this becomes a final price.',
                )
              : Drupal.t(
                  'This move cannot be priced automatically. Submit the form and we will confirm the price.',
                ),
          ),
        );
      } else if (Number.isFinite(total) && total >= 0) {
        const totalRow = element('div', 'custom-quote-summary-sidebar__total');
        totalRow.appendChild(
          element(
            'span',
            'custom-quote-summary-sidebar__total-label',
            Drupal.t('Instant estimate'),
          ),
        );
        totalRow.appendChild(
          element(
            'strong',
            'custom-quote-summary-sidebar__total-amount',
            money(total, quote.currency),
          ),
        );
        body.appendChild(totalRow);
      }

      if (state === 'error' && message) {
        body.appendChild(
          element('p', 'custom-quote-summary-sidebar__error', message),
        );
      }

      // Which disclosure applies is the server's verdict, not a reading of the
      // copy above: a request staff must price keeps the review wording, an
      // automatically priced one says plainly that the figure is an instant
      // estimate and what would move it, and an unfinished one claims neither,
      // because no estimate has been reached to qualify. The collapsed legal
      // disclosure the Webform renders separately is unaffected either way.
      let disclaimer = Drupal.t(
        'This is an instant estimate calculated from the details you entered. It can change if the pickup or dropoff address, the package details, or the service you request differs materially from what you submitted.',
      );
      if (incomplete) {
        disclaimer = Drupal.t(
          'No estimate yet. This summary updates as you complete the required details.',
        );
      } else if (needsReview()) {
        disclaimer = Drupal.t(
          'Preliminary, non-binding estimate. The final price is confirmed after review.',
        );
      }
      body.appendChild(
        element('p', 'custom-quote-summary-sidebar__disclaimer', disclaimer),
      );
    }

    form.addEventListener('custom-webform-quote-pending', () => {
      render('pending');
    });

    form.addEventListener('custom-webform-quote-updated', (event) => {
      quote = event.detail && event.detail.quote ? event.detail.quote : null;
      storeQuote(quote);
      render(quote ? 'ready' : 'waiting');
    });

    form.addEventListener('custom-webform-quote-cleared', (event) => {
      const detail = event.detail || {};
      quote = null;
      storeQuote(null);
      const recoverable = detail.code && detail.code !== 'incomplete_addresses';
      render(recoverable ? 'error' : 'waiting', detail.message || '');
    });

    // The Dispatch offer endpoint's verdict, relayed by the Slot widget. Kept as
    // the server sent it and only re-rendered here: the request's readiness is
    // still the quote's own, so a booking verdict cannot promote a pending
    // estimate to a finished one, and a stale Manual Review cannot outlive the
    // facts that caused it once the widget drops back to pending.
    form.addEventListener('custom-webform-booking-state', (event) => {
      const detail = event.detail || {};
      const next = detail.state || null;
      if (next === bookingState) {
        return;
      }
      bookingState = next;
      render(lastState, lastMessage);
    });

    // Keep the region in step with the hidden breakdown element, whether the
    // calculator or a Webform AJAX rebuild changed it.
    if (breakdownName) {
      const syncFromField = (event) => {
        if (!event.target || event.target.name !== breakdownName) {
          return;
        }
        quote = parseQuote(event.target.value);
        storeQuote(quote);
        render(quote ? 'ready' : 'waiting');
      };
      form.addEventListener('input', syncFromField);
      form.addEventListener('change', syncFromField);
    }

    quote = quoteFromField() || readStoredQuote();
    render(quote ? 'ready' : 'waiting');
  }

  Drupal.behaviors.customWebformQuoteSummary = {
    attach(context, settings) {
      once('custom-webform-quote-summary', FORM_SELECTOR, context).forEach(
        (form) => {
          const config = settingsFor(form, settings);
          if (config) {
            setupForm(form, config);
          }
        },
      );
    },
  };
})(Drupal, drupalSettings, once);
