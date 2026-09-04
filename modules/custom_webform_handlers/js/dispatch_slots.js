/**
 * @file
 * Customer-facing availability widget for the Dispatch Planner.
 *
 * The server is the only authority here. This behavior asks the server which
 * arrivals it will offer for the job the visitor has described so far, renders
 * exactly those offers, and — when the visitor picks one — records that single
 * choice locally. It contains no capacity, working-hours, routing, or pricing
 * logic: every window it can show was derived by the server for this specific
 * job, and every feasibility decision stays on the server.
 *
 * It performs offer and read behavior only. Selecting an arrival does not
 * reserve it: no Hold is created, renewed, or released from the browser, and
 * there is no expiry to display or count down. The one thing a selection writes
 * is the canonical pickup-arrival instant ("at", absolute UTC seconds) into the
 * submission's arrival element, which is the only value Step 3 needs when it
 * starts checkout. Starting checkout — not selecting here — is what creates a
 * Hold, and that happens later and on the server.
 *
 * A hidden marker element is set to "1" while an arrival is selected and cleared
 * otherwise, purely so the rest of the form can react to "an arrival was
 * chosen". It never carries a token, because the browser never sees one.
 *
 * Any change to an address, the service, the package, an add-on, or the date can
 * change what is feasible, so it invalidates the current selection and asks the
 * server again. Request sequencing and an AbortController keep a stale answer
 * from overwriting a newer one.
 */
