<?php
/* Removes accounts whose 30-day deletion grace has ended.
   Run from the command line (not from a browser):
       C:\xampp\php\php.exe C:\xampp\htdocs\Dezzme\purge_deleted_accounts.php
   Schedule it once a day with Windows Task Scheduler (or cron on Linux).
   Even without it, the site runs the same purge about once an hour from normal page traffic. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(__DIR__);
include __DIR__ . '/config.php';
require_once __DIR__ . '/account_purge.php';
$n = dz_purge_due($conn, 200);
echo date('c') . "  purged $n account(s)\n";