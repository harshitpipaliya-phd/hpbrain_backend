<?php
require "c:/Users/omshivay/Desktop/ADK/hp-enterprise-brain".'/vendor/autoload.php';
$app = require_once "c:/Users/omshivay/Desktop/ADK/hp-enterprise-brain".'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
$tables = ['hpbrain_cases','hpbrain_recommendations','hpbrain_decisions','hpbrain_eso_executions','hpbrain_outcomes','hpbrain_learnings','hpbrain_risks','hpbrain_signals','hpbrain_evidence','hpbrain_knowledge_library'];
$rows = DB::select("SELECT TABLE_NAME t, COLUMN_NAME c, COLUMN_TYPE ty, IS_NULLABLE n, COLUMN_DEFAULT d FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('".implode("','",$tables)."') ORDER BY TABLE_NAME, ORDINAL_POSITION");
$out=[];
foreach($rows as $r){ $out[$r->t][] = $r->c.' '.$r->ty.($r->n==='NO'?' NOTNULL':'').($r->d!==null?' DEF='.$r->d:''); }
foreach($out as $t=>$cols){ echo "== $t\n  ".implode("\n  ",$cols)."\n"; }
