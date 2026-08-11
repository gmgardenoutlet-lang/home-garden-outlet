<?php
declare(strict_types=1);

require __DIR__ . '/lib.php';
boot_admin();
require_login();

// This is intentionally the exact loader used by the checkout integration.
require_once __DIR__ . '/../shop-test/config.php';

$privateConfigReadable = isset($hgoPrivateConfigFile)
    && is_file($hgoPrivateConfigFile)
    && is_readable($hgoPrivateConfigFile);
$environment = in_array(PAYNOW_ENV, ['production', 'sandbox'], true) ? PAYNOW_ENV : 'brak';

$diagnostic = [
    'Paynow private config readable' => $privateConfigReadable,
    'Environment' => $environment,
    'Api-Key configured' => PAYNOW_API_KEY !== '',
    'Signature-Key configured' => PAYNOW_SIGNATURE_KEY !== '',
    'Paynow enabled' => PAYNOW_ENABLED,
];
?>
<!doctype html>
<html lang="pl">
<head>
  <meta charset="utf-8">
  <meta name="robots" content="noindex,nofollow,noarchive">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Diagnostyka Paynow | Home &amp; Garden Outlet</title>
  <link rel="stylesheet" href="/admin/style.css">
</head>
<body>
  <main class="narrow">
    <section class="card">
      <h1>Diagnostyka Paynow</h1>
      <dl>
        <?php foreach ($diagnostic as $label => $value): ?>
          <dt><?= e($label) ?></dt>
          <dd><?= is_bool($value) ? ($value ? 'TAK' : 'NIE') : e($value) ?></dd>
        <?php endforeach; ?>
      </dl>
    </section>
  </main>
</body>
</html>
