<?php
require "c:/Users/omshivay/Desktop/ADK/hp-enterprise-brain/vendor/autoload.php";
$app = require_once "c:/Users/omshivay/Desktop/ADK/hp-enterprise-brain/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
$T='1000010';
$s=microtime(true);
DB::select("SELECT status, COUNT(*) c FROM hpbrain_signals WHERE tenant_id=? GROUP BY status",[$T]);
printf("GROUP BY status   (INDEXED tenant,status): %6.0f ms\n",(microtime(true)-$s)*1000);
$s=microtime(true);
DB::select("SELECT severity, COUNT(*) c FROM hpbrain_signals WHERE tenant_id=? GROUP BY severity",[$T]);
printf("GROUP BY severity (NOT indexed)          : %6.0f ms\n",(microtime(true)-$s)*1000);
