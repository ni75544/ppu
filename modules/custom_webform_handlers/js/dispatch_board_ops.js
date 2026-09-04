/**
 * @file
 * Dragging on the Dispatch Planner board, over operations that already work.
 *
 * Every row this file can move already links to a Replan form, and that form is
 * what proves the move. Dragging is a second way to reach the same server
 * operation, so a browser without this file loses a convenience and nothing else.
 *
 * What a drag says is deliberately small: this Reservation, that day, at the hour
 * it already arrives. There is no field here for a Dispatch Window, a duration, a
 * route or a price, because the endpoint reads none of those — it reloads the
 * Reservation, rebuilds its Dispatch Request from the submission that owns it, and
 * re-proves the whole plan under the calendar locks. Changing the hour is the
 * Replan form's job, which the same row links to.
 *
 * Keyboard first, and not as an afterthought. Each movable row is given a real
 * button that arms the move, each day is given a real button that accepts it, and
 * Escape stands the whole thing down. The pointer handlers are added on top of
 * those buttons rather than instead of them, so nothing here can be reached only
 * by dragging a mouse.
 *
 * After the server answers — moved or refused — the page is reloaded, because the
 * only trustworthy picture of the day is the one the server has just rebuilt. A
 * refusal arrives as an HTTP error and changes nothing, so this file never paints
 * a job in a place the schedule did not agree to. The answer is carried across the
 * reload in session storage, so the operator reads the sentence beside the board
 * it applies to rather than instead of it.
 */
