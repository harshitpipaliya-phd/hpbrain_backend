<?php
require "c:/Users/omshivay/Desktop/ADK/hp-enterprise-brain/vendor/autoload.php";
$app = require_once "c:/Users/omshivay/Desktop/ADK/hp-enterprise-brain/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
echo "-- signals per tenant\n";
foreach(DB::select("SELECT tenant_id, COUNT(*) c FROM hpbrain_signals GROUP BY tenant_id") as $r) echo json_encode($r)."\n";
echo "-- oprec per tenant/dataset\n";
foreach(DB::select("SELECT tenant_id, dataset, COUNT(*) c FROM hpbrain_operational_records GROUP BY tenant_id, dataset") as $r) echo json_encode($r)."\n";
echo "-- institutes\n";
foreach(DB::select("SELECT sub_institute_id, organization_name, industry_type FROM institute_detail LIMIT 30") as $r) echo json_encode($r)."\n";
