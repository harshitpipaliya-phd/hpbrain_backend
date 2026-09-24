<?php
require "c:/Users/omshivay/Desktop/ADK/hp-enterprise-brain/vendor/autoload.php";
$app = require_once "c:/Users/omshivay/Desktop/ADK/hp-enterprise-brain/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
// The ALTER is queued behind a rolling queue of read scans and is being
// starved. Clear competing reads until it gets its lock window.
for ($i = 0; $i < 150; $i++) {
    $done = DB::select("SHOW INDEX FROM hpbrain_operational_records WHERE Key_name='idx_oprec_tenant_updated'");
    if (!empty($done)) { echo "INDEX READY after {$i} sweeps\n"; exit(0); }
    $rows = DB::select("SELECT ID FROM information_schema.PROCESSLIST
        WHERE INFO LIKE '%operational_records%' AND INFO LIKE 'select%' AND TIME > 15");
    foreach ($rows as $r) { try { DB::statement("KILL QUERY ".$r->ID); } catch (\Throwable $e) {} }
    if ($rows) echo "swept ".count($rows)." reads\n";
    sleep(2);
}
echo "GAVE UP - index still not built\n";
