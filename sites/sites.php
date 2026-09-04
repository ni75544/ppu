<?php

/**
 * @file
 * Maps the QA hostname onto the `sites/qa` directory.
 *
 * Browser acceptance must not run against the database that holds real and
 * historical submissions, so the permanent gate gets its own site directory and
 * its own database on the same DDEV project and the same codebase. Sharing the
 * codebase is deliberate: a QA site built from a different copy of the module
 * would prove something about that copy instead of about this one.
 *
 * The customer-facing site (`ppu.ddev.site`) is not listed and keeps resolving to
 * `sites/default` through Drupal's own fallback.
 *
 * Created by qa/support/qa-env-setup.sh; safe to keep in Git because it holds no
 * credentials.
 */

$sites['ppu-qa.ddev.site'] = 'qa';
$sites['ppu-qa.ddev.site.'] = 'qa';
