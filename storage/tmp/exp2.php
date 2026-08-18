<?php
require "c:/Users/omshivay/Desktop/ADK/hp-enterprise-brain/vendor/autoload.php";
$app = require_once "c:/Users/omshivay/Desktop/ADK/hp-enterprise-brain/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
$T='1000010';
$s=microtime(true); $sig=DB::table('hpbrain_signals')->where('tenant_id',$T)->get();
printf("signals  ->get()  : %6.0f ms  (%d rows, %.1f MB)\n",(microtime(true)-$s)*1000,count($sig),strlen(serialize($sig))/1048576);
$s=microtime(true); $ev=DB::table('hpbrain_evidence')->where('tenant_id',$T)->get();
printf("evidence ->get()  : %6.0f ms  (%d rows, %.1f MB)\n",(microtime(true)-$s)*1000,count($ev),strlen(serialize($ev))/1048576);
$s=microtime(true);
DB::table('hpbrain_signals')->where('tenant_id',$T)
  ->selectRaw("COUNT(*) total, SUM(severity='critical') c, SUM(severity='high') h, SUM(severity='medium') m, SUM(severity='low') l")->first();
printf("signals  aggregate: %6.0f ms\n",(microtime(true)-$s)*1000);
$s=microtime(true);
DB::table('hpbrain_evidence')->where('tenant_id',$T)
  ->selectRaw("COUNT(*) total, AVG(confidence) avg_conf, SUM(created_date < ?) stale", [now()->subDays(30)->format('Y-m-d H:i:s')])->first();
printf("evidence aggregate: %6.0f ms\n",(microtime(true)-$s)*1000);
