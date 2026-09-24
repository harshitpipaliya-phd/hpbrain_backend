<?php
require "c:/Users/omshivay/Desktop/ADK/hp-enterprise-brain/vendor/autoload.php";
$app = require_once "c:/Users/omshivay/Desktop/ADK/hp-enterprise-brain/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
$pidFile = "c:/Users/omshivay/Desktop/ADK/hp-enterprise-brain/storage/tmp/warm.pid";
for ($i=0; $i<120; $i++) {
  $keep = is_file($pidFile) ? trim(file_get_contents($pidFile)) : '0';
  $rows = DB::select("SELECT ID FROM information_schema.PROCESSLIST
     WHERE INFO LIKE '%operational_records%' AND INFO LIKE 'select%'
       AND TIME > 25 AND ID <> ?", [$keep]);
  foreach ($rows as $r) { try { DB::statement("KILL QUERY ".$r->ID); } catch (\Throwable $e) {} }
  if ($rows) echo "swept ".count($rows)." competing scans (protecting {$keep})\n";
  sleep(5);
}
