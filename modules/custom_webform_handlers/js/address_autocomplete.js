/**
 * @file
 * Address suggestions and the single address-pair commit boundary.
 *
 * Suggestions stay on the self-hosted endpoint. Google Routes is deliberately
 * reached only by the server-side distance preview after this behavior emits
 * one committed pair; typing must never call a billed Google API.
 */
(function ($, Drupal, once) {
  'use strict';

  const ONCE_ID = 'custom-webform-address-autocomplete';
  const INPUT_SELECTOR = 'input[data-custom-address-role]';
  const MIN_ADDRESS_LENGTH = 6;
  const COMMIT_DELAY_MS = 600;
  const instances = new WeakMap();
  const forms = new WeakMap();

  /**
   * Normalizes address identity without changing the submitted display value.
   */
  function normalize(value) {
    return String(value || '')
      .replace(/[\u0000-\u001f\u007f]+/g, ' ')
      .replace(/\s+/g, ' ')
      .trim()
      .toLowerCase()
      .replace(/\s*,\s*/g, ', ')
      .replace(/(?:,\s*){2,}/g, ', ')
      .replace(/^[\s,]+|[\s,]+$/g, '');
  }

  /**
   * Rejects fragments before they can become a route request.
   */
  function isComplete(value) {
    const address = normalize(value);
    return (
      address.length >= MIN_ADDRESS_LENGTH &&
      address.split(' ').filter(Boolean).length >= 2 &&
      (/\d/.test(address) || address.includes(','))
    );
  }

  /**
   * Returns the one state machine shared by both inputs in a form.
   */
  function formState(form) {
    let state = forms.get(form);
    if (!state) {
      state = {
        committed: { origin: '', destination: '' },
        lastEmittedPair: '',
        commitTimer: null,
        commitDelay: COMMIT_DELAY_MS,
        inputCount: 0,
      };
      forms.set(form, state);
    }
    return state;
  }

  /**
   * Emits one event only when both current values have been committed.
   */
  function schedulePairCommit(form) {
    const state = formState(form);
    window.clearTimeout(state.commitTimer);
    state.commitTimer = window.setTimeout(() => {
      state.commitTimer = null;
      const origin = state.committed.origin;
      const destination = state.committed.destination;
      if (!origin || !destination) {
        return;
      }
      const pair = JSON.stringify([origin, destination]);
      if (pair === state.lastEmittedPair) {
        return;
      }
      state.lastEmittedPair = pair;
      form.dispatchEvent(
        new CustomEvent('custom-webform-address-committed', {
          bubbles: true,
          detail: { pair },
        }),
      );
    }, state.commitDelay);
  }

  /**
   * Marks one field as committed and attempts the pair boundary.
   */
  function commitInput(input) {
    const role = input.getAttribute('data-custom-address-role');
    if (role !== 'origin' && role !== 'destination') {
      return;
    }
    const state = formState(input.form);
    state.committed[role] = isComplete(input.value)
      ? normalize(input.value)
      : '';
    schedulePairCommit(input.form);
  }

  /**
   * Invalidates only the edited endpoint; no route request is made here.
   */
  function dirtyInput(input) {
    const role = input.getAttribute('data-custom-address-role');
    if (role !== 'origin' && role !== 'destination') {
      return;
    }
    const state = formState(input.form);
    state.committed[role] = '';
    state.lastEmittedPair = '';
    window.clearTimeout(state.commitTimer);
  }

  Drupal.behaviors.customWebformAddressAutocomplete = {
    attach(context) {
      once(ONCE_ID, INPUT_SELECTOR, context).forEach((input) => {
        const form = input.form;
        if (!form) {
          return;
        }
        const state = formState(form);
        state.inputCount += 1;
        const configuredDelay = Number(
          input.getAttribute('data-custom-address-commit-delay'),
        );
        if (Number.isFinite(configuredDelay) && configuredDelay > 0) {
          state.commitDelay = Math.max(
            100,
            Math.min(5000, Math.round(configuredDelay)),
          );
        }

        // A restored wizard value is known, but it is not dispatched on attach.
        // It can only cause a route request after an explicit selection or blur.
        const role = input.getAttribute('data-custom-address-role');
        if (
          (role === 'origin' || role === 'destination') &&
          isComplete(input.value)
        ) {
          state.committed[role] = normalize(input.value);
        }

        const onInput = () => dirtyInput(input);
        const onBlur = () => commitInput(input);
        input.addEventListener('input', onInput);
        input.addEventListener('blur', onBlur);

        const endpoint = input.getAttribute(
          'data-custom-address-suggestions-url',
        );
        let request = null;
        const $input = $(input);
        if (endpoint && typeof $.fn.autocomplete === 'function') {
          $input.autocomplete({
            delay: 600,
            minLength: MIN_ADDRESS_LENGTH,
            source(_request, response) {
              const query = String(input.value || '')
                .replace(/[\u0000-\u001f\u007f]+/g, ' ')
                .replace(/\s+/g, ' ')
                .trim();
              if (
                query.length < MIN_ADDRESS_LENGTH ||
                query.length > 200
              ) {
                response([]);
                return;
              }
              if (request) {
                request.abort();
              }
              const currentRequest = $.ajax({
                url: endpoint,
                method: 'GET',
                dataType: 'json',
                cache: false,
                timeout: 8000,
                data: { q: query },
              });
              request = currentRequest;
              currentRequest
                .done((data) => {
                  response(Array.isArray(data) ? data : []);
                })
                .fail((_xhr, status) => {
                  if (status !== 'abort') {
                    response([]);
                  }
                })
                .always(() => {
                  if (request === currentRequest) {
                    request = null;
                  }
                });
            },
            focus() {
              return false;
            },
            select(event, ui) {
              event.preventDefault();
              const value = String(
                ui.item && ui.item.value ? ui.item.value : '',
              );
              if (!value) {
                return false;
              }
              input.value = value;
              // Preserve Webform #states integration. Neither event can request
              // a route; the custom committed event below is the only trigger.
              input.dispatchEvent(new Event('input', { bubbles: true }));
              input.dispatchEvent(new Event('change', { bubbles: true }));
              commitInput(input);
              return false;
            },
          });
        }

        instances.set(input, {
          destroy() {
            input.removeEventListener('input', onInput);
            input.removeEventListener('blur', onBlur);
            if (request) {
              request.abort();
              request = null;
            }
            if (
              endpoint &&
              typeof $.fn.autocomplete === 'function' &&
              $input.autocomplete('instance')
            ) {
              $input.autocomplete('destroy');
            }
            state.inputCount -= 1;
            if (state.inputCount <= 0) {
              window.clearTimeout(state.commitTimer);
              forms.delete(form);
            }
          },
        });
      });
    },

    detach(context, _settings, trigger) {
      if (trigger !== 'unload') {
        return;
      }
      once.remove(ONCE_ID, INPUT_SELECTOR, context).forEach((input) => {
        const instance = instances.get(input);
        if (instance) {
          instance.destroy();
          instances.delete(input);
        }
      });
    },
  };
})(jQuery, Drupal, once);
