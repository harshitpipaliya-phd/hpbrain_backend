<?php
require "c:/Users/omshivay/Desktop/ADK/hp-enterprise-brain/vendor/autoload.php";
$app = require_once "c:/Users/omshivay/Desktop/ADK/hp-enterprise-brain/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
$T='1000010'; $D='lions-result-data';
// B: split — non-distinct scalars in one pass, each DISTINCT on its own index
$s=microtime(true);
DB::selectOne("SELECT COUNT(*) r, COUNT(category) a, COUNT(status) b, COUNT(subject_ref) c,
   MIN(occurred_at) f, MAX(occurred_at) l, AVG(metric_value) m
   FROM hpbrain_operational_records WHERE tenant_id=? AND dataset=?", [$T,$D]);
printf("  scalars-one-pass      : %6.0f ms\n",(microtime(true)-$s)*1000);
foreach (['category','sub_category','status','subject_ref','zone','owner_name'] as $col) {
  $s=microtime(true);
  DB::selectOne("SELECT COUNT(DISTINCT `$col`) d FROM hpbrain_operational_records WHERE tenant_id=? AND dataset=?", [$T,$D]);
  printf("  DISTINCT %-14s: %6.0f ms\n",$col,(microtime(true)-$s)*1000);
}
foreach (['natural_key','source_file','import_job_id','metric_unit','area','supervisor_name'] as $col) {
  $s=microtime(true);
  DB::selectOne("SELECT COUNT(DISTINCT `$col`) d FROM hpbrain_operational_records WHERE tenant_id=? AND dataset=?", [$T,$D]);
  printf("  DISTINCT %-14s: %6.0f ms  (no dedicated index)\n",$col,(microtime(true)-$s)*1000);
}
