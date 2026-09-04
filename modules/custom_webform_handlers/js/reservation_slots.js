/**
 * @file
 * Time-slot selection: availability display and short-lived Holds.
 *
 * Nothing here is authoritative. Disabling a booked option is a courtesy that
 * saves a visitor from choosing a slot they cannot have; the server refuses the
 * slot regardless, and a tampered option, a forged hidden token, or a disabled
 * script cannot produce a Reservation. Every decision — which times exist, which
 * are taken, who owns a Hold — is made by the endpoints this file calls.
 *
 * Because it is only a courtesy, the failure mode matters: an option left
 * enabled reads as "free". So this file never returns quietly when it cannot
 * answer. Either the server's booked list is applied, or the marks are cleared
 * and the visitor is told that times could not be checked.
 */
(function (Drupal, drupalSettings, once) {
  'use strict';

  /** Debounce for date and resource edits. */
  const REFRESH_DELAY_MS = 250;

  /** Abort an availability read that never answers. */
  const REQUEST_TIMEOUT_MS = 12000;

  /**
   * Re-read a visible form often enough that another visitor's confirmation is
   * reflected without requiring this visitor to change the date first.
   */
  const AVAILABILITY_POLL_MS = 30000;

  /**
   * Fraction of the Hold window after which the Hold is renewed.
   *
   * A Hold is short so an abandoned Slot returns to sale quickly, but the form it
   * protects can take much longer to fill in than the window lasts. Renewing at a
   * third of the window leaves two further attempts before the Hold would lapse,
   * so a single failed or throttled renewal is not fatal.
   */
  const RENEW_AT_FRACTION = 3;

  /** Never renew more often than this, however short the configured window. */
  const RENEW_MIN_MS = 15000;

  /** Never wait longer than this between renewals, however long the window. */
  const RENEW_MAX_MS = 240000;

  /** The Hold window assumed when configuration does not state one. */
  const RENEW_FALLBACK_SECONDS = 600;

  /** Month names for the grid caption and day labels. */
  const MONTH_NAMES = [
    'January', 'February', 'March', 'April', 'May', 'June',
    'July', 'August', 'September', 'October', 'November', 'December',
  ];

  /** Weekday column headers, Sunday first, and their accessible names. */
  const WEEKDAY_SHORT = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

  /**
   * Formats a Y-m-d string from calendar parts.
   */
  function toIsoDate(year, monthIndex, day) {
    const month = String(monthIndex + 1).padStart(2, '0');
    return `${year}-${month}-${String(day).padStart(2, '0')}`;
  }

  /**
   * Splits a Y-m-d string, or returns null.
   */
  function fromIsoDate(value) {
    if (!ISO_DATE.test(String(value || ''))) {
      return null;
    }
    const parts = String(value).split('-');
    return {
      year: Number(parts[0]),
      monthIndex: Number(parts[1]) - 1,
      day: Number(parts[2]),
    };
  }

  /**
   * Days in one month. Built in UTC so no local timezone can shift the count.
   */
  function daysInMonth(year, monthIndex) {
    return new Date(Date.UTC(year, monthIndex + 1, 0)).getUTCDate();
  }

  /**
   * Weekday index (0 = Sunday) of one calendar day, computed in UTC.
   */
  function weekdayIndex(year, monthIndex, day) {
    return new Date(Date.UTC(year, monthIndex, day)).getUTCDay();
  }

  /** The canonical date shape the endpoints accept. */
  const ISO_DATE = /^\d{4}-\d{2}-\d{2}$/;

  /** Marks one option the server reported as taken. */
  const BOOKED_OPTION_CLASS = 'custom-reservation-slot--booked';

  /** Marks an option that cannot be checked safely right now. */
  const UNAVAILABLE_OPTION_CLASS = 'custom-reservation-slot--unavailable';

  /** Marks a time control that currently carries at least one booked option. */
  const BOOKED_CONTROL_CLASS = 'custom-reservation-time--has-booked-options';

  /** Marks a time control locked while the availability read is unavailable. */
  const UNAVAILABLE_CONTROL_CLASS = 'custom-reservation-time--availability-unavailable';

  /** Marks a date control whose every configured time is unavailable. */
  const DATE_FULL_CLASS = 'custom-reservation-date--fully-booked';

  /**
   * The Webforms this behavior attaches to.
   *
   * The handler marks the form it alters and publishes the matching webform_id,
   * so a second Webform sharing the page is never touched.
   */
  const FORM_SELECTOR = 'form[data-custom-webform-quote]';

  /**
   * Returns the reservation settings belonging to one form, or null.
   */
  function settingsFor(form, settings) {
    const config = Object.assign(
      {},
      drupalSettings.custom_webform_handlers,
      (settings || {}).custom_webform_handlers,
    );
    const id = form.getAttribute('data-custom-webform-quote') || '';
    if (!id || config.webform_id !== id) {
      return null;
    }
    const reservation = config.reservation;
    if (!reservation || !reservation.field_names) {
      return null;
    }
    // The webform_id lives on the outer bucket; the Hold request needs it.
    return Object.assign({ webform_id: id }, reservation);
  }

  /**
   * Escapes a value for use inside a double-quoted attribute selector.
   */
  function attr(value) {
    return String(value).replace(/\\/g, '\\\\').replace(/"/g, '\\"');
  }

  /**
   * Prefix for the per-Webform record of the Hold this browser owns.
   */
  const HOLD_STORAGE_PREFIX = 'cwh_reservation_hold_';

  /**
   * Returns the storage key for one Webform's Hold record.
   */
  function holdStorageKey(webformId) {
    return HOLD_STORAGE_PREFIX + String(webformId);
  }

  /**
   * Reads this browser's record of the Hold it owns, or null.
   *
   * This is a memo, not a credential. It exists so a page that renders none of
   * the reservation controls — every later wizard page — still knows which Slot
   * to keep alive. Everything in it was issued by the server, and the server
   * re-checks the Slot, the token, and the owner on every request that uses it,
   * so a forged or edited entry buys nothing.
   */
  function readStoredHold(webformId) {
    try {
      const raw = window.sessionStorage.getItem(holdStorageKey(webformId));
      if (!raw) {
        return null;
      }
      const data = JSON.parse(raw);
      if (!data || typeof data.token !== 'string' || data.token === '') {
        return null;
      }
      if (!ISO_DATE.test(String(data.date || '')) || String(data.time || '') === '') {
        return null;
      }
      return {
        date: String(data.date),
        time: String(data.time),
        resource: String(data.resource || ''),
        token: String(data.token),
        expiresAt: Number(data.expiresAt) || 0,
      };
    } catch (error) {
      return null;
    }
  }

  function writeStoredHold(webformId, state) {
    try {
      window.sessionStorage.setItem(holdStorageKey(webformId), JSON.stringify(state));
    } catch (error) {
      // Storage disabled or full. The Hold still exists server-side; only the
      // cross-page renewal is lost, and the server still decides submission.
    }
  }

  function clearStoredHold(webformId) {
    try {
      window.sessionStorage.removeItem(holdStorageKey(webformId));
    } catch (error) {
      // Nothing to do: an unreadable store cannot be cleared either.
    }
  }

  /**
   * Returns how long to wait between renewals for one configuration.
   */
  function renewIntervalFor(config) {
    const constraints = config.constraints || {};
    const seconds =
      Number(constraints.hold_seconds) > 0
        ? Number(constraints.hold_seconds)
        : RENEW_FALLBACK_SECONDS;
    const interval = (seconds * 1000) / RENEW_AT_FRACTION;
    return Math.max(RENEW_MIN_MS, Math.min(interval, RENEW_MAX_MS));
  }

  /**
   * Builds a POST helper that carries and refreshes its own CSRF token.
   */
  function createPoster(config) {
    let csrfToken = '';

    async function token(forceRefresh) {
      if (csrfToken && !forceRefresh) {
        return csrfToken;
      }
      const response = await fetch(config.csrf_token_url, {
        method: 'GET',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { Accept: 'text/plain' },
      });
      if (!response.ok) {
        throw new Error('csrf_token_request_failed');
      }
      csrfToken = (await response.text()).trim();
      if (!csrfToken) {
        throw new Error('csrf_token_request_failed');
      }
      return csrfToken;
    }

    return async function post(url, payload, keepalive) {
      for (let attempt = 0; attempt < 2; attempt += 1) {
        const response = await fetch(url, {
          method: 'POST',
          credentials: 'same-origin',
          cache: 'no-store',
          keepalive: Boolean(keepalive),
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': await token(attempt > 0),
          },
          body: JSON.stringify(payload),
        });
        if (response.status !== 403 || attempt === 1) {
          return response;
        }
        csrfToken = '';
      }
      throw new Error('csrf_token_request_failed');
    };
  }

  /**
   * Keeps a Hold alive on a page that renders no reservation controls.
   *
   * A multi-page Webform puts the date and time on one page and Submit on a
   * later one. The behavior that took the Hold cannot run on that later page —
   * there is nothing for it to attach to — so nothing renewed the Hold while
   * the visitor finished the form, and it lapsed under them. The token element
   * itself is rendered on every page, which is what makes recovery possible.
   *
   * The rendered token must match the recorded one. If it does not, the record
   * describes a Slot this page is not carrying and it is discarded rather than
   * renewed.
   */
  function setupHoldOnlyPage(form, config, tokenInput) {
    const rendered = String(tokenInput.value || '').trim();
    const stored = readStoredHold(config.webform_id);

    if (!config.renew_url || rendered === '' || !stored) {
      return;
    }
    if (stored.token !== rendered) {
      // A different Slot, or a replaced selection. The memo is stale.
      clearStoredHold(config.webform_id);
      return;
    }

    const post = createPoster(config);
    let timer = null;

    function stop() {
      if (timer !== null) {
        window.clearInterval(timer);
        timer = null;
      }
    }

    async function renew() {
      const payload = {
        webform: config.webform_id,
        date: stored.date,
        time: stored.time,
        hold_token: stored.token,
      };
      if (stored.resource) {
        payload.resource = stored.resource;
      }

      try {
        const response = await post(config.renew_url, payload, false);
        if (response.ok) {
          const data = await response.json();
          if (data && data.expires_at) {
            stored.expiresAt = Number(data.expires_at);
            writeStoredHold(config.webform_id, stored);
          }
          return;
        }
        if (response.status === 404) {
          // The server says this session no longer holds the Slot: it lapsed,
          // was released, or was already confirmed. Stop renewing and stop
          // presenting a token for it.
          stop();
          clearStoredHold(config.webform_id);
          tokenInput.value = '';
        }
      } catch (error) {
        // Transient. The window has room for the next attempt.
      }
    }

    // Immediately, before any interval: this page may have loaded close to the
    // expiry that was set on the page the visitor came from.
    renew();
    timer = window.setInterval(() => {
      if (!form.isConnected) {
        stop();
        return;
      }
      renew();
    }, renewIntervalFor(config));

    window.addEventListener('pagehide', stop);
  }

  /**
   * Wires one form's date, time, and resource elements.
   */
  function setupForm(form, config) {
    const names = config.field_names || {};
    const dateInput = names.date
      ? form.querySelector(`[name="${attr(names.date)}"]`)
      : null;
    const timeInput = names.time
      ? form.querySelector(`[name="${attr(names.time)}"]`)
      : null;
    const tokenInput = names.hold_token
      ? form.querySelector(`[name="${attr(names.hold_token)}"]`)
      : null;
    const resourceInput = names.resource
      ? form.querySelector(`[name="${attr(names.resource)}"]`)
      : null;

    // Availability needs only the day and the list of times. Requiring the
    // hidden token element here too would abandon the whole behavior on any
    // render that does not carry it — a wizard page, a partial rebuild — and
    // leave every taken time looking free.
    if (!dateInput || !timeInput) {
      // A later wizard page: no controls to wire, but a Hold taken on an earlier
      // page is still running out. Keep it alive.
      if (tokenInput) {
        setupHoldOnlyPage(form, config, tokenInput);
      }
      return;
    }

    // A Hold is a different capability from showing availability: it needs
    // somewhere to keep its receipt. Without that element the visitor still
    // sees what is taken, and the server still refuses a slot at submission.
    const canHold = Boolean(tokenInput);

    // Capacity is per Resource, so a partitioned schedule has no answer until
    // the visitor picks one. Read this from configuration rather than from the
    // DOM: a configured resource element that this render does not include is
    // still a partitioned schedule.
    const resourcePartitioned = Boolean(names.resource);

    // One POST helper for this form, carrying its own CSRF token cache.
    const post = createPoster(config);
    let availabilitySequence = 0;
    let activeAvailability = null;
    let refreshTimer = null;
    let heldToken = '';
    let heldKey = '';
    let status = null;

    /**
     * The last booked list the server returned, and which read it answered.
     *
     * A failed or refused read tells us nothing new, but it does not unlearn
     * what the previous successful read established for the same day and
     * Resource. Keeping it means a transient failure downgrades a red "Booked"
     * mark to a weaker grey lock instead of quietly restoring a taken time to
     * selectable, which is the exact failure a visitor would discover only at
     * submission.
     */
    let lastBooked = { key: '', times: [] };

    /** Repeating timer that keeps this browser's Hold alive. */
    let renewTimer = null;

    /** The month grid, its data, and the window the server allows. */
    let calendarRoot = null;
    let calendarGrid = null;
    let calendarCaption = null;
    let calendarPrev = null;
    let calendarNext = null;
    let calendarStatus = null;
    let calendarYear = 0;
    let calendarMonthIndex = -1;
    let calendarDays = {};
    let calendarHorizon = { start: '', end: '' };
    let calendarSequence = 0;
    let calendarFocusDate = '';

    /**
     * Creates the live region that reports Hold and availability state.
     */
    function ensureStatus() {
      if (status) {
        return status;
      }
      status = document.createElement('p');
      status.className = 'custom-reservation-status';
      status.setAttribute('data-reservation-status', '');
      status.setAttribute('role', 'status');
      status.setAttribute('aria-live', 'polite');
      status.setAttribute('aria-atomic', 'true');
      timeInput.insertAdjacentElement('afterend', status);
      return status;
    }

    function setStatus(message) {
      ensureStatus().textContent = message || '';
    }

    /**
     * Returns the selected day as a canonical Y-m-d value, or ''.
     *
     * A native date input reports Y-m-d in its value whatever the display
     * locale, but a Webform date element can be configured with another format,
     * in which case valueAsDate is the only reliable reading. Falling back to a
     * bare regex test on the raw value would silently skip availability for
     * every such form.
     */
    function currentDate() {
      const raw = String(dateInput.value || '').trim();
      if (ISO_DATE.test(raw)) {
        return raw;
      }

      const parsed = dateInput.valueAsDate;
      if (parsed && !Number.isNaN(parsed.getTime())) {
        // valueAsDate is UTC-based for a date input, so read it back in UTC to
        // avoid shifting the day in a negative-offset timezone.
        const month = String(parsed.getUTCMonth() + 1).padStart(2, '0');
        const day = String(parsed.getUTCDate()).padStart(2, '0');
        return `${parsed.getUTCFullYear()}-${month}-${day}`;
      }

      return '';
    }

    function resourceValue() {
      return resourceInput ? String(resourceInput.value || '').trim() : '';
    }

    function slotKey() {
      return JSON.stringify([
        currentDate(),
        String(timeInput.value || '').trim(),
        resourceValue(),
      ]);
    }

    /**
     * Identifies the read a booked list belongs to: one day, one Resource.
     *
     * The sequence counter alone cannot decide staleness. Edits are debounced,
     * so the visitor can change the date while an older request is still in
     * flight and before any newer request has been issued — at that moment the
     * sequence still matches, and the old day's booked list would be painted
     * onto the new day. Comparing the read's own subject closes that window.
     */
    function availabilityKey() {
      return JSON.stringify([currentDate(), resourceValue()]);
    }

    /**
     * Returns the booked times already established for what is shown now.
     */
    function knownBooked() {
      return lastBooked.key === availabilityKey() ? lastBooked.times : [];
    }

    /**
     * Returns the HH:MM this browser currently holds for the shown day.
     *
     * Availability deliberately does not say who owns a booked Slot — that
     * would leak another visitor's session — so the browser recognises its own
     * Hold from the key it recorded when the Hold succeeded.
     */
    function ownHeldTime() {
      if (!heldToken || !heldKey) {
        return '';
      }
      let recorded;
      try {
        recorded = JSON.parse(heldKey);
      } catch (error) {
        return '';
      }
      const sameDay = recorded[0] === currentDate();
      const sameResource = recorded[2] === resourceValue();
      return sameDay && sameResource ? String(recorded[1] || '') : '';
    }

    /**
     * Returns every option that carries a canonical time value.
     */
    function timeOptions() {
      return Array.prototype.slice.call(timeInput.options || []);
    }

    /**
     * Records an option's original label once, so it can always be restored.
     */
    function labelFor(option) {
      const label =
        option.getAttribute('data-reservation-label') || option.textContent;
      option.setAttribute('data-reservation-label', label);
      return label;
    }

    /**
     * Restores every option to selectable, keeping canonical values intact.
     *
     * Used whenever availability is unknown. Leaving the previous day's marks in
     * place would show times as booked on a day nobody has checked, and leaving
     * a stale enabled state would show taken times as free. Everything applyBooked
     * sets is undone here — label, disabled state, accessibility attribute, data
     * attribute, and both CSS classes — so no mark can outlive the read that
     * justified it.
     */
    function clearBooked() {
      timeOptions().forEach((option) => {
        if (!option.value) {
          return;
        }
        const label = labelFor(option);
        option.textContent = label;
        option.disabled = false;
        option.removeAttribute('aria-disabled');
        option.removeAttribute('data-reservation-booked');
        option.removeAttribute('data-reservation-unavailable');
        option.classList.remove(BOOKED_OPTION_CLASS);
        option.classList.remove(UNAVAILABLE_OPTION_CLASS);
      });

      timeInput.classList.remove(BOOKED_CONTROL_CLASS);
      timeInput.classList.remove(UNAVAILABLE_CONTROL_CLASS);
      clearFullyBookedDate();
    }

    /**
     * Fails closed in the browser when a selected day cannot be checked.
     *
     * Showing every option as free after a failed read invites a visitor to
     * select a slot that may already be confirmed. The server still decides the
     * outcome, but this keeps the UI conservative until an authoritative read
     * succeeds. A visitor's own valid Hold remains usable so a transient read
     * failure does not strand their checkout.
     *
     * Times a previous successful read already reported as taken keep their red
     * "Booked" mark. Only the times whose current state is genuinely unknown are
     * greyed as "Unavailable", so a failure never upgrades a taken slot back to
     * selectable and never mislabels an unchecked slot as booked.
     */
    function lockUntilAvailabilityReturns() {
      const known = knownBooked();
      clearBooked();
      const ours = ownHeldTime();
      let lockedCount = 0;
      let bookedCount = 0;

      timeOptions().forEach((option) => {
        if (!option.value || option.value === ours) {
          return;
        }
        const label = labelFor(option);
        const value = String(option.value);
        option.disabled = true;
        option.setAttribute('aria-disabled', 'true');

        if (known.indexOf(value) !== -1) {
          option.setAttribute('data-reservation-booked', 'true');
          option.classList.add(BOOKED_OPTION_CLASS);
          option.textContent = Drupal.t('@time — Booked', { '@time': label });
          bookedCount += 1;
          return;
        }

        option.setAttribute('data-reservation-unavailable', 'true');
        option.classList.add(UNAVAILABLE_OPTION_CLASS);
        option.textContent = Drupal.t('@time — Unavailable', { '@time': label });
        lockedCount += 1;
      });

      timeInput.classList.toggle(UNAVAILABLE_CONTROL_CLASS, lockedCount > 0);
      timeInput.classList.toggle(BOOKED_CONTROL_CLASS, bookedCount > 0);
      clearFullyBookedDate();

      if (timeInput.value && timeInput.value !== ours) {
        timeInput.value = '';
        clearToken();
        timeInput.dispatchEvent(new Event('change', { bubbles: true }));
      }
    }

    /**
     * Returns every canonical time this Webform actually offers.
     *
     * The select's own non-placeholder option values are the configured set, so
     * "every configured time" is answered from the same list the server built.
     */
    function bookableTimes() {
      return timeOptions()
        .map((option) => String(option.value))
        .filter((value) => value !== '');
    }

    /**
     * Drops the fully-booked marking from the date control.
     */
    function clearFullyBookedDate() {
      dateInput.classList.remove(DATE_FULL_CLASS);
      dateInput.removeAttribute('aria-invalid');
    }

    /**
     * Reports a day on which no configured time is left.
     *
     * A native date input cannot disable an individual day, and CSS cannot
     * either — the browser draws that calendar itself. So the day is marked on
     * the control, announced in the live region, and the unusable time selection
     * is cleared, which is the honest equivalent of a disabled day. The server
     * still refuses every date and time regardless of what happens here.
     *
     * The chosen date itself is deliberately kept. Clearing it used to look like
     * a firmer rejection, but it destroyed the subject of the read that had just
     * proved the day was full: the next refresh saw no date, cleared every mark,
     * and left all nine times looking free again on the day nothing was left.
     * Keeping the value means the marks, the invalid state, and the poll all
     * continue to describe the same day until the visitor picks another one.
     */
    function markFullyBookedDate() {
      dateInput.classList.add(DATE_FULL_CLASS);
      dateInput.setAttribute('aria-invalid', 'true');
      timeInput.value = '';
      clearToken();
      setStatus(
        Drupal.t(
          'Every time on that date is already booked. Please choose another date.',
        ),
      );
      timeInput.dispatchEvent(new Event('change', { bubbles: true }));
    }

    /**
     * Marks booked options as unselectable without destroying their value.
     *
     * The canonical value stays in the DOM so the server still receives what the
     * visitor saw, and a re-render can restore it when the slot frees up.
     */
    function applyBooked(booked) {
      const taken = Array.isArray(booked) ? booked.map(String) : [];
      const ours = ownHeldTime();
      let bookedCount = 0;

      // Remember what this read established, so a later failed read can keep
      // marking these times as taken instead of forgetting them.
      lastBooked = { key: availabilityKey(), times: taken };

      timeOptions().forEach((option) => {
        if (!option.value) {
          return;
        }
        const label = labelFor(option);

        // The Slot this browser holds reads as booked to everyone, including
        // itself. Disabling it would take the visitor's own reservation away.
        const value = String(option.value);
        if (taken.indexOf(value) !== -1 && value !== ours) {
          bookedCount += 1;
          option.disabled = true;
          // disabled alone is not announced consistently on an <option>, so the
          // state is also exposed explicitly.
          option.setAttribute('aria-disabled', 'true');
          option.setAttribute('data-reservation-booked', 'true');
          option.removeAttribute('data-reservation-unavailable');
          option.classList.add(BOOKED_OPTION_CLASS);
          option.classList.remove(UNAVAILABLE_OPTION_CLASS);
          option.textContent = Drupal.t('@time — Booked', { '@time': label });
        } else {
          option.disabled = false;
          option.removeAttribute('aria-disabled');
          option.removeAttribute('data-reservation-booked');
          option.removeAttribute('data-reservation-unavailable');
          option.classList.remove(BOOKED_OPTION_CLASS);
          option.classList.remove(UNAVAILABLE_OPTION_CLASS);
          option.textContent = label;
        }
      });

      // The control itself carries the state, because a disabled <option> cannot
      // be styled reliably on its own in every browser.
      timeInput.classList.toggle(BOOKED_CONTROL_CLASS, bookedCount > 0);
      timeInput.classList.remove(UNAVAILABLE_CONTROL_CLASS);

      // One booked time must never disable the whole day. Only when every
      // configured time is gone is the date itself unusable.
      const bookable = bookableTimes();
      if (bookable.length > 0 && bookedCount >= bookable.length) {
        markFullyBookedDate();
        return;
      }
      clearFullyBookedDate();

      // A slot that became unavailable while it was selected must not stay
      // selected: the server would refuse it at submission.
      const selected = timeInput.selectedOptions
        ? timeInput.selectedOptions[0]
        : null;
      if (selected && selected.disabled) {
        timeInput.value = '';
        clearToken();
        setStatus(
          Drupal.t('That time was just taken. Please choose another time.'),
        );
        timeInput.dispatchEvent(new Event('change', { bubbles: true }));
        return;
      }

      // Several browsers refuse to paint colour on a disabled <option> inside a
      // closed native select, so the visual mark alone cannot be relied on.
      // This line states the same fact in text, in a live region, for everyone.
      if (bookedCount > 0) {
        setStatus(
          Drupal.t(
            'Times marked Booked are already reserved and cannot be selected.',
          ),
        );
      }
    }

    /**
     * Builds the month grid once, directly after the Webform's date element.
     *
     * The native date input is deliberately left in place and still submits the
     * value. It is the no-JavaScript fallback, and it is also why this grid has
     * to exist at all: a browser draws its own calendar for a date input and
     * will not let a page colour or disable individual days in it, so a full day
     * cannot be shown as full there. The grid is the module's own control, so it
     * can say so — in colour, in text, and to assistive technology.
     */
    function ensureCalendar() {
      if (calendarRoot || !config.calendar_url) {
        return calendarRoot;
      }

      calendarRoot = document.createElement('div');
      calendarRoot.className = 'custom-reservation-calendar';
      calendarRoot.setAttribute('data-reservation-calendar', '');

      const header = document.createElement('div');
      header.className = 'custom-reservation-calendar__header';

      calendarPrev = document.createElement('button');
      calendarPrev.type = 'button';
      calendarPrev.className = 'custom-reservation-calendar__nav';
      calendarPrev.textContent = '‹';
      calendarPrev.setAttribute('aria-label', Drupal.t('Previous month'));
      calendarPrev.addEventListener('click', () => shiftMonth(-1));

      calendarCaption = document.createElement('h3');
      calendarCaption.className = 'custom-reservation-calendar__caption';
      calendarCaption.setAttribute('aria-live', 'polite');

      calendarNext = document.createElement('button');
      calendarNext.type = 'button';
      calendarNext.className = 'custom-reservation-calendar__nav';
      calendarNext.textContent = '›';
      calendarNext.setAttribute('aria-label', Drupal.t('Next month'));
      calendarNext.addEventListener('click', () => shiftMonth(1));

      header.appendChild(calendarPrev);
      header.appendChild(calendarCaption);
      header.appendChild(calendarNext);

      calendarGrid = document.createElement('div');
      calendarGrid.className = 'custom-reservation-calendar__grid';
      calendarGrid.setAttribute('role', 'grid');
      calendarGrid.setAttribute('aria-label', Drupal.t('Choose a service date'));
      calendarGrid.addEventListener('keydown', onCalendarKeydown);

      calendarStatus = document.createElement('p');
      calendarStatus.className = 'custom-reservation-calendar__status';
      calendarStatus.setAttribute('role', 'status');
      calendarStatus.setAttribute('aria-live', 'polite');

      const legend = document.createElement('p');
      legend.className = 'custom-reservation-calendar__legend';
      // Colour is never the only signal: every day also carries its count in
      // text, and the same fact again in its accessible name.
      legend.textContent = Drupal.t(
        'Dates marked Open have hours available. Dates marked Full or Closed cannot be booked.',
      );

      calendarRoot.appendChild(header);
      calendarRoot.appendChild(calendarGrid);
      calendarRoot.appendChild(calendarStatus);
      calendarRoot.appendChild(legend);
      dateInput.insertAdjacentElement('afterend', calendarRoot);

      return calendarRoot;
    }

    /**
     * Returns the day payload the server reported, or null.
     */
    function calendarDay(date) {
      return Object.prototype.hasOwnProperty.call(calendarDays, date)
        ? calendarDays[date]
        : null;
    }

    /**
     * Tells whether one date may be chosen: known, inside the window, free.
     *
     * Unknown days are not selectable. The server has not described them, and
     * treating silence as availability is exactly how a full day gets offered.
     */
    function isSelectableDate(date) {
      if (calendarHorizon.start && date < calendarHorizon.start) {
        return false;
      }
      if (calendarHorizon.end && date > calendarHorizon.end) {
        return false;
      }
      const day = calendarDay(date);
      return Boolean(day && day.weekday_open && Number(day.available_hours) > 0);
    }

    /**
     * Moves the grid by whole months, without leaving the booking window.
     */
    function shiftMonth(delta) {
      let year = calendarYear;
      let monthIndex = calendarMonthIndex + delta;
      if (monthIndex < 0) {
        monthIndex = 11;
        year -= 1;
      }
      else if (monthIndex > 11) {
        monthIndex = 0;
        year += 1;
      }
      showMonth(year, monthIndex);
    }

    /**
     * Displays one month and reads its capacity.
     */
    function showMonth(year, monthIndex) {
      calendarYear = year;
      calendarMonthIndex = monthIndex;
      renderCalendar();
      fetchCalendar();
    }

    /**
     * Reads per-day capacity for the displayed month, ignoring stale answers.
     */
    async function fetchCalendar() {
      if (!config.calendar_url || calendarMonthIndex < 0) {
        return;
      }

      const total = daysInMonth(calendarYear, calendarMonthIndex);
      const first = toIsoDate(calendarYear, calendarMonthIndex, 1);
      const last = toIsoDate(calendarYear, calendarMonthIndex, total);
      const sequence = ++calendarSequence;

      const query = new URLSearchParams({ from: first, to: last });
      const resource = resourceValue();
      if (resource) {
        query.set('resource', resource);
      }

      try {
        const response = await fetch(`${config.calendar_url}?${query}`, {
          method: 'GET',
          credentials: 'same-origin',
          cache: 'no-store',
          headers: { Accept: 'application/json' },
        });
        const data = await response.json();

        // A slower answer for a month the visitor has already left must not
        // repaint the one they are looking at.
        if (sequence !== calendarSequence) {
          return;
        }
        if (!response.ok || data.status !== 'success') {
          setCalendarStatus(
            Drupal.t('Dates could not be checked. Use the date field above.'),
          );
          return;
        }

        calendarHorizon = {
          start: String(data.horizon_start || ''),
          end: String(data.horizon_end || ''),
        };
        (data.days || []).forEach((day) => {
          if (day && ISO_DATE.test(String(day.date))) {
            calendarDays[String(day.date)] = day;
          }
        });
        renderCalendar();
      } catch (error) {
        if (sequence === calendarSequence) {
          setCalendarStatus(
            Drupal.t('Dates could not be checked. Use the date field above.'),
          );
        }
      }
    }

    /**
     * Re-reads the visible month, after anything that can change capacity.
     */
    function refreshCalendar() {
      if (calendarRoot && calendarMonthIndex >= 0) {
        fetchCalendar();
      }
    }

    function setCalendarStatus(message) {
      if (calendarStatus) {
        calendarStatus.textContent = message || '';
      }
    }

    /**
     * Paints the month: one focusable button per day, described in text.
     */
    function renderCalendar() {
      if (!calendarGrid) {
        return;
      }

      const selected = currentDate();
      const total = daysInMonth(calendarYear, calendarMonthIndex);
      const monthName = MONTH_NAMES[calendarMonthIndex] || '';
      calendarCaption.textContent = `${monthName} ${calendarYear}`;

      calendarGrid.textContent = '';

      const headRow = document.createElement('div');
      headRow.className = 'custom-reservation-calendar__row';
      headRow.setAttribute('role', 'row');
      WEEKDAY_SHORT.forEach((name) => {
        const cell = document.createElement('span');
        cell.className = 'custom-reservation-calendar__weekday';
        cell.setAttribute('role', 'columnheader');
        cell.textContent = name;
        headRow.appendChild(cell);
      });
      calendarGrid.appendChild(headRow);

      let row = document.createElement('div');
      row.className = 'custom-reservation-calendar__row';
      row.setAttribute('role', 'row');

      const leading = weekdayIndex(calendarYear, calendarMonthIndex, 1);
      for (let i = 0; i < leading; i += 1) {
        const filler = document.createElement('span');
        filler.className = 'custom-reservation-calendar__cell custom-reservation-calendar__cell--empty';
        filler.setAttribute('role', 'gridcell');
        row.appendChild(filler);
      }

      let openDays = 0;
      let focusAssigned = false;

      for (let day = 1; day <= total; day += 1) {
        const date = toIsoDate(calendarYear, calendarMonthIndex, day);
        const payload = calendarDay(date);
        const selectable = isSelectableDate(date);
        const outside =
          (calendarHorizon.start && date < calendarHorizon.start) ||
          (calendarHorizon.end && date > calendarHorizon.end);

        const cell = document.createElement('span');
        cell.className = 'custom-reservation-calendar__cell';
        cell.setAttribute('role', 'gridcell');

        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'custom-reservation-calendar__day';
        button.setAttribute('data-reservation-date', date);

        const number = document.createElement('span');
        number.className = 'custom-reservation-calendar__number';
        number.textContent = String(day);

        const note = document.createElement('span');
        note.className = 'custom-reservation-calendar__note';

        let state;
        let spoken;
        if (outside) {
          state = 'outside';
          note.textContent = Drupal.t('—');
          spoken = Drupal.t('not bookable');
        }
        else if (!payload) {
          state = 'unknown';
          note.textContent = Drupal.t('…');
          spoken = Drupal.t('checking');
        }
        else if (!payload.weekday_open) {
          state = 'closed';
          note.textContent = Drupal.t('Closed');
          spoken = Drupal.t('closed');
        }
        else if (selectable) {
          state = 'open';
          openDays += 1;
          note.textContent = Drupal.t('@count open', {
            '@count': payload.available_hours,
          });
          spoken = Drupal.t('@count of @total hours available', {
            '@count': payload.available_hours,
            '@total': payload.configured_hours,
          });
        }
        else {
          state = 'full';
          note.textContent = Drupal.t('Full');
          spoken = Drupal.t('fully booked');
        }

        button.classList.add(`custom-reservation-calendar__day--${state}`);
        button.setAttribute('data-reservation-day-state', state);
        button.appendChild(number);
        button.appendChild(note);
        button.setAttribute(
          'aria-label',
          `${day} ${MONTH_NAMES[calendarMonthIndex]} ${calendarYear}, ${spoken}`,
        );

        if (!selectable) {
          // aria-disabled rather than the disabled property: a disabled button
          // cannot be focused, and a keyboard user must still be able to move
          // across a full day and be told that it is full.
          button.setAttribute('aria-disabled', 'true');
        }
        if (date === selected) {
          button.classList.add('custom-reservation-calendar__day--selected');
          button.setAttribute('aria-current', 'date');
        }

        // Roving tabindex: the grid is one tab stop, and the arrow keys move
        // within it.
        const focusHere =
          (calendarFocusDate && calendarFocusDate === date) ||
          (!calendarFocusDate && date === selected);
        button.tabIndex = focusHere ? 0 : -1;
        if (focusHere) {
          focusAssigned = true;
        }

        button.addEventListener('click', () => {
          if (isSelectableDate(date)) {
            selectCalendarDate(date);
            return;
          }
          setCalendarStatus(
            Drupal.t('That date has no available times. Choose another date.'),
          );
        });

        cell.appendChild(button);
        row.appendChild(cell);

        if (row.children.length === 7) {
          calendarGrid.appendChild(row);
          row = document.createElement('div');
          row.className = 'custom-reservation-calendar__row';
          row.setAttribute('role', 'row');
        }
      }

      if (row.children.length > 0) {
        calendarGrid.appendChild(row);
      }

      if (!focusAssigned) {
        const first = calendarGrid.querySelector('.custom-reservation-calendar__day');
        if (first) {
          first.tabIndex = 0;
        }
      }

      calendarPrev.disabled = Boolean(
        calendarHorizon.start &&
          toIsoDate(calendarYear, calendarMonthIndex, 1) <= calendarHorizon.start,
      );
      calendarNext.disabled = Boolean(
        calendarHorizon.end &&
          toIsoDate(calendarYear, calendarMonthIndex, total) >= calendarHorizon.end,
      );

      setCalendarStatus(
        Drupal.t('@month @year: @open date(s) with hours available.', {
          '@month': monthName,
          '@year': calendarYear,
          '@open': openDays,
        }),
      );
    }

    /**
     * Writes a chosen date into the Webform's own element and announces it.
     *
     * The grid never becomes the source of truth. It sets the value on the real
     * date element and fires the events that element would fire itself, so every
     * other listener — this behavior's own availability refresh included — reacts
     * exactly as it does when the visitor types the date.
     */
    function selectCalendarDate(date) {
      calendarFocusDate = date;
      dateInput.value = date;
      dateInput.dispatchEvent(new Event('input', { bubbles: true }));
      dateInput.dispatchEvent(new Event('change', { bubbles: true }));
      renderCalendar();
    }

    /**
     * Moves focus by whole days, crossing month boundaries when needed.
     */
    function moveCalendarFocus(fromDate, deltaDays) {
      const parts = fromDate ? fromIsoDate(fromDate) : null;
      if (!parts) {
        return;
      }
      const moved = new Date(
        Date.UTC(parts.year, parts.monthIndex, parts.day + deltaDays),
      );
      const target = toIsoDate(
        moved.getUTCFullYear(),
        moved.getUTCMonth(),
        moved.getUTCDate(),
      );
      calendarFocusDate = target;

      if (
        moved.getUTCFullYear() !== calendarYear ||
        moved.getUTCMonth() !== calendarMonthIndex
      ) {
        showMonth(moved.getUTCFullYear(), moved.getUTCMonth());
      }
      else {
        renderCalendar();
      }

      const button = calendarGrid.querySelector(
        `[data-reservation-date="${target}"]`,
      );
      if (button) {
        button.focus();
      }
    }

    /**
     * Keyboard model: arrows by day and week, Home/End, PageUp/PageDown.
     */
    function onCalendarKeydown(event) {
      const active = event.target;
      if (!active || !active.hasAttribute('data-reservation-date')) {
        return;
      }
      const date = active.getAttribute('data-reservation-date');
      const handlers = {
        ArrowLeft: -1,
        ArrowRight: 1,
        ArrowUp: -7,
        ArrowDown: 7,
      };

      if (Object.prototype.hasOwnProperty.call(handlers, event.key)) {
        event.preventDefault();
        moveCalendarFocus(date, handlers[event.key]);
        return;
      }
      if (event.key === 'Home') {
        event.preventDefault();
        moveCalendarFocus(date, 1 - fromIsoDate(date).day);
        return;
      }
      if (event.key === 'End') {
        event.preventDefault();
        const total = daysInMonth(calendarYear, calendarMonthIndex);
        moveCalendarFocus(date, total - fromIsoDate(date).day);
        return;
      }
      if (event.key === 'PageUp' || event.key === 'PageDown') {
        event.preventDefault();
        shiftMonth(event.key === 'PageUp' ? -1 : 1);
      }
    }

    /**
     * Opens the grid on the month the visitor is actually looking at.
     */
    function syncCalendarToDate() {
      if (!calendarRoot) {
        return;
      }
      const chosen = fromIsoDate(currentDate());
      if (!chosen) {
        renderCalendar();
        return;
      }
      if (
        chosen.year !== calendarYear ||
        chosen.monthIndex !== calendarMonthIndex
      ) {
        showMonth(chosen.year, chosen.monthIndex);
        return;
      }
      renderCalendar();
    }

    /**
     * Reads availability for the selected date, ignoring stale answers.
     */
    async function refreshAvailability() {
      const date = currentDate();
      if (!date) {
        // No day chosen yet, so nothing is known about any time. Any marks left
        // from a previous day would be misleading.
        clearBooked();
        return;
      }

      // A partitioned schedule cannot be read before the Resource is known, and
      // asking anyway earns a refusal the visitor never sees.
      if (resourcePartitioned && resourceValue() === '') {
        clearBooked();
        setStatus(
          Drupal.t('Choose an option above to see which times are available.'),
        );
        return;
      }

      const sequence = ++availabilitySequence;
      // The subject of this read, captured before it is issued. Both this and
      // the sequence must still hold when the answer lands.
      const requestKey = availabilityKey();
      if (activeAvailability) {
        window.clearTimeout(activeAvailability.timer);
        activeAvailability.controller.abort();
      }
      const request = { controller: new AbortController() };
      activeAvailability = request;
      request.timer = window.setTimeout(
        () => request.controller.abort(),
        REQUEST_TIMEOUT_MS,
      );

      const query = new URLSearchParams({ date });
      const resource = resourceValue();
      if (resource) {
        query.set('resource', resource);
      }

      try {
        const response = await fetch(`${config.availability_url}?${query}`, {
          method: 'GET',
          credentials: 'same-origin',
          cache: 'no-store',
          signal: request.controller.signal,
          headers: { Accept: 'application/json' },
        });
        const data = await response.json();

        // A slower answer for an older date must never overwrite a newer one,
        // and an answer about a day the visitor has already moved off describes
        // times that are no longer on screen.
        if (sequence !== availabilitySequence || requestKey !== availabilityKey()) {
          return;
        }
        if (!response.ok || data.status !== 'success') {
          // The server refused this day — an unbookable weekday, a date past
          // the horizon, an unknown resource. It has told us nothing about
          // individual times, so no time may keep a booked mark.
          lockUntilAvailabilityReturns();
          setStatus(
            Drupal.t('Times could not be checked for that date. Choose another date.'),
          );
          // The grid is the place a visitor can see which days the server will
          // accept, so a refusal is a reason to re-read it.
          refreshCalendar();
          return;
        }
        setStatus('');
        applyBooked(data.booked);
      } catch (error) {
        if (request.controller.signal.aborted &&
          sequence !== availabilitySequence) {
          // Superseded by a newer read, which owns the display now.
          return;
        }
        if (sequence === availabilitySequence && requestKey === availabilityKey()) {
          // The server remains the authority and will still refuse a taken
          // slot, but the visitor must not be shown a guess as if it were
          // checked.
          lockUntilAvailabilityReturns();
          setStatus(
            Drupal.t('Times are temporarily unavailable while availability is checked. Please try again.'),
          );
        }
      } finally {
        window.clearTimeout(request.timer);
        if (activeAvailability === request) {
          activeAvailability = null;
        }
      }
    }

    function clearToken() {
      heldToken = '';
      heldKey = '';
      stopRenewing();
      // Released, refused, replaced, or expired: the memo must not outlive the
      // Hold it describes, or a later page would renew something already gone.
      clearStoredHold(config.webform_id);
      if (tokenInput) {
        tokenInput.value = '';
      }
    }

    /**
     * Stops renewing, because there is no longer a Hold to keep alive.
     */
    function stopRenewing() {
      if (renewTimer !== null) {
        window.clearInterval(renewTimer);
        renewTimer = null;
      }
    }

    /**
     * Returns how long to wait between renewals of this form's Hold.
     */
    function renewIntervalMs() {
      return renewIntervalFor(config);
    }

    /**
     * Keeps this browser's Hold alive for as long as the form is being filled in.
     *
     * The Hold window is deliberately short, and nothing about the visitor's pace
     * is bounded by it: a quote form with address lookup and a distance
     * calculation is routinely open for longer than the window lasts. Without
     * this, the Hold lapses under the visitor, another visitor's next request
     * sweeps it, and the submission is refused at final validation for a Slot the
     * visitor believed was theirs.
     *
     * The server decides every renewal. A refusal is taken at face value and the
     * stale local state is dropped, so the visitor is asked to choose again while
     * they are still on the form rather than being refused at the end of it.
     */
    function startRenewing() {
      stopRenewing();
      // A form rendered before the renewal endpoint existed publishes no URL for
      // it. Such a page keeps working exactly as it did rather than treating a
      // request to nowhere as proof that the Hold is gone.
      if (!canHold || !config.renew_url) {
        return;
      }
      renewTimer = window.setInterval(renewHold, renewIntervalMs());
    }

    /**
     * Asks the server to extend the Hold this browser believes it owns.
     */
    async function renewHold() {
      if (!heldToken || !heldKey || !config.renew_url) {
        stopRenewing();
        return;
      }

      // An AJAX rebuild can replace the form this timer was started for. The
      // Hold is server-side and survives, but this closure no longer drives
      // anything on screen.
      if (!form.isConnected) {
        stopRenewing();
        return;
      }

      let recorded;
      try {
        recorded = JSON.parse(heldKey);
      } catch (error) {
        clearToken();
        return;
      }

      // Renew the Slot the Hold was taken on, not whatever the form now shows.
      // If they no longer agree, the selection moved on and the old Hold is not
      // this form's concern any more.
      if (
        recorded[0] !== currentDate() ||
        recorded[1] !== String(timeInput.value || '').trim() ||
        recorded[2] !== resourceValue()
      ) {
        return;
      }

      const payload = {
        webform: config.webform_id,
        date: recorded[0],
        time: recorded[1],
        hold_token: heldToken,
      };
      if (recorded[2]) {
        payload.resource = recorded[2];
      }

      try {
        const response = await post(config.renew_url, payload, false);
        if (response.ok) {
          // Keep the memo's expiry current, so a later page inherits the real
          // window rather than the one this Hold started with.
          try {
            const data = await response.json();
            if (data && data.expires_at) {
              const stored = readStoredHold(config.webform_id);
              if (stored && stored.token === heldToken) {
                stored.expiresAt = Number(data.expires_at);
                writeStoredHold(config.webform_id, stored);
              }
            }
          } catch (error) {
            // A renewal that succeeded but reported nothing readable is still a
            // renewal; only the recorded expiry is stale.
          }
          return;
        }

        // 404 is the server stating plainly that this session no longer holds
        // the Slot. Keeping the token would mean submitting against a Hold that
        // does not exist and being refused at the very end.
        if (response.status === 404) {
          clearToken();
          setStatus(
            Drupal.t(
              'Your hold on that time has ended. Please choose a time again.',
            ),
          );
          refreshAvailability();
          refreshCalendar();
        }
      } catch (error) {
        // A transient failure is not proof the Hold is gone, and the window has
        // room for further attempts, so the token is kept and the next tick
        // tries again.
      }
    }

    /**
     * Gives up the Hold this browser owns, if any.
     */
    async function releaseHold(keepalive) {
      const activeToken = heldToken;
      if (!activeToken) {
        return;
      }
      clearToken();
      try {
        await post(config.release_url, { hold_token: activeToken }, keepalive);
      } catch (error) {
        // The Hold expires on its own, so a failed release costs only time.
      }
      // The released hour is free again, so the day's count changed.
      refreshCalendar();
    }

    /**
     * Claims the selected slot, replacing any Hold this browser owned.
     */
    async function requestHold() {
      if (!canHold) {
        return;
      }

      const date = currentDate();
      const time = String(timeInput.value || '').trim();
      if (!date || !time) {
        await releaseHold(false);
        return;
      }

      const key = slotKey();
      // Re-selecting the same slot is normally a no-op. It is not one when the
      // token is gone: the Hold was lost, refused, or renewed too late, and the
      // browser has been told to take a fresh one. Returning early on the key
      // alone is what left a visitor stuck — the error asked them to choose a
      // time again, and choosing the same time again did nothing at all.
      if (key === heldKey && heldToken) {
        return;
      }

      await releaseHold(false);
      setStatus(Drupal.t('Holding this time…'));

      const payload = { webform: config.webform_id, date, time };
      const resource = resourceValue();
      if (resource) {
        payload.resource = resource;
      }

      try {
        const response = await post(config.hold_url, payload, false);
        const data = await response.json();

        if (response.status === 409) {
          clearToken();
          setStatus(
            Drupal.t('That time was just taken. Please choose another time.'),
          );
          refreshAvailability();
          refreshCalendar();
          return;
        }
        if (!response.ok || data.status !== 'success' || !data.hold_token) {
          clearToken();
          setStatus(
            Drupal.t(
              'That time could not be held. Please choose a time again.',
            ),
          );
          refreshAvailability();
          refreshCalendar();
          return;
        }

        // The token is the browser's receipt, not its permission: the server
        // re-checks the Hold on submission.
        heldToken = data.hold_token;
        heldKey = key;
        tokenInput.value = data.hold_token;
        // Recorded so the later wizard pages, which render none of these
        // controls, still know which Slot to keep alive.
        writeStoredHold(config.webform_id, {
          date,
          time,
          resource,
          token: data.hold_token,
          expiresAt: Number(data.expires_at) || 0,
        });
        // The Hold now has to outlive the rest of the form.
        startRenewing();
        setStatus(Drupal.t('This time is held while you finish the form.'));
        // This Hold consumed an hour, so the day's remaining count changed.
        refreshCalendar();
      } catch (error) {
        clearToken();
        setStatus(
          Drupal.t('That time could not be held. Please choose a time again.'),
        );
        refreshAvailability();
        refreshCalendar();
      }
    }

    function scheduleAvailability() {
      window.clearTimeout(refreshTimer);
      refreshTimer = window.setTimeout(refreshAvailability, REFRESH_DELAY_MS);
    }

    dateInput.addEventListener('change', () => {
      // Changing the day abandons the slot, so give the Hold back instead of
      // silently dropping the token and stranding it until it expires.
      releaseHold(false);
      scheduleAvailability();
      syncCalendarToDate();
    });
    dateInput.addEventListener('input', scheduleAvailability);

    if (resourceInput) {
      resourceInput.addEventListener('change', () => {
        releaseHold(false);
        scheduleAvailability();
        // Capacity is per Resource, so every day's counts change with it.
        calendarDays = {};
        refreshCalendar();
      });
    }

    timeInput.addEventListener('change', () => {
      requestHold();
    });

    // A visitor can leave the form open while another visitor confirms the
    // same time. Refreshing before the control is opened narrows that stale UI
    // window, while the interval below covers an untouched open form.
    timeInput.addEventListener('focus', refreshAvailability);

    const availabilityPoll = window.setInterval(() => {
      // AJAX rebuilds remove the old form without reloading the document. Stop
      // its timer on the next tick instead of allowing detached forms to keep
      // issuing availability requests.
      if (!form.isConnected) {
        window.clearInterval(availabilityPoll);
        stopRenewing();
        return;
      }
      if (!document.hidden) {
        refreshAvailability();
        // Another visitor can fill a day while this form sits open, so the grid
        // is re-read on the same interval as the time options.
        refreshCalendar();
      }
    }, AVAILABILITY_POLL_MS);

    // Browsers throttle and eventually freeze timers in a backgrounded tab, so a
    // form left in another tab can come back with its renewal overdue. Renew at
    // the moment it becomes visible again rather than waiting for a tick that may
    // not have fired for minutes.
    document.addEventListener('visibilitychange', () => {
      if (document.hidden || !form.isConnected) {
        return;
      }
      renewHold();
      refreshAvailability();
      refreshCalendar();
    });

    // Submitting this form is not abandoning it. A multi-page Webform advances
    // by submitting, so pagehide fires on the way to the next page — and
    // releasing there handed the visitor's own Slot back every time they pressed
    // Next, leaving the final page with a Hold that no longer existed.
    let submittingForm = false;
    form.addEventListener('submit', () => {
      submittingForm = true;
    });

    window.addEventListener('pagehide', () => {
      window.clearInterval(availabilityPoll);
      stopRenewing();
      // Only a visitor who is really leaving gives the Slot back. Everyone else
      // is mid-form, and an abandoned Hold still expires on its own.
      if (canHold && !submittingForm) {
        releaseHold(true);
      }
    });

    // A Webform AJAX rebuild re-runs this behavior against fresh DOM, with the
    // previous closure's state gone. The Hold itself is server-side and still
    // live, so a token the rebuild carried over is adopted rather than
    // discarded: clearing it here would strand the Slot for the rest of its
    // window and make the visitor choose a time again. It is still not proof of
    // anything — the server re-verifies the Hold on submission.
    const renderedDate = currentDate();
    const renderedTime = String(timeInput.value || '').trim();
    const renderedToken = tokenInput
      ? String(tokenInput.value || '').trim()
      : '';
    if (renderedToken && renderedDate && renderedTime) {
      heldToken = renderedToken;
      heldKey = slotKey();
      // The rebuilt form inherits the Hold, so it also inherits the duty to keep
      // it alive. The previous closure's timer died with the old DOM, and without
      // this the inherited Hold would run out its remaining window unrenewed.
      startRenewing();
    } else {
      // A token without a complete slot describes nothing selectable.
      clearToken();
    }

    // Read availability on attach whether or not a day is already chosen: with
    // one, the taken times must be marked before the visitor can pick a time;
    // without one, any marks carried in by a cached render must be cleared.
    //
    // When a day is already on screen — a cached render, a draft, a wizard step
    // back, an AJAX rebuild — the options are selectable for as long as that
    // read takes, and every one of them reads as free. Lock them first: the read
    // below either confirms which are taken or leaves the conservative state in
    // place. The visitor's own held time stays selectable throughout.
    if (renderedDate) {
      lockUntilAvailabilityReturns();
      setStatus(Drupal.t('Checking which times are still available…'));
    }
    refreshAvailability();

    // The month grid opens on the chosen date when there is one, and otherwise
    // on the current month. It is built last so a failure to render it can never
    // stop the time options being marked, which is the part that protects the
    // booking.
    if (config.calendar_url && ensureCalendar()) {
      const chosen = fromIsoDate(renderedDate);
      const now = new Date();
      calendarFocusDate = renderedDate || '';
      showMonth(
        chosen ? chosen.year : now.getFullYear(),
        chosen ? chosen.monthIndex : now.getMonth(),
      );
    }
  }

  Drupal.behaviors.customWebformReservationSlots = {
    attach(context, settings) {
      once('custom-webform-reservation-slots', FORM_SELECTOR, context).forEach(
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
