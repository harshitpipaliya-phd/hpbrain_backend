<?php
require "c:/Users/omshivay/Desktop/ADK/hp-enterprise-brain/vendor/autoload.php";
$app = require_once "c:/Users/omshivay/Desktop/ADK/hp-enterprise-brain/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
$T='1000010';
echo "-- oprec columns\n";
foreach(DB::select("SELECT COLUMN_NAME c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='hpbrain_operational_records' ORDER BY ORDINAL_POSITION") as $r) echo $r->c." ";
echo "\n-- result sample\n";
foreach(DB::select("SELECT * FROM hpbrain_operational_records WHERE tenant_id=? AND dataset='lions-result-data' LIMIT 2",[$T]) as $r) echo json_encode($r)."\n";
echo "-- fee sample\n";
foreach(DB::select("SELECT * FROM hpbrain_operational_records WHERE tenant_id=? AND dataset='lions-fees-data' LIMIT 2",[$T]) as $r) echo json_encode($r)."\n";
echo "-- loop counts\n";
foreach(['hpbrain_cases','hpbrain_recommendations','hpbrain_decisions','hpbrain_eso_executions','hpbrain_outcomes','hpbrain_learnings','hpbrain_risks','hpbrain_evidence','hpbrain_capabilities','hpbrain_departments','hpbrain_people','hpbrain_esos'] as $t){
 try{ echo "$t=".DB::table($t)->where('tenant_id',$T)->count()."\n"; }catch(\Throwable $e){ echo "$t=ERR ".substr($e->getMessage(),0,80)."\n"; }
}
echo "-- signal groups\n";
foreach(DB::select("SELECT rule_key, classification, severity, priority, status, COUNT(*) c FROM hpbrain_signals WHERE tenant_id=? GROUP BY rule_key,classification,severity,priority,status LIMIT 20",[$T]) as $r) echo json_encode($r)."\n";
echo "-- signal sample\n";
foreach(DB::select("SELECT * FROM hpbrain_signals WHERE tenant_id=? LIMIT 2",[$T]) as $r) echo json_encode($r)."\n";
