<?php
require "c:/Users/omshivay/Desktop/ADK/hp-enterprise-brain/vendor/autoload.php";
$app = require_once "c:/Users/omshivay/Desktop/ADK/hp-enterprise-brain/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
foreach(['hpbrain_signals','hpbrain_evidence','hpbrain_cases','hpbrain_decisions','hpbrain_recommendations','hpbrain_eso_executions','hpbrain_risks','hpbrain_outcomes'] as $t){
  $cols=[];
  foreach(DB::select("SHOW INDEX FROM `$t`") as $r) $cols[$r->Key_name][]=$r->Column_name;
  echo $t.":\n";
  foreach($cols as $k=>$c) echo "   $k(".implode(',',$c).")\n";
}
