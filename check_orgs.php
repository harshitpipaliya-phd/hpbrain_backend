<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$org = DB::table('hpbrain_organizations')->where('name', 'V1 Academy')->first();
if ($org) {
    echo "Found V1 Academy with tenant_id: " . $org->tenant_id . "\n";
} else {
    echo "V1 Academy not found.\n";
}

$all = DB::table('hpbrain_organizations')->get();
echo "All orgs:\n";
foreach ($all as $o) {
    echo "- " . $o->name . " (tenant: " . $o->tenant_id . ")\n";
}

