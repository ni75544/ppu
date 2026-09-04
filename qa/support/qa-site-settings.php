<?php

/**
 * @file
 * Versioned, credential-free settings for the isolated QA site.
 *
 * qa/support/qa-env-setup.sh copies this to sites/qa/settings.php, which
 * .gitignore excludes along with every other site settings file. Keeping the
 * source of truth here means the QA environment is reproducible from versioned
 * code without a settings file — and therefore a database password — entering
 * Git.
 *
 * Nothing is hardcoded: DDEV already generates the connection details, the hash
 * salt and the trusted-host patterns for this project, so those are reused and
 * only the database name is overridden. That keeps the QA site on the same
 * server, user and password as the default site while pointing it at a different
 * schema.
 */

// DDEV's generated include supplies $databases, hash_salt and trusted hosts.
$ddev_settings = __DIR__ . '/../default/settings.ddev.php';
if (getenv('IS_DDEV_PROJECT') === 'true' && is_readable($ddev_settings)) {
  require $ddev_settings;
}

// The one difference that matters: a separate schema. Everything above stays as
// DDEV configured it, so there is no second copy of a credential anywhere.
$databases['default']['default']['database'] = 'qa';

if (getenv('IS_DDEV_PROJECT') === 'true' && file_exists(__DIR__ . '/../default/settings.ddev.redis.php')) {
  include __DIR__ . '/../default/settings.ddev.redis.php';
}

// Redis is shared with the default site, so the QA site needs its own key space.
// Without this, a cache or flood key written by one site would be read by the
// other, and the throttle reset the gate performs would clear the wrong bucket.
// Set after the Redis include so the include cannot put the two sites back into
// one key space.
$settings['cache_prefix'] = 'qa_';

// Files live under this site directory, not the default site's.
$settings['file_public_path'] = 'sites/qa/files';
$settings['file_private_path'] = 'sites/qa/private';
$settings['config_sync_directory'] = 'sites/qa/files/config/sync';

// A QA site is for reading the customer-facing flow, not for administering it.
// Leaving the update route open would let a mistyped URL run database updates
// against the fixture data.
$settings['update_free_access'] = FALSE;

// The Routes API key is a settings value, not database configuration, so a cloned
// database arrives without it and every distance preview answers
// `provider_not_configured` — which shows up as a Courier form that quotes
// nothing and offers no Slots. Rather than copy the secret into a second file
// that could then be committed or leaked, read it from the one file that already
// holds it. Nothing else from that file is executed or imported.
if (empty($settings['custom_webform_handlers_server_api_key'])) {
  $source = @file_get_contents(__DIR__ . '/../default/settings.php');
  if ($source !== FALSE && preg_match(
    '/\$settings\[\s*[\'"]custom_webform_handlers_server_api_key[\'"]\s*\]\s*=\s*([\'"])(.*?)\1/s',
    $source, $matches) === 1) {
    $settings['custom_webform_handlers_server_api_key'] = $matches[2];
  }
}
