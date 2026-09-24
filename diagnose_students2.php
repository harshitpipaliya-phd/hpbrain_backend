<?php
require __DIR__ . '/../hp-enterprise-brain/vendor/autoload.php';
$app = require_once __DIR__ . '/../hp-enterprise-brain/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== Sample rows for 1000010 ===\n";
$rows = DB::table('hpbrain_operational_records')
    ->where('tenant_id', '1000010')
    ->select('dataset')
    ->distinct()
    ->limit(20)
    ->get();
foreach ($rows as $r) {
    echo $r->dataset . "\n";
}

echo "\n=== Count per dataset (using index) ===\n";
$datasets = ['lions-result-data', 'lions-fee-data', 'school_fee', 'academic'];
foreach ($datasets as $ds) {
    $count = DB::table('hpbrain_operational_records')
        ->where('tenant_id', '1000010')
        ->where('dataset', $ds)
        ->count();
    echo $ds . ' => ' . $count . "\n";
}

echo "\n=== Total for 1000010 ===\n";
echo DB::table('hpbrain_operational_records')->where('tenant_id', '1000010')->count() . "\n";

echo "\n=== Sample student extraction ===\n";
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
