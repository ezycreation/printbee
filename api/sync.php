<?php
// CLI only:  php api/sync.php [--force]      (run from cron, e.g. every 15 minutes)
if(PHP_SAPI !== 'cli'){ http_response_code(403); exit("CLI only\n"); }
require __DIR__ . '/hymns_lib.php';
$force = in_array('--force', $argv ?? [], true);
$r = hymns_sync($force);
echo json_encode($r, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
exit(in_array($r['status'], ['updated', 'not_modified', 'fresh', 'backoff'], true) ? 0 : 1);