(function (Drupal, drupalSettings, once) {
  'use strict';

  const FORM_SELECTOR = 'form[data-custom-webform-quote]';
  const ONCE_ID = 'custom-webform-dispatch-slots';

  // Every network call is aborted if it outruns this, so a stalled request can
  // never leave the widget waiting or overwrite a newer answer.
  const REQUEST_TIMEOUT_MS = 12000;
  // Answers to "what changed" are coalesced so typing an address does not fire a
  // request per keystroke.
  const REOFFER_DEBOUNCE_MS = 450;
  // The existing quote behavior preserves multi-page wizard facts for 30
  // minutes. Offers read that context only so Route & Service facts remain
  // available on Package & Quote; it is never used for a Hold or selection.
  const QUOTE_CONTEXT_TTL_MS = 30 * 60 * 1000;
  // While a date is chosen and nothing is selected, the offer list is refreshed
  // so a time that is no longer offerable stops being shown. Availability is
  // advisory, so this only keeps the read fresh; it never touches a selection.
  const OFFER_POLL_MS = 45000;

  /**
   * Escapes a value for safe use inside a CSS attribute selector.
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
   * Returns the dispatch settings belonging to one form, or null.
   *
   * The handler publishes one flat bucket per page and stamps the form it
   * altered with the matching webform_id. A bucket that names a different
   * Webform belongs to a different form on this page, so it is refused rather
   * than borrowed. Dispatch is present only when this form actually does it.
   */
  function settingsFor(form, settings) {
    const bucket = Object.assign(
      {},
      drupalSettings.custom_webform_handlers,
      (settings || {}).custom_webform_handlers,
    );
    const id = form.getAttribute('data-custom-webform-quote') || '';
    if (!id || bucket.webform_id !== id) {
      return null;
    }
    const dispatch = bucket.dispatch;
    if (!dispatch || typeof dispatch !== 'object' || !dispatch.offer_url) {
      return null;
    }
    return { bucket, dispatch };
  }

  /**
   * A POST helper that carries the CSRF header token and refreshes it once.
   *
   * The token is fetched lazily and cached. A 403 is treated as a stale token
   * exactly once — refreshed and retried — after which the failure is real.
   */
  function createPoster(dispatch) {
    let token = '';

    async function fetchToken(forceRefresh) {
      if (token && !forceRefresh) {
        return token;
      }
      const response = await fetch(dispatch.csrf_token_url, {
        method: 'GET',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { Accept: 'text/plain' },
      });
      if (!response.ok) {
        throw new Error('csrf_token_request_failed');
      }
      token = (await response.text()).trim();
      if (!token) {
        throw new Error('csrf_token_request_failed');
      }
      return token;
    }

    return {
      async post(url, payload, options) {
        options = options || {};
        const controller = options.controller || null;
        const send = (value) =>
          fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            cache: 'no-store',
            signal: controller ? controller.signal : undefined,
            headers: {
              'Content-Type': 'application/json',
              'X-CSRF-Token': value,
            },
            body: JSON.stringify(payload),
          });

        let current = await fetchToken(false);
        let response = await send(current);
        if (response.status === 403) {
          current = await fetchToken(true);
          response = await send(current);
        }
        return response;
      },
    };
  }

  /**
   * Reads the current value of every named input present in this form.
   *
   * Mirrors the quote path's collector rule for rule: unchecked boxes are
   * skipped, bracketed names and repeated same-name checkboxes collect into a
   * list, and everything answering once — a lone checkbox, a radio group, a text
   * field — stays scalar. The mirroring is the point, not a coincidence. This
   * collector overlays the current page's DOM onto the context the quote
   * behavior stored from earlier pages, so if the two disagreed about a shape,
   * the value the server received for a field would depend on which page asked
   * for it. Eligibility facts such as one-driver handling and the contents
   * attestation are exactly where that costs something: the server refuses an
   * ambiguous list where it expects one yes/no, and the visitor is quietly told
   * no Slots are available.
   * The result is the unsaved answers the server needs to rebuild the Dispatch
   * Request; nothing here interprets them.
   */
  function collectValues(form, names, initial) {
    const collected = Object.assign({}, initial || {});
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

  /**
   * Reads the quote behavior's short-lived wizard context without writing it.
   *
   * The quote behavior owns persistence and its expiry. Dispatch only needs the
   * prior-page route and Service facts to construct this offer request; no Hold,
   * Slot selection, token, or availability result is stored here.
   */
  function readQuoteContext(key) {
    if (!key) {
      return {};
    }

    const read = (storageKey) => {
      if (!storageKey) {
        return {};
      }
      try {
        const raw = window.sessionStorage.getItem(storageKey);
        if (!raw) {
          return {};
        }
        const parsed = JSON.parse(raw);
        const savedAt = Number(parsed && parsed.at);
        if (
          !parsed ||
          typeof parsed !== 'object' ||
          Array.isArray(parsed) ||
          !Number.isFinite(savedAt) ||
          Date.now() - savedAt > QUOTE_CONTEXT_TTL_MS
        ) {
          return {};
        }
        return parsed.input &&
          typeof parsed.input === 'object' &&
          !Array.isArray(parsed.input)
          ? parsed.input
          : {};
      } catch (error) {
        return {};
      }
    };

    // The first wizard page uses the Webform-wide context key. Drupal assigns
    // a draft UUID on Next, so page two must inherit that short-lived context
    // before overlaying values scoped to the new draft. This is read-only and
    // deliberately excludes slot, hold, and token state.
    const rootKey = String(key).replace(/:[^:]+$/, '');
    return rootKey && rootKey !== key
      ? Object.assign({}, read(rootKey), read(key))
      : read(key);
  }

  /**
   * Returns a scalar from a collected value that may be a single-item list.
   */
  function firstValue(value) {
    if (Array.isArray(value)) {
      return value.length ? String(value[0]).trim() : '';
    }
    return value == null ? '' : String(value).trim();
  }

  /**
   * Formats an absolute instant as a clock time in the server's timezone.
   *
   * The timezone is the one the server returned with the offer, so the arrival a
   * visitor reads is the arrival the driver works, whatever zone the browser is
   * in. A zone the browser cannot resolve falls back to its own, which shifts the
   * label but never the "at" value that is actually booked.
   */
  function formatClock(timestamp, timezone) {
    const options = { hour: 'numeric', minute: '2-digit' };
    if (timezone) {
      options.timeZone = timezone;
    }
    try {
      return new Intl.DateTimeFormat(undefined, options).format(
        new Date(timestamp * 1000),
      );
    } catch (error) {
      return new Intl.DateTimeFormat(undefined, {
        hour: 'numeric',
        minute: '2-digit',
      }).format(new Date(timestamp * 1000));
    }
  }

  /**
   * Formats an absolute instant as a weekday, date, and clock time.
   */
  function formatDayClock(timestamp, timezone) {
    const options = {
      weekday: 'short',
      month: 'short',
      day: 'numeric',
      hour: 'numeric',
      minute: '2-digit',
    };
    if (timezone) {
      options.timeZone = timezone;
    }
    try {
      return new Intl.DateTimeFormat(undefined, options).format(
        new Date(timestamp * 1000),
      );
    } catch (error) {
      delete options.timeZone;
      return new Intl.DateTimeFormat(undefined, options).format(
        new Date(timestamp * 1000),
      );
    }
  }

  /**
   * Adds a whole number of days to a Y-m-d date, in UTC, avoiding DST drift.
   */
  function addDays(ymd, days) {
    const parts = ymd.split('-').map(Number);
    if (parts.length !== 3 || parts.some((value) => !Number.isFinite(value))) {
      return ymd;
    }
    const date = new Date(Date.UTC(parts[0], parts[1] - 1, parts[2]));
    date.setUTCDate(date.getUTCDate() + days);
    const month = String(date.getUTCMonth() + 1).padStart(2, '0');
    const day = String(date.getUTCDate()).padStart(2, '0');
    return `${date.getUTCFullYear()}-${month}-${day}`;
  }

  /**
   * Returns the ISO-8601 weekday, 1 for Monday to 7 for Sunday, of a Y-m-d date.
   *
   * Read in UTC for the same reason addDays is: the calendar date the visitor
   * picked is a plain date, and reading it in the browser's zone would move it a
   * day either side of midnight. Returns 0 for anything unparseable, which no
   * working-weekday set can match.
   */
  function weekdayOf(ymd) {
    const parts = String(ymd).split('-').map(Number);
    if (parts.length !== 3 || parts.some((value) => !Number.isFinite(value))) {
      return 0;
    }
    const day = new Date(Date.UTC(parts[0], parts[1] - 1, parts[2])).getUTCDay();
    return day === 0 ? 7 : day;
  }

  /**
   * Normalises the working weekdays the server published into a lookup set.
   *
   * The server sends the keys of the Resource's working_hours, which are ISO-8601
   * weekdays. A 0 is accepted as Sunday so a hand-edited profile using the other
   * common convention still reads correctly. An empty or absent list yields an
   * empty set, which callers treat as "the server did not say" and therefore never
   * as "no day works" — a missing constraint must not be able to close the
   * calendar the server would happily offer Slots on.
   */
  function workingWeekdaySet(published) {
    const set = new Set();
    if (!Array.isArray(published)) {
      return set;
    }
    published.forEach((value) => {
      const day = Number(value);
      if (!Number.isInteger(day) || day < 0 || day > 7) {
        return;
      }
      set.add(day === 0 ? 7 : day);
    });
    return set;
  }

  /**
   * Formats a Y-m-d date as a weekday name, for naming the next day that works.
   */
  function formatDayName(ymd, timezone) {
    const parts = String(ymd).split('-').map(Number);
    if (parts.length !== 3 || parts.some((value) => !Number.isFinite(value))) {
      return ymd;
    }
    // Noon UTC keeps the label on the intended calendar date in every zone the
    // service can be configured for.
    const at = new Date(Date.UTC(parts[0], parts[1] - 1, parts[2], 12));
    const options = { weekday: 'long', month: 'short', day: 'numeric' };
    if (timezone) {
      options.timeZone = timezone;
    }
    try {
      return new Intl.DateTimeFormat(undefined, options).format(at);
    } catch (error) {
      return new Intl.DateTimeFormat(undefined, {
        weekday: 'long',
        month: 'short',
        day: 'numeric',
      }).format(at);
    }
  }

  /**
   * Wires the availability widget to one form.
   */
  function setupForm(form, dispatch, bucket) {
    const names = dispatch.field_names || {};
    const constraints = dispatch.constraints || {};
    const timezone = constraints.timezone || '';
    // The days the Resource actually works, as published with the other
    // constraints. The planner already refuses every other day; knowing them here
    // is what stops the widget offering a date it can only answer with an empty
    // list, which reads as a fault rather than as a closed day.
    const workingWeekdays = workingWeekdaySet(constraints.working_weekdays);
    const valueFields = Array.isArray(dispatch.value_fields)
      ? dispatch.value_fields
      : [];
    // What each selectable service does, keyed by service type, published by the
    // handler from the one resolver the offer endpoint assesses against: whether
    // Slots are possible at all, which answers that service still needs, and the
    // fixed sentence to show when a person schedules it. No service name and no
    // review wording is written in this file, so opening or closing a service is a
    // configuration change and never a JavaScript one.
    //
    // This map is the only published description of a service. There used to be a
    // second, flattened one beside it — a union of every self-bookable service's
    // required facts plus the list of those services — and the two could disagree
    // about what a service needs the moment a second service opened, because a
    // union asks every open service for every other open service's answers.
    const bookingProfiles = dispatch.booking_profiles &&
      typeof dispatch.booking_profiles === 'object' &&
      !Array.isArray(dispatch.booking_profiles)
      ? dispatch.booking_profiles
      : {};
    const serviceTypeField =
      typeof dispatch.service_type_field === 'string'
        ? dispatch.service_type_field
        : '';
    const quoteDefaults = bucket.quote_defaults &&
      typeof bucket.quote_defaults === 'object' &&
      !Array.isArray(bucket.quote_defaults)
      ? bucket.quote_defaults
      : {};
    const watched = new Set(valueFields);
    const poster = createPoster(dispatch);

    const slotInput = names.slot
      ? form.querySelector(nameSelector(names.slot))
      : null;
    const markerInput = names.hold_marker
      ? form.querySelector(nameSelector(names.hold_marker))
      : null;
    // The one public date field, published by the handler and owned by the
    // Webform. The widget reads it and never writes it, and never bounds it: the
    // same element serves six other services whose horizon is not the dispatch
    // horizon, so narrowing its min/max here would silently shorten theirs. A day
    // the planner cannot work is named by requestOffers instead.
    const dateInput = names.date
      ? form.querySelector(nameSelector(names.date))
      : null;

    // This behavior attaches to each wizard rebuild, while the arrival element
    // intentionally lives only on the Package & Quote page. Earlier and later
    // pages have no place to store a selection, so they must remain untouched.
    if (!slotInput) {
      return null;
    }

    // The Webform's own help text under the date question. It reads as an
    // instruction — "select a date to see available pickup times" — which is true
    // until times are on the screen and then contradicts them. The node is the
    // form's, so its original wording is kept and restored rather than replaced:
    // this file states no help text of its own for the date question except the
    // one line that names the day whose times are showing.
    const dateHelp = dateInput
      ? (dateInput.closest('.js-form-item') || form).querySelector(
          '.form-item__description, .webform-element-description, .description',
        )
      : null;
    const dateHelpDefault = dateHelp ? dateHelp.textContent : '';

    // Request sequencing. A response whose sequence is not the current one lost
    // a race to a newer request and must not touch the DOM or the selection.
    let offerSequence = 0;
    let offerAbort = null;
    let pollTimer = null;
    let reofferTimer = null;
    let stopped = false;
    // The selected arrival instant, or null. Never a token; never a Hold.
    let selection = null;
    let ui = null;
    let lastTimezone = timezone;

    // ---- submission field writers -------------------------------------------

    function fireInput(element) {
      element.dispatchEvent(new Event('input', { bubbles: true }));
      element.dispatchEvent(new Event('change', { bubbles: true }));
    }

    // Writes the one value Step 3 needs: the canonical pickup-arrival instant.
    // The marker mirrors "an arrival is chosen" for the rest of the form. Neither
    // is a token, and no other selection state is stored anywhere.
    function writeArrival(at) {
      if (slotInput) {
        slotInput.value = at ? String(at) : '';
        fireInput(slotInput);
      }
      if (markerInput) {
        markerInput.value = at ? '1' : '';
        fireInput(markerInput);
      }
    }

    function currentDate() {
      return dateInput ? String(dateInput.value || '').slice(0, 10) : '';
    }

    /**
     * Whether the service works on a Y-m-d date at all.
     *
     * A server that published no working weekdays gets the benefit of the doubt:
     * every date is treated as operating, and the offer request decides. Only a
     * published list can rule a day out here.
     */
    function isOperatingDate(ymd) {
      if (!ymd || workingWeekdays.size === 0) {
        return true;
      }
      return workingWeekdays.has(weekdayOf(ymd));
    }

    /**
     * The first operating date on or after a Y-m-d date, or '' if none is in range.
     *
     * Bounded by one week plus the horizon so a profile with an unreachable set can
     * never spin. Returns the input unchanged when nothing was published, which
     * keeps the control's bounds exactly as they were before this was added.
     */
    function nextOperatingDate(ymd) {
      if (!ymd || workingWeekdays.size === 0) {
        return ymd;
      }
      for (let offset = 0; offset <= 7; offset++) {
        const candidate = addDays(ymd, offset);
        if (workingWeekdays.has(weekdayOf(candidate))) {
          return candidate;
        }
      }
      return '';
    }

    // ---- selection ----------------------------------------------------------

    /**
     * Records a chosen arrival locally. No server call, no Hold.
     *
     * The only durable effect is the canonical instant written into the arrival
     * element, which travels with the submission to Step 3. Selecting reserves
     * nothing: capacity is claimed only when checkout starts, on the server.
     */
    function selectArrival(at) {
      selection = { at: at };
      writeArrival(at);
      markSelected(at);
      setStatus(selectedMessage(at));
    }

    /**
     * Clears the current selection locally. No server call, no release.
     *
     * Called when the visitor unpicks a time, and whenever a change to the job
     * invalidates what was chosen. Nothing was reserved, so nothing is returned;
     * the arrival element is simply emptied so a stale instant cannot be
     * submitted.
     */
    function clearSelection() {
      selection = null;
      writeArrival(0);
      markSelected(0);
    }

    // ---- booking state ------------------------------------------------------

    /**
     * The last booking verdict published to the rest of the form.
     */
    let bookingState = null;

    /**
     * The last quote the calculator published, kept only so the price line can be
     * re-rendered when the booking verdict changes. Never re-quoted from here.
     */
    let lastQuote = null;

    /**
     * Publishes the server's booking verdict for the current request.
     *
     * The verdict is the server's and is relayed verbatim — nothing is decided
     * here, and no second copy of the eligibility rules exists on this side. The
     * quote summary subscribes to it so that one request can never present a
     * priced, ready estimate beside a Manual Review offer, nor a Manual Review
     * estimate beside selectable Slots.
     *
     * Emitted only when the verdict changes, because every keystroke on the form
     * can reach this path.
     */
    function emitBookingState(state) {
      if (state === bookingState) {
        return;
      }
      bookingState = state;
      // The verdict decides whether the figure beside the Slots is a bookable
      // instant estimate, so the price line is re-rendered from the quote already
      // in hand. No request is made and no price is recomputed.
      refreshPrice(lastQuote);
      form.dispatchEvent(
        new CustomEvent('custom-webform-booking-state', {
          bubbles: true,
          detail: { state: state, manual_review: state === 'manual_review' },
        }),
      );
    }

    // ---- offers -------------------------------------------------------------

    function abortOffer() {
      if (offerAbort) {
        window.clearTimeout(offerAbort.timer);
        offerAbort.controller.abort();
        offerAbort = null;
      }
    }

    async function requestOffers() {
      if (!ui || stopped) {
        return;
      }
      const date = currentDate();
      const values = currentValues();
      // A service whose profile says a person schedules it, answered from the
      // service alone and without a request. The offer endpoint would refuse this
      // request whatever else the form says, so asking would only delay the same
      // answer — and the branches below would meanwhile tell the visitor to pick a
      // date, or an address, for times that are never going to be offered. The
      // sentence is the server's, published with the profile; nothing about this
      // service's wording is decided here.
      const profile = currentProfile(values);
      if (profile && profile.always_review) {
        renderOffers([], null);
        if (selection) {
          clearSelection();
        }
        setStatus(
          String(profile.review_message || '') || manualReviewMessage(''),
        );
        emitBookingState('manual_review');
        return;
      }
      const origin = firstValue(values[names.origin]);
      const destination = firstValue(values[names.destination]);

      if (!date) {
        renderOffers([], null);
        setStatus(
          Drupal.t('Select a preferred service date to see available pickup times.'),
        );
        emitBookingState('waiting');
        return;
      }
      // A day the service does not work is answered here rather than by the server.
      // The planner returns a successful response with an empty list for such a
      // date, which is correct and unreadable: the visitor sees no times and no
      // reason. Naming the closed day and the next open one costs no request and
      // cannot be mistaken for a fault.
      if (!isOperatingDate(date)) {
        renderOffers([], null);
        const nextOpen = nextOperatingDate(addDays(date, 1));
        setStatus(
          nextOpen
            ? Drupal.t(
                'We do not collect on @closed. The next day we can collect is @open.',
                {
                  '@closed': formatDayName(date, timezone),
                  '@open': formatDayName(nextOpen, timezone),
                },
              )
            : Drupal.t('We do not collect on @closed. Please choose another date.', {
                '@closed': formatDayName(date, timezone),
              }),
        );
        emitBookingState('waiting');
        return;
      }
      if (!origin || !destination) {
        renderOffers([], null);
        setStatus(
          Drupal.t(
            'Enter your pickup and delivery addresses to see available arrival times.',
          ),
        );
        emitBookingState('waiting');
        return;
      }
      // The request does not describe a job yet. The server answers such a request
      // with a refusal, correctly and unhelpfully: a visitor who has not reached
      // the weight question is unfinished, not ineligible, and must not be told
      // that staff will price their job. So the missing answers are named and no
      // request is sent. This is a courtesy, not a gate — every request that is
      // sent is still assessed server-side, and the same request would still be
      // refused if it arrived.
      if (requestUnfinished()) {
        renderOffers([], null);
        setStatus(incompleteMessage());
        emitBookingState('waiting');
        return;
      }

      const seq = ++offerSequence;
      abortOffer();
      const controller = new AbortController();
      const timer = window.setTimeout(
        () => controller.abort(),
        REQUEST_TIMEOUT_MS,
      );
      offerAbort = { controller, timer };
      setBusy(true);
      setStatus(Drupal.t('Finding available arrival times…'), true);
      emitBookingState('pending');
      try {
        const response = await poster.post(
          dispatch.offer_url,
          { date: date, values: values },
          { controller },
        );
        if (seq !== offerSequence) {
          return;
        }
        window.clearTimeout(timer);
        if (!response.ok) {
          renderOffers([], null);
          setStatus(offerFailureMessage());
          emitBookingState('unavailable');
          return;
        }
        const data = await response.json().catch(() => null);
        if (seq !== offerSequence) {
          return;
        }
        if (!data || data.status !== 'success') {
          renderOffers([], null);
          setStatus(offerFailureMessage());
          emitBookingState('unavailable');
          return;
        }
        // The server's own "not finished yet" answer, which is not a refusal and
        // must not read as one. Reached when the browser's precheck could not see
        // the gap — an unmapped element, or a fact the form asks somewhere this
        // widget cannot collect — so the sentence has to be right here too.
        if (data.incomplete) {
          renderOffers([], null);
          setStatus(incompleteMessage());
          emitBookingState('waiting');
          return;
        }
        if (data.manual_review) {
          renderOffers([], null);
          setStatus(manualReviewMessage(data.review_code));
          emitBookingState('manual_review');
          return;
        }
        const slots = Array.isArray(data.slots) ? data.slots : [];
        lastTimezone = data.timezone || timezone;
        renderOffers(slots, lastTimezone);
        if (!slots.length) {
          setStatus(
            Drupal.t(
              'No arrival times are available on that date. Please try another day.',
            ),
          );
          emitBookingState('unavailable');
        } else if (selection) {
          setStatus(selectedMessage(selection.at));
          emitBookingState('available');
        } else {
          setStatus(Drupal.t('Select one of the available arrival times.'));
          emitBookingState('available');
        }
      } catch (error) {
        if (seq !== offerSequence) {
          return;
        }
        window.clearTimeout(timer);
        if (error && error.name === 'AbortError') {
          return;
        }
        renderOffers([], null);
        setStatus(connectionMessage());
        emitBookingState('unavailable');
      } finally {
        if (offerAbort && offerAbort.controller === controller) {
          offerAbort = null;
        }
        setBusy(false);
      }
    }

    /**
     * Coalesces a re-offer after the job changed, dropping any stale selection.
     *
     * Any change to an address, the service, the package, an add-on, or the date
     * can change what is feasible, so the previous offers are invalidated and the
     * current selection is cleared. Feasibility itself is never decided here —
     * only that the server must be asked again.
     *
     * The rendered Slots and the status line are cleared with the selection, not
     * left standing through the debounce. They describe a request that no longer
     * exists, and leaving them up meant a visitor could read — and click — an
     * arrival time computed from the previous package for as long as the debounce
     * lasted. The published booking state drops to pending for the same reason: a
     * verdict about the old facts must not survive into the new ones.
     */
    function scheduleReoffer() {
      if (!ui) {
        return;
      }
      if (reofferTimer) {
        window.clearTimeout(reofferTimer);
      }
      offerSequence += 1;
      abortOffer();
      if (selection) {
        clearSelection();
      }
      renderOffers([], null);
      setStatus(Drupal.t('Updating the arrival times we can offer…'), true);
      emitBookingState('pending');
      reofferTimer = window.setTimeout(() => {
        reofferTimer = null;
        requestOffers();
      }, REOFFER_DEBOUNCE_MS);
    }

    // ---- rendering ----------------------------------------------------------

    /**
     * Formats a decimal amount as currency, falling back to a plain string.
     *
     * The figure comes from the server's quote; this only presents it. A locale
     * or currency the browser cannot format degrades to a readable fallback
     * rather than throwing.
     */
    function formatMoney(value, currency) {
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
     * Renders exactly the arrivals the server offered. Nothing is invented here.
     *
     * Each offer becomes a real button, so it is reachable and operable by
     * keyboard and announced as a button by assistive technology, carrying the
     * arrival time, the pickup promise window, and the estimated delivery the
     * server computed for this job. Choosing one toggles the selection; there is
     * no reservation to make and no expiry to draw.
     */
    function renderOffers(slots, tz) {
      if (!ui) {
        return;
      }
      ui.list.replaceChildren();
      const zone = tz || lastTimezone || timezone;
      (Array.isArray(slots) ? slots : []).forEach((slot) => {
        const at = Number(slot.at);
        if (!Number.isFinite(at) || at <= 0) {
          return;
        }
        const item = document.createElement('li');
        item.className = 'custom-webform-dispatch__item';

        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'custom-webform-dispatch__slot';
        button.setAttribute('data-at', String(at));
        button.setAttribute('aria-pressed', 'false');

        const time = document.createElement('span');
        time.className = 'custom-webform-dispatch__slot-time';
        time.textContent = formatClock(at, zone);
        button.appendChild(time);

        const promiseStart = Number(slot.promise_start);
        const promiseEnd = Number(slot.promise_end);
        if (Number.isFinite(promiseStart) && Number.isFinite(promiseEnd)) {
          const promise = document.createElement('span');
          promise.className = 'custom-webform-dispatch__slot-promise';
          promise.textContent = Drupal.t('Pickup @from–@to', {
            '@from': formatClock(promiseStart, zone),
            '@to': formatClock(promiseEnd, zone),
          });
          button.appendChild(promise);
        }

        const eta = Number(slot.estimated_delivery_at);
        if (Number.isFinite(eta) && eta > 0) {
          const delivery = document.createElement('span');
          delivery.className = 'custom-webform-dispatch__slot-eta';
          delivery.textContent = Drupal.t('Est. delivery @when', {
            '@when': formatDayClock(eta, zone),
          });
          button.appendChild(delivery);
        }

        button.addEventListener('click', () => {
          if (selection && selection.at === at) {
            clearSelection();
            setStatus(Drupal.t('Select one of the available arrival times.'));
          } else {
            selectArrival(at);
          }
        });

        item.appendChild(button);
        ui.list.appendChild(item);
      });

      markSelected(selection ? selection.at : 0);
      // The date question's help text follows the list rather than the request, so
      // it is right in every branch that empties the list without either naming
      // itself here or being forgotten.
      setDateHelp(ui.list.children.length ? currentDate() : null);
    }

    /**
     * Reflects the current selection on the rendered buttons only.
     */
    function markSelected(at) {
      if (!ui) {
        return;
      }
      const target = at ? String(at) : '';
      ui.list
        .querySelectorAll('.custom-webform-dispatch__slot')
        .forEach((button) => {
          const isSelected =
            target !== '' && button.getAttribute('data-at') === target;
          button.classList.toggle(
            'custom-webform-dispatch__slot--selected',
            isSelected,
          );
          button.setAttribute('aria-pressed', isSelected ? 'true' : 'false');
        });
    }

    /**
     * TRUE when a server authority says a person must price this request.
     *
     * Two authorities answer different questions — the calculator decides whether
     * the price can be derived, the Dispatch eligibility policy decides whether
     * the job can be self-booked — and a request refused by either one is not
     * bookable. Both verdicts arrive from the server and neither rule is
     * re-implemented here; the only status the calculator emits for a request
     * staff must price is 'manual_review'.
     *
     * This mirrors needsReview() in quote-summary-sidebar.js on purpose, so the
     * price line and the summary can never disagree: change both or neither.
     *
     * The pair agrees on a second rule that neither predicate states alone — an
     * unfinished request shows no review wording — but each reaches it from the
     * evidence its own file holds. Here requestUnfinished() checks the published
     * required_facts against the live form values, ahead of this call in
     * refreshPrice(); the summary has no form values, only the quote, so it reads
     * the calculator's own reason vocabulary, where a `missing_` prefix names an
     * answer nobody has given. On the Courier path the two cannot drift, because
     * every required fact the profile names is also a `missing_` reason the
     * calculator emits for that same field.
     */
    function needsReview(quote) {
      return (
        (!!quote && quote.status === 'manual_review') ||
        bookingState === 'manual_review'
      );
    }

    /**
     * Shows the authoritative quote total, or nothing when there is no quote.
     *
     * The number shown is the server's, taken from the quote event the calculator
     * emits; this never computes or adjusts a price.
     *
     * Whether that number is a bookable instant estimate is decided by the server
     * statuses needsReview() reads, never by inspecting copy: the calculator emits
     * 'estimated' or 'manual_review' and nothing else, so an allow-list of any
     * other status would silently label every quote as unpriced. A request either
     * authority refused shows review wording and no figure at all, so it can never
     * read as a bookable price.
     */
    function refreshPrice(quote) {
      if (!ui) {
        return;
      }
      lastQuote = quote && typeof quote === 'object' ? quote : null;
      if (!lastQuote) {
        ui.price.textContent = '';
        return;
      }
      // An unfinished request has no price and has not been judged. The calculator
      // still answers it — a package with no weight cannot be priced, so it comes
      // back as 'manual_review' — and repeating that verdict here would tell a
      // visitor mid-form that staff will price their job. There is nothing to show
      // yet, so nothing is shown; the status line asks for the missing answers.
      if (requestUnfinished()) {
        ui.price.textContent = '';
        return;
      }
      if (needsReview(lastQuote)) {
        ui.price.textContent = Drupal.t(
          'Manual review required — staff will price this request.',
        );
        return;
      }
      const formatted = formatMoney(lastQuote.total, lastQuote.currency);
      ui.price.textContent = formatted
        ? Drupal.t('Instant estimate: @price', { '@price': formatted })
        : '';
    }

    /**
     * Shows the route distance the calculator validated, or nothing.
     */
    function refreshDistance(distance) {
      if (!ui) {
        return;
      }
      const miles = distance ? Number(distance.distance_miles) : NaN;
      ui.distance.textContent =
        Number.isFinite(miles) && miles > 0
          ? Drupal.t('Distance: @miles mi', { '@miles': miles.toFixed(2) })
          : '';
    }

    // ---- status -------------------------------------------------------------

    /**
     * The published profile of the service the form currently names, or null.
     *
     * Null for a service the map does not name, which is a page rendered before
     * profiles were published or a service the handler could not resolve. The
     * callers treat null as "ask the server", which is what this widget did for
     * every service before the map existed: the offer endpoint assesses each
     * request it receives, so the map only ever saves a round trip.
     */
    function currentProfile(values) {
      if (!serviceTypeField) {
        return null;
      }
      const service = String(firstValue(values[serviceTypeField]) || '').trim();
      if (!service) {
        return null;
      }

      return Object.prototype.hasOwnProperty.call(bookingProfiles, service)
        ? bookingProfiles[service]
        : null;
    }

    /**
     * Names the day whose times are showing, or restores the form's own help text.
     *
     * Called from renderOffers() and nowhere else, so the invariant holds by
     * construction: the instruction to pick a date cannot be left standing beside
     * pickup times that are already on the screen, and it comes back the moment the
     * list empties for any reason — a closed day, a changed package, a failed
     * request, or a service a person schedules.
     */
    function setDateHelp(ymd) {
      if (!dateHelp) {
        return;
      }
      dateHelp.textContent = ymd
        ? Drupal.t('Available pickup times for @day.', {
            '@day': formatDayName(ymd, timezone),
          })
        : dateHelpDefault;
    }

    function setStatus(message) {
      if (ui && ui.status) {
        ui.status.textContent = message || '';
      }
    }

    function setBusy(isBusy) {
      if (!ui) {
        return;
      }
      ui.root.classList.toggle('is-busy', !!isBusy);
      if (isBusy) {
        ui.root.setAttribute('aria-busy', 'true');
      } else {
        ui.root.removeAttribute('aria-busy');
      }
    }

    /**
     * Explains a refusal, naming the visitor's own answer where the server named it.
     *
     * The server publishes a code only for refusals the visitor themselves declared,
     * so every sentence here repeats a decision they already made and discloses no
     * configured threshold. An unrecognised or generic code falls back to the plain
     * wording, which is what over_weight_limit deliberately arrives as.
     */
    function manualReviewMessage(code) {
      const explanations = {
        two_person_handling: Drupal.t(
          'You told us this needs two people to carry, which our self-service courier cannot schedule. Submit the form and we will follow up with times.',
        ),
        contents_or_add_ons: Drupal.t(
          'The contents or extra services you selected need a person to confirm. Submit the form and we will follow up with times.',
        ),
        store_pickup_not_ready: Drupal.t(
          'A store pickup has to be paid for and ready to collect before we can schedule it. Submit the form and we will follow up with times.',
        ),
        unsupported_delivery_mode: Drupal.t(
          'We schedule this delivery type by hand. Submit the form and we will follow up with times.',
        ),
        unsupported_service: Drupal.t(
          'We schedule this service by hand. Submit the form and we will follow up with times.',
        ),
      };

      return (
        explanations[String(code || '')] ||
        Drupal.t(
          'These details need a person to confirm the schedule. Submit the form and we will follow up with times.',
        )
      );
    }

    /**
     * Asks for the answers the request is still missing, without judging the job.
     *
     * Kept deliberately distinct from manualReviewMessage(): a visitor who has not
     * reached the weight question has not asked for staff attention, and telling
     * them their job needs review is both wrong and a reason to stop filling in
     * the form.
     */
    function incompleteMessage() {
      return Drupal.t(
        'Complete the required courier details to see available pickup times.',
      );
    }

    /**
     * The published required facts this form has not answered yet.
     *
     * Element keys only, taken from the service's own profile: a Manual Review
     * service requires none, because it is refused rather than unfinished, and
     * telling its visitor to complete a courier detail would be the wrong sentence
     * about their request. A value that is an empty string, an empty array, or
     * absent counts as unanswered — the same emptiness the server reads as
     * IncompleteFacts.
     *
     * A service with no profile in the map returns nothing to ask for. That is not
     * a gap: an unrecognised service resolves to Manual Review on the server, so
     * the honest local answer is "nothing is missing, let the server say why" —
     * and the widget's own Manual Review branch is what paints that reason. The
     * flattened fallback that used to sit here answered such a service with the
     * union of every open service's facts, which asked for Courier answers on a
     * page that is not Courier.
     */
    function missingRequiredFacts(values) {
      const unanswered = (field) =>
        String(firstValue(values[field]) || '').trim() === '';

      const profile = currentProfile(values);
      if (!profile) {
        return [];
      }

      return (
        Array.isArray(profile.required_facts) ? profile.required_facts : []
      ).filter(unanswered);
    }

    /** The live answers an offer request would carry, defaults merged in. */
    function currentValues() {
      return collectValues(
        form,
        valueFields,
        Object.assign(
          {},
          quoteDefaults,
          readQuoteContext(bucket.quote_context_key),
        ),
      );
    }

    /**
     * True while the form has not answered every published required fact.
     *
     * Recomputed from the form each time rather than remembered from the last
     * offer attempt: the price line is refreshed by quote events that arrive on
     * code paths which never reach the offer request, and a remembered flag would
     * be answering about whichever request happened to be sent last.
     */
    function requestUnfinished() {
      return missingRequiredFacts(currentValues()).length > 0;
    }

    function offerFailureMessage() {
      return Drupal.t(
        'We could not load arrival times just now. Change a detail to try again.',
      );
    }

    function connectionMessage() {
      return Drupal.t(
        'We could not reach the scheduling service. Check your connection and try again.',
      );
    }

    function selectedMessage(at) {
      return Drupal.t(
        'Pickup selected for @time. You can change it any time before checkout.',
        { '@time': formatClock(at, lastTimezone) },
      );
    }

    // ---- construction -------------------------------------------------------

    /**
     * Builds the widget and anchors it beside the arrival element it feeds.
     *
     * The widget only renders where it can store a choice, so it is built next to
     * the hidden arrival element. It builds no date control of its own: the form
     * already asks for a Preferred Service Date, and a second date input here was
     * a duplicate question whose answer was never submitted — the day the planner
     * planned existed only in this DOM. One fixed label states the timezone, since
     * the arrival is always in the service's zone and there is nothing to choose.
     */
    function buildUi() {
      const root = document.createElement('div');
      root.className = 'custom-webform-dispatch';

      const heading = document.createElement('h3');
      heading.className = 'custom-webform-dispatch__heading';
      heading.textContent = Drupal.t('Choose your pickup time');
      root.appendChild(heading);

      const zoneLabel = document.createElement('p');
      zoneLabel.className = 'custom-webform-dispatch__timezone';
      zoneLabel.textContent = Drupal.t('Times shown in New York local time');
      root.appendChild(zoneLabel);

      const price = document.createElement('p');
      price.className = 'custom-webform-dispatch__price';
      root.appendChild(price);

      const distance = document.createElement('p');
      distance.className = 'custom-webform-dispatch__distance';
      root.appendChild(distance);

      const list = document.createElement('ul');
      list.className = 'custom-webform-dispatch__list';
      root.appendChild(list);

      const status = document.createElement('p');
      status.className = 'custom-webform-dispatch__status';
      status.setAttribute('role', 'status');
      status.setAttribute('aria-live', 'polite');
      root.appendChild(status);

      const anchor = slotInput.closest('.js-form-item') || slotInput;
      anchor.parentNode.insertBefore(root, anchor);

      ui = { root, list, price, distance, status };
    }

    // ---- lifecycle ----------------------------------------------------------

    function stopAll() {
      stopped = true;
      if (pollTimer) {
        window.clearInterval(pollTimer);
        pollTimer = null;
      }
      if (reofferTimer) {
        window.clearTimeout(reofferTimer);
        reofferTimer = null;
      }
      abortOffer();
    }

    function onFormInput(event) {
      const target = event.target;
      if (!target || !target.name) {
        return;
      }
      const base = target.name.split('[')[0];
      if (watched.has(base)) {
        scheduleReoffer();
      }
    }

    function onQuoteChanged(event) {
      refreshPrice(event && event.detail ? event.detail.quote : null);
    }

    function onQuoteCleared() {
      refreshPrice(null);
    }

    function onRouteValidated(event) {
      refreshDistance(event && event.detail ? event.detail.distance : null);
    }

    function onRouteCleared() {
      refreshDistance(null);
    }

    /**
     * Adopts an arrival already stored on the element, without re-writing it.
     *
     * A returning wizard step can arrive with the arrival element already filled.
     * That value is adopted as the current selection so its button re-highlights
     * once the offers render, but nothing is written back and no request is made:
     * adopting is a read, and the server re-checks the arrival at checkout anyway.
     */
    function adoptExistingSelection() {
      if (!slotInput) {
        return;
      }
      const at = Number(slotInput.value);
      if (Number.isFinite(at) && at > 0) {
        selection = { at: at };
      }
    }

    buildUi();
    adoptExistingSelection();

    // The date element is normally one of the watched value fields, so the
    // form-level listener below already re-offers when it changes. This binds it
    // directly only when it is not watched, so the one control the widget depends
    // on still refreshes the times if the published field list ever omits it.
    if (dateInput && !watched.has(names.date)) {
      dateInput.addEventListener('change', () => {
        scheduleReoffer();
      });
    }
    form.addEventListener('input', onFormInput, true);
    form.addEventListener('change', onFormInput, true);
    form.addEventListener('custom-webform-quote-updated', onQuoteChanged);
    form.addEventListener('custom-webform-quote-cleared', onQuoteCleared);
    form.addEventListener('custom-webform-route-validated', onRouteValidated);
    form.addEventListener('custom-webform-route-cleared', onRouteCleared);

    // A date is chosen and nothing is selected: keep the read fresh so a time the
    // server can no longer offer stops being shown. This only refreshes offers; a
    // live selection is never disturbed, and availability here is advisory.
    pollTimer = window.setInterval(() => {
      if (!stopped && !selection && currentDate()) {
        requestOffers();
      }
    }, OFFER_POLL_MS);

    // A date is what the widget normally waits for. A service a person schedules
    // is the exception: its answer does not depend on a date, and waiting for one
    // would leave the page telling the visitor to pick a date so they could see
    // times that will never be offered. requestOffers() states the profile's own
    // sentence and sends nothing.
    const initialProfile = currentProfile(currentValues());
    if (currentDate() || (initialProfile && initialProfile.always_review)) {
      requestOffers();
    } else {
      setStatus(
        Drupal.t('Select a preferred service date to see available pickup times.'),
      );
    }

    return { stop: stopAll };
  }

  // One live widget per set-up form, so a detach can stop its timers and pending
  // request when an AJAX wizard rebuild removes the form it was attached to.
  const instances = new WeakMap();

  Drupal.behaviors.customWebformDispatchSlots = {
    attach(context, settings) {
      once(ONCE_ID, FORM_SELECTOR, context).forEach((form) => {
        const found = settingsFor(form, settings);
        if (!found) {
          return;
        }
        instances.set(form, setupForm(form, found.dispatch, found.bucket));
      });
    },
    detach(context) {
      once
        .filter(ONCE_ID, FORM_SELECTOR, context)
        .forEach((form) => {
          const instance = instances.get(form);
          if (instance) {
            instance.stop();
            instances.delete(form);
          }
        });
    },
  };
})(Drupal, drupalSettings, once);
