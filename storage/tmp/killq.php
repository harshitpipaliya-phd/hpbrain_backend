<?php
require "c:/Users/omshivay/Desktop/ADK/hp-enterprise-brain/vendor/autoload.php";
$app = require_once "c:/Users/omshivay/Desktop/ADK/hp-enterprise-brain/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
// Read-only, abandoned page-load scans. Nothing here writes.
$rows = DB::select("SELECT ID FROM information_schema.PROCESSLIST
  WHERE INFO LIKE '%operational_records%' AND INFO LIKE 'select%' AND TIME > 120");
$n=0;
foreach($rows as $r){ try{ DB::statement("KILL QUERY ".$r->ID); $n++; }catch(\Throwable $e){} }
echo "killed {$n} abandoned read queries\n";
