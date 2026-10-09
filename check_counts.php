<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$org = \Illuminate\Support\Facades\DB::table('hpbrain_tenants')->first();
if (!$org) { echo 'No tenants found.'; exit; }
$tenantId = $org->id;
echo "Tenant ID: $tenantId\n";

echo "Students: " . \Illuminate\Support\Facades\DB::table('hpbrain_students')->where('tenant_id', $tenantId)->count() . "\n";
echo "Records: " . \Illuminate\Support\Facades\DB::table('hpbrain_operational_records')->where('tenant_id', $tenantId)->count() . "\n";
echo "Signals: " . \Illuminate\Support\Facades\DB::table('hpbrain_signals')->where('tenant_id', $tenantId)->count() . "\n";
echo "Cases: " . \Illuminate\Support\Facades\DB::table('hpbrain_cases')->where('tenant_id', $tenantId)->count() . "\n";
echo "Recommendations: " . \Illuminate\Support\Facades\DB::table('hpbrain_recommendations')->where('tenant_id', $tenantId)->count() . "\n";

