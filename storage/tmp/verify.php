<?php
require "c:/Users/omshivay/Desktop/ADK/hp-enterprise-brain/vendor/autoload.php";
$app = require_once "c:/Users/omshivay/Desktop/ADK/hp-enterprise-brain/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
$T='1000010';
foreach(['hpbrain_cases','hpbrain_hypotheses','hpbrain_reasoning_steps','hpbrain_case_evidence','hpbrain_recommendations','hpbrain_decisions','hpbrain_eso_definitions','hpbrain_eso_executions','hpbrain_outcomes','hpbrain_learnings','hpbrain_risks'] as $t)
  echo str_pad($t,32).DB::table($t)->where('tenant_id',$T)->count()."\n";
echo "--- decisions ---\n";
foreach(DB::table('hpbrain_decisions')->where('tenant_id',$T)->get(['status','confidence']) as $r) echo json_encode($r)."\n";
echo "--- cases ---\n";
foreach(DB::table('hpbrain_cases')->where('tenant_id',$T)->get(['title','status']) as $r) echo substr($r->title,0,95)." [".$r->status."]\n";
