<?php
require "c:/Users/omshivay/Desktop/ADK/hp-enterprise-brain/vendor/autoload.php";
$app = require_once "c:/Users/omshivay/Desktop/ADK/hp-enterprise-brain/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
echo "-- datasets\n";
foreach(DB::select("SELECT dataset, COUNT(*) c, MIN(occurred_at) mn, MAX(occurred_at) mx FROM hpbrain_operational_records WHERE tenant_id='8' GROUP BY dataset") as $r) echo json_encode($r)."\n";
echo "-- sample payloads\n";
foreach(DB::select("SELECT id, dataset, subject_ref, occurred_at, payload FROM hpbrain_operational_records WHERE tenant_id='8' LIMIT 3") as $r) echo json_encode($r)."\n";
echo "-- signal rules\n";
foreach(DB::select("SELECT rule_key, classification, severity, COUNT(*) c FROM hpbrain_signals WHERE tenant_id='8' GROUP BY rule_key, classification, severity LIMIT 20") as $r) echo json_encode($r)."\n";
echo "-- signal sample\n";
foreach(DB::select("SELECT id, source, metadata, related_entity_type, related_entity_id, created_date FROM hpbrain_signals WHERE tenant_id='8' LIMIT 2") as $r) echo json_encode($r)."\n";
echo "-- loop counts\n";
foreach(['hpbrain_cases','hpbrain_recommendations','hpbrain_decisions','hpbrain_eso_executions','hpbrain_outcomes','hpbrain_learnings','hpbrain_risks','hpbrain_evidence','hpbrain_capabilities','hpbrain_departments','hpbrain_people'] as $t){
 try{ echo "$t=".DB::table($t)->where('tenant_id','8')->count()."\n"; }catch(\Throwable $e){ echo "$t=ERR ".substr($e->getMessage(),0,60)."\n"; }
}
