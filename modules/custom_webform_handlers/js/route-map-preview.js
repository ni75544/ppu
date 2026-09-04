/**
 * @file
 * Route map preview driven entirely by the server's validated route.
 *
 * The browser never asks a routing service for directions. It only draws the
 * encoded polyline the site's own preview endpoint returned, and falls back to a
 * text-only status whenever the Maps library, the route geometry, or the
 * addresses are unavailable. The map is decoration: the validated distance is
 * always available as text.
 */
(function (Drupal, drupalSettings, once) {
  'use strict';

  /** How long to wait for a Maps bootstrap owned by the calculator library. */
  const MAPS_WAIT_MS = 10000;

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
   * Returns the settings belonging to one form, or null.
   *
   * A bucket that names a different Webform belongs to a different form on
   * this page, so it is refused rather than borrowed.
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

  /**
   * True when enough of the Maps drawing surface exists to render a polyline.
   */
  function mapsReady() {
    return (
      typeof google !== 'undefined' &&
      google.maps &&
      typeof google.maps.Map === 'function' &&
      typeof google.maps.Polyline === 'function' &&
      typeof google.maps.LatLng === 'function' &&
      typeof google.maps.LatLngBounds === 'function'
    );
  }

  /**
   * Decodes a Google encoded polyline, returning [] on any malformed input.
   */
  function decodePolyline(encoded) {
    if (typeof encoded !== 'string' || encoded.length < 2) {
      return [];
    }

    const points = [];
    let index = 0;
    let latitude = 0;
    let longitude = 0;

    const decodeValue = () => {
      let value = 0;
      let shift = 0;
      let byte;
      do {
        if (index >= encoded.length) {
          return null;
        }
        byte = encoded.charCodeAt(index) - 63;
        index += 1;
        if (byte < 0 || byte > 63) {
          return null;
        }
        value |= (byte & 0x1f) << shift;
        shift += 5;
      } while (byte >= 0x20);

      return value & 1 ? ~(value >> 1) : value >> 1;
    };

    while (index < encoded.length) {
      const latitudeChange = decodeValue();
      const longitudeChange = decodeValue();
      if (latitudeChange === null || longitudeChange === null) {
        return [];
      }
      latitude += latitudeChange;
      longitude += longitudeChange;
      const lat = latitude / 1e5;
      const lng = longitude / 1e5;
      if (
        !Number.isFinite(lat) ||
        !Number.isFinite(lng) ||
        lat < -90 ||
        lat > 90 ||
        lng < -180 ||
        lng > 180
      ) {
        return [];
      }
      points.push({ lat, lng });
    }

    return points;
  }

  /**
   * Builds the named, labelled preview region once per Webform.
   */
  function buildRegion(form, webformId) {
    const existing = form.querySelector('[data-route-map-preview]');
    if (existing) {
      return existing;
    }

    regionCounter += 1;
    const id = `custom-route-map-preview--${webformId}-${regionCounter}`;

    const region = element('section', 'custom-route-map-preview');
    region.id = id;
    region.hidden = true;
    region.setAttribute('data-route-map-preview', '');
    region.setAttribute('role', 'region');
    region.setAttribute('aria-labelledby', `${id}-title`);
    region.setAttribute('aria-describedby', `${id}-status`);

    const header = element('div', 'custom-route-map-preview__header');
    const title = element(
      'h3',
      'custom-route-map-preview__title',
      Drupal.t('Route preview'),
    );
    title.id = `${id}-title`;
    header.appendChild(title);

    const status = element('p', 'custom-route-map-preview__status');
    status.id = `${id}-status`;
    status.setAttribute('data-route-map-status', '');
    status.setAttribute('role', 'status');
    status.setAttribute('aria-live', 'polite');
    status.setAttribute('aria-atomic', 'true');
    header.appendChild(status);
    region.appendChild(header);

    // Plain-text description of the same route, for anyone who cannot use the
    // map surface at all.
    const summary = element('p', 'custom-route-map-preview__summary');
    summary.setAttribute('data-route-map-summary', '');
    region.appendChild(summary);

    const canvas = element('div', 'custom-route-map-preview__canvas');
    canvas.setAttribute('data-route-map-canvas', '');
    // An interactive, pannable surface. Google's own map container provides the
    // focusable element and keyboard handling inside it.
    canvas.setAttribute('role', 'application');
    canvas.setAttribute('aria-label', Drupal.t('Driving route map'));
    region.appendChild(canvas);

    const slot = form.querySelector('[data-custom-quote-experience-main]');
    if (slot) {
      slot.appendChild(region);
      return region;
    }
    const progress = form.querySelector('.webform-progress');
    if (progress) {
      progress.insertAdjacentElement('afterend', region);
    } else {
      form.insertAdjacentElement('afterbegin', region);
    }
    return region;
  }

  function setupForm(form, config) {
    const region = buildRegion(form, config.webform_id || 'webform');
    const status = region.querySelector('[data-route-map-status]');
    const summary = region.querySelector('[data-route-map-summary]');
    const canvas = region.querySelector('[data-route-map-canvas]');

    let map = null;
    let polyline = null;
    let waitTimer = null;
    let sequence = 0;

    function setStatus(message) {
      status.textContent = message;
    }

    function setSummary(message) {
      summary.textContent = message;
    }

    function distanceLabel(distance) {
      const miles = Number(distance && distance.distance_miles);
      return Number.isFinite(miles) && miles > 0
        ? Drupal.t('Validated driving distance: @miles mi', {
            '@miles': miles.toFixed(2),
          })
        : Drupal.t('Driving route preview');
    }

    function stopWaiting() {
      if (waitTimer) {
        window.clearInterval(waitTimer);
        waitTimer = null;
      }
    }

    /**
     * Waits for the Maps bootstrap the calculator library owns. This behavior
     * never injects the script itself.
     */
    function whenMapsReady(onReady, onUnavailable) {
      stopWaiting();
      if (mapsReady()) {
        onReady();
        return;
      }
      const startedAt = Date.now();
      waitTimer = window.setInterval(() => {
        if (mapsReady()) {
          stopWaiting();
          onReady();
        } else if (Date.now() - startedAt >= MAPS_WAIT_MS) {
          stopWaiting();
          onUnavailable();
        }
      }, 200);
    }

    function clearOverlay() {
      if (polyline) {
        polyline.setMap(null);
        polyline = null;
      }
    }

    function ensureMap() {
      if (map) {
        return true;
      }
      if (!mapsReady()) {
        return false;
      }
      map = new google.maps.Map(canvas, {
        center: { lat: 39.8283, lng: -98.5795 },
        zoom: 4,
        mapTypeControl: false,
        streetViewControl: false,
        fullscreenControl: false,
        clickableIcons: false,
        keyboardShortcuts: true,
        gestureHandling: 'cooperative',
      });
      return true;
    }

    /**
     * Draws the server-returned geometry. Returns false when it is unusable.
     */
    function drawRoute(encodedPolyline) {
      const points = decodePolyline(encodedPolyline);
      if (points.length < 2) {
        // Malformed or too-short geometry. Drop the previous route so the map
        // cannot keep showing a path the status text calls undrawable.
        clearOverlay();
        return false;
      }

      const bounds = new google.maps.LatLngBounds();
      const path = points.map((point) => {
        const coordinate = new google.maps.LatLng(point.lat, point.lng);
        bounds.extend(coordinate);
        return coordinate;
      });

      clearOverlay();
      polyline = new google.maps.Polyline({
        path,
        map,
        geodesic: true,
        strokeColor: '#1d4ed8',
        strokeOpacity: 0.9,
        strokeWeight: 5,
      });
      map.fitBounds(bounds, 48);
      return true;
    }

    function clearRoute() {
      sequence += 1;
      stopWaiting();
      clearOverlay();
      region.hidden = true;
      setStatus('');
      setSummary('');
    }

    function displayRoute(detail) {
      const route = detail || {};
      if (!route.origin || !route.destination) {
        clearRoute();
        return;
      }

      const encoded =
        typeof route.encodedPolyline === 'string' && route.encodedPolyline
          ? route.encodedPolyline
          : (route.distance && route.distance.encoded_polyline) || '';

      const request = ++sequence;
      region.hidden = false;
      setStatus(Drupal.t('Loading the route map…'));
      setSummary(
        Drupal.t('Route from @origin to @destination. @distance', {
          '@origin': route.origin,
          '@destination': route.destination,
          '@distance': distanceLabel(route.distance),
        }),
      );

      if (!encoded) {
        // The route was validated but carries no geometry: text only. Any
        // polyline from the previous route must go, or the map would keep
        // showing a route the status text says is unavailable.
        clearOverlay();
        setStatus(
          Drupal.t('@distance. A map of this route is unavailable.', {
            '@distance': distanceLabel(route.distance),
          }),
        );
        return;
      }

      whenMapsReady(
        () => {
          if (request !== sequence) {
            return;
          }
          if (!ensureMap() || !drawRoute(encoded)) {
            setStatus(
              Drupal.t('@distance. A map of this route could not be drawn.', {
                '@distance': distanceLabel(route.distance),
              }),
            );
            return;
          }
          setStatus(distanceLabel(route.distance));
        },
        () => {
          if (request !== sequence) {
            return;
          }
          setStatus(
            Drupal.t('@distance. The map could not be loaded.', {
              '@distance': distanceLabel(route.distance),
            }),
          );
        },
      );
    }

    form.addEventListener('custom-webform-route-validated', (event) => {
      displayRoute(event.detail);
    });
    form.addEventListener('custom-webform-route-cleared', clearRoute);
  }

  Drupal.behaviors.customWebformRouteMapPreview = {
    attach(context, settings) {
      once('custom-webform-route-map-preview', FORM_SELECTOR, context).forEach(
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
