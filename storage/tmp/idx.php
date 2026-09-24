<?php
require "c:/Users/omshivay/Desktop/ADK/hp-enterprise-brain/vendor/autoload.php";
$app = require_once "c:/Users/omshivay/Desktop/ADK/hp-enterprise-brain/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
foreach(DB::select("SHOW INDEX FROM hpbrain_operational_records") as $r) echo $r->Key_name." seq".$r->Seq_in_index." ".$r->Column_name."\n";
