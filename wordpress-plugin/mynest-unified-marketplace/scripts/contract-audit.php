<?php
$root = dirname(__DIR__);
$checks = [
  'version' => ['mynest-unified-marketplace.php', "define( 'MNU_VERSION', '3.13.38' )"],
  'db schema' => ['mynest-unified-marketplace.php', "define( 'MNU_DB_VERSION', '3.0.20' )"],
  'atomic address route' => ['includes/class-tnm-rest.php', '/me/contact-address'],
  'complete address validation' => ['includes/class-tnm-rest.php', 'validate_complete_address_row'],
  'tax response' => ['includes/class-mnu-native-checkout.php', "'tax_total'"],
  'shipping fee flag' => ['includes/class-mnu-native-checkout.php', '$shipping_includes_processing_fee'],
  'delivery return clock' => ['includes/class-mnu-refund-lifecycle.php', '14-day RETURN'],
  'hashed signup code' => ['includes/class-mnu-signup.php', 'hash_code( $code )'],
  'hashed code schema' => ['includes/class-mnu-install.php', 'code varchar(255) NOT NULL'],
];
$fail = 0;
foreach ($checks as $name => [$file,$needle]) {
  $text = file_get_contents($root . '/' . $file);
  $ok = $text !== false && strpos($text, $needle) !== false;
  echo ($ok ? 'PASS' : 'FAIL') . " - $name\n";
  if (!$ok) $fail++;
}
exit($fail ? 1 : 0);
