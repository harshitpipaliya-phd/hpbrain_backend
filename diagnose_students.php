<?php
require __DIR__ . '/../hp-enterprise-brain/vendor/autoload.php';
$app = require_once __DIR__ . '/../hp-enterprise-brain/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== Datasets for 1000010 (sampled) ===\n";
$datasets = DB::table('hpbrain_operational_records')
    ->where('tenant_id', '1000010')
    ->select('dataset', DB::raw('COUNT(*) as cnt'))
    ->groupBy('dataset')
    ->get();
foreach ($datasets as $d) {
    echo $d->dataset . ' => ' . $d->cnt . "\n";
}

echo "\n=== Sample fee record if exists ===\n";
$fee = DB::table('hpbrain_operational_records')
    ->where('tenant_id', '1000010')
    ->where('dataset', '!=', 'lions-result-data')
    ->limit(1)
    ->get();
if ($fee->isNotEmpty()) {
    echo json_encode((array) $fee->first()) . "\n";
} else {
    echo "No non-academic records found\n";
}

echo "\n=== Distinct academic students ===\n";
$students = DB::table('hpbrain_operational_records')
    ->where('tenant_id', '1000010')
    ->where('dataset', 'lions-result-data')
    ->whereNotNull('subject_ref')
    ->select('subject_ref', DB::raw('MAX(JSON_UNQUOTE(JSON_EXTRACT(payload, "$.student_name"))) as name'), DB::raw('MAX(JSON_UNQUOTE(JSON_EXTRACT(payload, "$.standard"))) as standard'))
    ->groupBy('subject_ref')
    ->limit(5)
    ->get();
foreach ($students as $s) {
    echo $s->subject_ref . ' => ' . $s->name . ' (' . $s->standard . ")\n";
}
