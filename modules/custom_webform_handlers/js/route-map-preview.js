(function (Drupal, once) {
  'use strict';

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

  function createPreview(form) {
    const existing = form.querySelector('#route-map-preview');
    if (existing) {
      return existing;
    }

    const preview = createElement('section', 'custom-route-map-preview');
    preview.id = 'route-map-preview';
    preview.hidden = true;
    preview.setAttribute('aria-label', 'Interactive route map preview');

    const header = createElement('div', 'custom-route-map-preview__header');
    header.appendChild(createElement('h3', 'custom-route-map-preview__title', 'Route preview'));
    const status = createElement('p', 'custom-route-map-preview__status');
    status.setAttribute('data-route-map-status', '');
    status.setAttribute('aria-live', 'polite');
    status.setAttribute('role', 'status');
    header.appendChild(status);
    preview.appendChild(header);

    const canvas = createElement('div', 'custom-route-map-preview__canvas');
    canvas.setAttribute('data-route-map-canvas', '');
    canvas.setAttribute('aria-label', 'Interactive driving route map');
    preview.appendChild(canvas);

    const quotePreview = form.querySelector('[data-custom-quote-preview]');
    if (quotePreview) {
      quotePreview.insertAdjacentElement('afterend', preview);
    } else {
      const progress = form.querySelector('.webform-progress');
      if (progress) {
        progress.insertAdjacentElement('afterend', preview);
      } else {
        form.insertAdjacentElement('afterbegin', preview);
      }
    }

    return preview;
  }

  function mapsReady() {
    return typeof google !== 'undefined'
      && google.maps
      && typeof google.maps.Map === 'function'
      && typeof google.maps.Polyline === 'function'
      && typeof google.maps.LatLng === 'function'
      && typeof google.maps.LatLngBounds === 'function';
  }

  function directionsReady() {
    return mapsReady()
      && typeof google.maps.DirectionsService === 'function'
      && typeof google.maps.DirectionsRenderer === 'function';
  }

  Drupal.behaviors.routeMapPreview = {
    attach(context) {
      once(
        'route-map-preview',
        'form.webform-submission-form, form[id^="webform-submission-"]',
        context,
      ).forEach((form) => {
        const preview = createPreview(form);
        const canvas = preview.querySelector('[data-route-map-canvas]');
        const status = preview.querySelector('[data-route-map-status]');
        let map;
        let directionsService;
        let directionsRenderer;
        let routePolyline;
        let activeRequest = 0;
        let mapsWaitTimer;

        function setStatus(message) {
          status.textContent = message;
        }

        function initializeMap() {
          if (map || !mapsReady()) {
            return Boolean(map);
          }

          map = new google.maps.Map(canvas, {
            center: {lat: 39.8283, lng: -98.5795},
            zoom: 4,
            mapTypeControl: false,
            streetViewControl: false,
            fullscreenControl: false,
            clickableIcons: false,
            gestureHandling: 'cooperative',
          });
          return true;
        }

        function waitForMaps(callback) {
          if (mapsReady()) {
            callback();
            return;
          }

          const startedAt = Date.now();
          mapsWaitTimer = window.setInterval(() => {
            if (mapsReady()) {
              window.clearInterval(mapsWaitTimer);
              callback();
            } else if (Date.now() - startedAt >= 10000) {
              window.clearInterval(mapsWaitTimer);
              preview.hidden = false;
              setStatus('Route map is unavailable. Your estimate is still available.');
            }
          }, 200);
        }

        function clearRouteOverlay() {
          if (routePolyline) {
            routePolyline.setMap(null);
            routePolyline = null;
          }
          if (directionsRenderer) {
            directionsRenderer.setMap(null);
            directionsRenderer = null;
          }
        }

        function decodePolyline(encodedPolyline) {
          const points = [];
          let index = 0;
          let latitude = 0;
          let longitude = 0;

          const decodeValue = () => {
            let value = 0;
            let shift = 0;
            let byte;
            do {
              if (index >= encodedPolyline.length) {
                return null;
              }
              byte = encodedPolyline.charCodeAt(index++) - 63;
              if (byte < 0 || byte > 63) {
                return null;
              }
              value |= (byte & 0x1f) << shift;
              shift += 5;
            } while (byte >= 0x20);

            return value & 1 ? ~(value >> 1) : value >> 1;
          };

          while (index < encodedPolyline.length) {
            const latitudeChange = decodeValue();
            const longitudeChange = decodeValue();
            if (latitudeChange === null || longitudeChange === null) {
              return [];
            }
            latitude += latitudeChange;
            longitude += longitudeChange;
            points.push({lat: latitude / 1e5, lng: longitude / 1e5});
          }

          return points;
        }

        function renderEncodedPolyline(encodedPolyline) {
          const points = decodePolyline(encodedPolyline);
          if (points.length < 2) {
            return false;
          }

          const bounds = new google.maps.LatLngBounds();
          const path = points.map((point) => {
            const coordinate = new google.maps.LatLng(point.lat, point.lng);
            bounds.extend(coordinate);
            return coordinate;
          });
          routePolyline = new google.maps.Polyline({
            path,
            map,
            geodesic: true,
            strokeColor: '#2563eb',
            strokeOpacity: 0.9,
            strokeWeight: 5,
          });
          map.fitBounds(bounds, 48);
          return true;
        }

        function renderDirectionsFallback(route, requestId) {
          if (!directionsReady()) {
            setStatus('Route preview is unavailable. Your estimate is still available.');
            return;
          }

          directionsService = new google.maps.DirectionsService();
          directionsRenderer = new google.maps.DirectionsRenderer({
            map,
            suppressInfoWindows: true,
            polylineOptions: {
              strokeColor: '#2563eb',
              strokeOpacity: 0.9,
              strokeWeight: 5,
            },
          });
          directionsService.route({
            origin: route.origin,
            destination: route.destination,
            travelMode: google.maps.TravelMode.DRIVING,
          }, (result, routeStatus) => {
            if (requestId !== activeRequest) {
              return;
            }
            if (routeStatus !== 'OK' || !result) {
              console.warn('Google Maps Directions fallback failed:', routeStatus);
              setStatus('Route preview is unavailable. Your estimate is still available.');
              return;
            }

            directionsRenderer.setDirections(result);
            const distanceMiles = Number(route.distance && route.distance.distance_miles);
            setStatus(Number.isFinite(distanceMiles) && distanceMiles > 0
              ? `Validated driving distance: ${distanceMiles.toFixed(2)} mi`
              : 'Driving route preview');
          });
        }

        function clearRoute() {
          activeRequest += 1;
          if (mapsWaitTimer) {
            window.clearInterval(mapsWaitTimer);
          }
          clearRouteOverlay();
          directionsService = null;
          map = null;
          canvas.replaceChildren();
          preview.hidden = true;
          setStatus('');
        }

        function displayRoute(route) {
          if (!route || !route.origin || !route.destination) {
            return;
          }

          preview.hidden = false;
          setStatus('Loading driving route…');
          const requestId = ++activeRequest;
          const renderRoute = () => {
            if (requestId !== activeRequest) {
              return;
            }
            if (!initializeMap()) {
              return;
            }

            clearRouteOverlay();
            const encodedPolyline = typeof route.encodedPolyline === 'string'
              ? route.encodedPolyline
              : route.distance && route.distance.encoded_polyline;
            if (typeof encodedPolyline === 'string' && renderEncodedPolyline(encodedPolyline)) {
              const distanceMiles = Number(route.distance && route.distance.distance_miles);
              setStatus(Number.isFinite(distanceMiles) && distanceMiles > 0
                ? `Validated driving distance: ${distanceMiles.toFixed(2)} mi`
                : 'Driving route preview');
              return;
            }

            renderDirectionsFallback(route, requestId);
          };

          waitForMaps(renderRoute);
        }

        form.addEventListener('custom-webform-route-validated', (event) => {
          displayRoute(event.detail);
        });
        form.addEventListener('custom-webform-route-cleared', clearRoute);
      });
    },
  };
})(Drupal, once);
