# Custom Webform Handlers

Current calculator release: `1.1.10`; rate version: `2026-08-production-v1`.

Step 16 clears all computed quote fields before authoritative recalculation, prevents stale totals after route/provider failures, stores classified manual-review metadata, disables caching of preview responses, and reports key/rate readiness in Drupal's Status Report.

Step 15 requires residential access, parking, specialty declaration, stair-flight counts, commercial timing, conditional descriptions, and a valid 10-digit US phone number. The quote calculator independently marks bypassed or incomplete pricing inputs for manual review.

Step 14 adds configurable few-items, 4+ bedroom, commercial timing, and DIY truck-rental rates. It also classifies commercial scope/building constraints, unspecified specialty items, disposal/donation work, and open-ended DIY labor as manual-review conditions while preserving the calculable subtotal.

This module adds address autocomplete, a throttled driving-distance preview,
authoritative server route validation, and preliminary quote calculation to a
Drupal Webform through the **Address and Distance Calculator** handler.

## Security notice

The API key included in the previous JavaScript file was exposed and must be
revoked in Google Cloud. Do not reuse it.

Configure two new restricted keys:

1. Enter the browser key at **Configuration > Web services > Google API
   Settings**. Restrict it by the site's HTTP referrers and required browser
   APIs.
2. Set the server key outside configuration export in `settings.php`:

   ```php
   $settings['custom_webform_handlers_server_api_key'] =
     getenv('CUSTOM_WEBFORM_HANDLERS_SERVER_API_KEY') ?: '';
   ```

   Restrict this key by the web server's outbound IP address and required API.

For browser autocomplete, merely saving a key is not sufficient. Its Google
Cloud project must have billing enabled and **Maps JavaScript API**,
**Places API (New)**, and **Directions API** enabled. The key must be a
**Websites** browser key. Add
the exact staging and production origins to its HTTP-referrer allowlist, for
example `https://example.com/*` and `https://www.example.com/*` when both
hostnames are used. Allow several minutes after changing Google Cloud settings,
then rebuild Drupal caches and reload the form without the browser cache.

If suggestions still do not appear, inspect the browser developer console.
This build reports initialization errors without exposing the key and allows
address autocomplete to initialize even when the distance-preview mapping is
incomplete.

Some Drupal modules or themes load an older Google Maps JavaScript interface
before this module. Version 1.1.2 supports both the current
`PlaceAutocompleteElement` interface and the compatible
`google.maps.places.Autocomplete` interface. Avoid configuring multiple Drupal
modules to load Google Maps independently; ideally, the first Google Maps script
on the page should include the Places library.

Version 1.1.3 also waits for the Places interface after the asynchronous Google
script load event. Google may finish loading the script before `importLibrary()`
and the Places namespace have been exposed, so the script event alone is not
treated as readiness.

## Handler setup

Add the **Address and Distance Calculator** handler to the Webform and map:

- Origin address: `origin_address`
- Destination address: `destination_address`
- Distance: `calculated_distance`
- Canonical distance: `calculated_distance_meters`
- Quote total: `final_calculated_quote`
- Quote currency: `estimated_quote_currency`
- Quote breakdown: `estimated_quote_breakdown`
- Quote status: `estimated_quote_status`
- Quote rate version: `estimated_quote_rate_version`

The legacy distance field may be hidden, numeric, or text. Map the optional
canonical distance, quote total, currency, status, breakdown, and rate-version
fields when those elements have been added to the Webform.

## Upgrade

Back up the site, replace the module files, run database updates, and rebuild
caches. The update hook intentionally removes the legacy shared API key. New
keys must be configured after the update.

This build removes the old public AJAX distance-record endpoint, the unused
Distance Record entity code, and the hardcoded `we` Webform hooks. It does not
drop an existing `distance_record` database table because that could destroy
historical data. Back up and review any existing table separately.

Distance preview and final submission validation now use Google Routes API v2
on the server. The browser no longer calculates distance with the legacy
Distance Matrix service. Existing Twig pricing remains only as a temporary
display layer; the authoritative stored total is calculated by the PHP quote
service. The next Webform YAML phase will replace that Twig pricing display.

All migrated rates are provisional copies of the supplied form values. Review
and approve them before production use.

Administrators can review route limits and quote rates at **Configuration >
Web services > Shipping Quote Settings**. Monetary settings are stored in
cents. Increment the rate version whenever approved production rates change.
