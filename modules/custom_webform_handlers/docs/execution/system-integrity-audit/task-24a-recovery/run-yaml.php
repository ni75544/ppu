<?php

/**
 * Offline YAML validation for the corrected recovery candidate config files.
 * Parses each with Symfony's YAML component (the parser Drupal itself uses).
 * Exit 0 = all parsed; non-zero = a parse error, printed.
 */

$autoload = '/var/www/html/vendor/autoload.php';
if (!is_file($autoload)) {
  fwrite(STDERR, "autoload missing at $autoload\n");
  exit(2);
}
require $autoload;

use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;

$base = '/var/www/html/modules/custom_webform_handlers/docs/execution/system-integrity-audit/task-24a-recovery/corrected/candidate';
$files = [
  'config/schema/custom_webform_handlers.schema.yml',
  'config/install/custom_webform_handlers.settings.yml',
  'custom_webform_handlers.services.yml',
];

$status = 0;
foreach ($files as $rel) {
  $path = "$base/$rel";
  try {
    Yaml::parseFile($path);
    echo "OK   $rel\n";
  }
  catch (ParseException $e) {
    echo "FAIL $rel :: " . $e->getMessage() . "\n";
    $status = 1;
  }
}
echo "YAML_STATUS=$status\n";
exit($status);