(function (Drupal, drupalSettings, once) {
  'use strict';

  const ONCE_ID = 'cwh-dispatch-board-ops';
  const BOARD_SELECTOR = '.cwh-dispatch-planner';
  const DAY_SELECTOR = '[data-cwh-day]';
  const ROW_SELECTOR = 'tr.cwh-dispatch-planner__movable';
  const TABLE_SELECTOR = '.cwh-dispatch-planner__tablewrap';

  // A proposal is abandoned if it outruns this. The server may still be proving it,
  // which is why the message says nothing was moved only when it is safe to.
  const REQUEST_TIMEOUT_MS = 20000;

  // One-shot, and read exactly once: the outcome of the last proposal, waiting to
  // be shown on the board the reload is about to draw.
  const NOTICE_KEY = 'cwhDispatchBoardNotice';

  /**
   * Returns the two paths the board published, or null when it published none.
   *
   * Absence is the normal case rather than an error: the settings are attached only
   * for an operator who holds the permission the endpoint requires, so a reader of
   * the board simply gets no drag layer.
   */
  function boardSettings(settings) {
    const bucket = Object.assign(
      {},
      drupalSettings.cwhDispatchBoard,
      (settings || {}).cwhDispatchBoard,
    );
    if (!bucket.replanUrl || !bucket.csrfTokenUrl) {
      return null;
    }
    return bucket;
  }

  /**
   * A POST helper that carries the CSRF header token and refreshes it once.
   *
   * The token is never published in the markup. It is fetched from Drupal's own
   * session token route the first time one is needed and then cached for this page.
   * A 403 is treated as a stale token exactly once — refreshed and retried — after
   * which the refusal is real and reported as one.
   */
  function createPoster(bucket) {
    let token = '';

    async function fetchToken(forceRefresh) {
      if (token && !forceRefresh) {
        return token;
      }
      const response = await fetch(bucket.csrfTokenUrl, {
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
      async post(payload, controller) {
        const send = (value) =>
          fetch(bucket.replanUrl, {
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
   * Leaves the outcome where the next page load will find it.
   *
   * Losing the sentence is survivable and the reload is not, so a storage failure
   * is swallowed: the operator would rather see an authoritative board with no
   * explanation than a stale one with a good story.
   */
  function stashNotice(text, kind) {
    try {
      window.sessionStorage.setItem(
        NOTICE_KEY,
        JSON.stringify({ text: text, kind: kind }),
      );
    } catch (error) {
      // Nothing to recover from; the board itself is the record.
    }
  }

  /**
   * Takes the stored outcome, and takes it away, so it is shown exactly once.
   */
  function drainNotice() {
    try {
      const raw = window.sessionStorage.getItem(NOTICE_KEY);
      window.sessionStorage.removeItem(NOTICE_KEY);
      if (!raw) {
        return null;
      }
      const parsed = JSON.parse(raw);
      if (!parsed || typeof parsed.text !== 'string' || !parsed.text) {
        return null;
      }
      return { text: parsed.text, kind: parsed.kind === 'error' ? 'error' : 'status' };
    } catch (error) {
      return null;
    }
  }

  /**
   * Reads the server's answer, whether or not it agreed.
   *
   * The endpoint speaks the same sentence to a refusal as the forms print, so there
   * is nothing to translate here and nothing to interpret. An unreadable body is
   * still an answer: the status line already said whether anything moved.
   */
  async function readAnswer(response) {
    let body = null;
    try {
      body = await response.json();
    } catch (error) {
      body = null;
    }
    const message =
      body && typeof body.message === 'string' && body.message
        ? body.message
        : Drupal.t('The server did not explain the outcome. The board below is what it holds now.');

    return { ok: response.ok, message: message };
  }

  /**
   * The live region every outcome is spoken through.
   *
   * It is polite rather than assertive because none of this interrupts anything:
   * the operator has just acted and is waiting to be told what happened.
   */
  function buildStatus(board) {
    const status = document.createElement('div');
    status.className = 'cwh-dispatch-ops__status';
    status.setAttribute('role', 'status');
    status.setAttribute('aria-live', 'polite');
    board.insertBefore(status, board.firstChild);

    return status;
  }

  /**
   * Says one thing, as text, replacing whatever was said before.
   */
  function announce(status, text, kind) {
    status.textContent = text;
    status.classList.toggle('cwh-dispatch-ops__status--error', kind === 'error');
    status.classList.toggle('cwh-dispatch-ops__status--said', text !== '');
  }

  /**
   * Puts the button that arms a move into the row it moves.
   *
   * It goes in the last cell, beside the Replan and release links, because it is
   * the third way of doing the same thing and belongs where the other two already
   * are. A row rendered without an operations cell — which is what a board without
   * the permission renders — never reaches here.
   */
  function buildGrab(row) {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'cwh-dispatch-ops__grab';
    button.textContent = Drupal.t('Move');
    button.setAttribute('aria-pressed', 'false');
    const host = row.cells.length ? row.cells[row.cells.length - 1] : row;
    host.appendChild(button);

    return button;
  }

  /**
   * Puts the button that accepts a move into the day it would move to.
   *
   * One per day, inside that day and above its table, so tabbing through the board
   * reaches each destination in the order the days are read. Hidden until something
   * is armed: a button offering to move nothing would be a lie about the state.
   */
  function buildTarget(day) {
    const label = day.getAttribute('data-cwh-day-label') || day.getAttribute('data-cwh-day') || '';
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'cwh-dispatch-ops__target';
    button.hidden = true;
    button.textContent = Drupal.t('Move the armed Reservation to @day', { '@day': label });
    const anchor = day.querySelector(TABLE_SELECTOR);
    if (anchor) {
      day.insertBefore(button, anchor);
    }
    else {
      day.appendChild(button);
    }

    return button;
  }

  /**
   * Wires one board: rows that can be armed, days that can accept, one proposal.
   *
   * Everything here is state about what the operator is doing, and none of it is
   * state about the schedule. The identifiers and times come out of the rendered
   * rows, the dates out of the rendered days, and the answer to whether any of it
   * is possible comes from the server every single time.
   */
  function initBoard(board, bucket) {
    const status = buildStatus(board);
    const notice = drainNotice();
    if (notice) {
      announce(status, notice.text, notice.kind);
    }

    const days = Array.prototype.slice.call(board.querySelectorAll(DAY_SELECTOR));
    const rows = Array.prototype.slice.call(board.querySelectorAll(ROW_SELECTOR));
    if (!days.length || !rows.length) {
      return;
    }

    const poster = createPoster(bucket);
    const targets = days.map((day) => ({ day: day, button: buildTarget(day) }));
    // The row whose move is being offered, and whether a proposal is in flight. A
    // second proposal is refused while one is outstanding: the first may be halfway
    // through a transaction, and two drags racing would produce two answers about a
    // day that only one of them measured.
    let armed = null;
    let busy = false;

    function disarm() {
      armed = null;
      board.classList.remove('cwh-dispatch-ops--armed');
      rows.forEach((row) => {
        row.classList.remove('is-armed');
        const grab = row.querySelector('.cwh-dispatch-ops__grab');
        if (grab) {
          grab.setAttribute('aria-pressed', 'false');
        }
      });
      targets.forEach((target) => {
        target.button.hidden = true;
        target.day.classList.remove('is-drop-target');
      });
    }

    function arm(row) {
      if (busy) {
        return;
      }
      armed = row;
      board.classList.add('cwh-dispatch-ops--armed');
      rows.forEach((other) => {
        const isThis = other === row;
        other.classList.toggle('is-armed', isThis);
        const grab = other.querySelector('.cwh-dispatch-ops__grab');
        if (grab) {
          grab.setAttribute('aria-pressed', isThis ? 'true' : 'false');
        }
      });
      targets.forEach((target) => {
        target.button.hidden = false;
      });

      announce(
        status,
        Drupal.t('Reservation @id is armed, arriving at @time. Choose a day to propose that same hour on, or press Escape to stand it down.', {
          '@id': row.getAttribute('data-cwh-reservation') || '',
          '@time': row.getAttribute('data-cwh-time') || '',
        }),
        'status',
      );
    }

    function standDown() {
      if (busy || !armed) {
        return;
      }
      disarm();
      announce(status, Drupal.t('Stood down. Nothing was proposed and nothing changed.'), 'status');
    }

    /**
     * Sends the whole proposal: one Reservation, one day, the hour it already has.
     *
     * Three values leave the browser and there is nowhere in this payload for a
     * fourth. Whatever this page believes about the journey, the server measures it
     * again under the calendar locks, so a stale board cannot talk it into a move.
     */
    async function propose(row, day) {
      if (busy) {
        return;
      }

      const reservation = Number(row.getAttribute('data-cwh-reservation'));
      const date = day.getAttribute('data-cwh-day') || '';
      const time = row.getAttribute('data-cwh-time') || '';
      if (!Number.isFinite(reservation) || reservation < 1 || !date || !time) {
        disarm();
        announce(status, Drupal.t('That row does not name a Reservation this board can propose a move for. Use its Replan link instead.'), 'error');

        return;
      }

      busy = true;
      board.classList.add('cwh-dispatch-ops--busy');
      row.classList.add('is-pending');
      day.classList.add('is-drop-target');
      targets.forEach((target) => {
        target.button.disabled = true;
      });
      announce(
        status,
        Drupal.t('Proposing @date at @time. The server is re-proving the whole plan before anything moves.', {
          '@date': date,
          '@time': time,
        }),
        'status',
      );

      const controller = 'AbortController' in window ? new AbortController() : null;
      const timer = controller
        ? window.setTimeout(() => controller.abort(), REQUEST_TIMEOUT_MS)
        : 0;

      try {
        const response = await poster.post(
          { reservation_id: reservation, date: date, time: time },
          controller,
        );
        const answer = await readAnswer(response);
        // Moved or refused, the same thing happens next, and deliberately so: the
        // operator is put back in front of rows the server has just rebuilt rather
        // than in front of this page's opinion of them. A refusal changed nothing,
        // and the reload is what proves that to whoever is reading.
        stashNotice(answer.message, answer.ok ? 'status' : 'error');
        window.location.reload();
      }
      catch (error) {
        // The request never completed, so what the server did with it is unknown.
        // The board is left alone and the operator is told to look again: claiming
        // nothing moved would be a guess, and this file does not guess about
        // capacity.
        busy = false;
        board.classList.remove('cwh-dispatch-ops--busy');
        row.classList.remove('is-pending');
        targets.forEach((target) => {
          target.button.disabled = false;
        });
        disarm();
        announce(status, Drupal.t('The proposal did not get an answer, so whether it was applied is unknown. Reload the board and read the day before trying again.'), 'error');
      }
      finally {
        if (timer) {
          window.clearTimeout(timer);
        }
      }
    }

    // The keyboard path, and the one every other path is built on top of. The button
    // toggles, so the same key that armed a row stands it down again.
    rows.forEach((row) => {
      const grab = buildGrab(row);
      grab.addEventListener('click', () => {
        if (armed === row) {
          standDown();
        }
        else {
          arm(row);
        }
      });

      row.setAttribute('draggable', 'true');
      row.addEventListener('dragstart', (event) => {
        if (busy) {
          event.preventDefault();

          return;
        }
        arm(row);
        if (event.dataTransfer) {
          event.dataTransfer.effectAllowed = 'move';
          // Some browsers refuse to start a drag carrying nothing. The identifier is
          // already rendered in this row, so putting it here discloses nothing, and
          // the server reads it from the payload rather than from the drag anyway.
          event.dataTransfer.setData('text/plain', row.getAttribute('data-cwh-reservation') || '');
        }
      });
      row.addEventListener('dragend', () => {
        if (!busy) {
          disarm();
        }
      });
    });

    targets.forEach((target) => {
      target.button.addEventListener('click', () => {
        if (armed) {
          propose(armed, target.day);
        }
      });

      target.day.addEventListener('dragover', (event) => {
        if (!armed || busy) {
          return;
        }
        event.preventDefault();
        if (event.dataTransfer) {
          event.dataTransfer.dropEffect = 'move';
        }
        target.day.classList.add('is-drop-target');
      });

      target.day.addEventListener('dragleave', (event) => {
        if (event.target === target.day) {
          target.day.classList.remove('is-drop-target');
        }
      });

      target.day.addEventListener('drop', (event) => {
        if (!armed || busy) {
          return;
        }
        event.preventDefault();
        propose(armed, target.day);
      });
    });

    board.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') {
        standDown();
      }
    });
  }

  Drupal.behaviors.cwhDispatchBoardOps = {
    attach(context, settings) {
      const bucket = boardSettings(settings);
      if (!bucket) {
        return;
      }

      once(ONCE_ID, BOARD_SELECTOR, context).forEach((board) => {
        initBoard(board, bucket);
      });
    },
  };
})(Drupal, drupalSettings, once);
