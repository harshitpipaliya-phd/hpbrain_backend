<?php

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

use App\Domain\Organization\FoundationCounts;
use App\Domain\Universal\EntityResolver;
use Illuminate\Support\Facades\DB;

$resolver = app(EntityResolver::class);
$foundation = app(FoundationCounts::class);
$tenants = ['1', '47', '61', '67', '69', '254'];

echo 'database='.config('database.connections.mysql.database').PHP_EOL;

foreach ($tenants as $tenant) {
    try {
        $org = $resolver->resolve($tenant, 'Organization');
        $person = $resolver->resolve($tenant, 'Person');
        $row = DB::table($org->table)
            ->where($org->tenantKey, $tenant)
            ->where($org->primaryKey, $tenant)
            ->first();
        echo json_encode([
            'tenant' => $tenant,
            'org_table' => $org->table,
            'org_exists' => $row !== null,
            'org_name' => $row ? ($row->{$org->field('name')} ?? null) : null,
            'person_table' => $person->table,
            'foundation' => $foundation->forTenant($tenant),
            'brain_records' => DB::table('hpbrain_operational_records')->where('tenant_id', $tenant)->count(),
            'brain_students' => DB::table('hpbrain_students')->where('tenant_id', $tenant)->count(),
            'signals' => DB::table('hpbrain_signals')->where('tenant_id', $tenant)->count(),
        ], JSON_PRETTY_PRINT).PHP_EOL;
    } catch (Throwable $e) {
        echo json_encode(['tenant' => $tenant, 'error' => $e->getMessage()], JSON_PRETTY_PRINT).PHP_EOL;
    }
}

$tests = [
    ['email' => 'admin@lionserp.com', 'password' => 'Gaurav@1997'],
    ['email' => 'admin@fragnelo.com', 'password' => 'admin'],
    ['email' => 'skijain@hotmail.com', 'password' => 'admin'],
];

foreach ($tests as $test) {
    $request = Illuminate\Http\Request::create('/api/v1/auth/login', 'POST', [], [], [], [
        'HTTP_ACCEPT' => 'application/json',
        'CONTENT_TYPE' => 'application/json',
    ], json_encode($test));

    $response = $kernel->handle($request);
    $body = json_decode($response->getContent(), true);

    echo json_encode([
        'login' => $test['email'],
        'status' => $response->getStatusCode(),
        'organization' => $body['organization'] ?? null,
        'hasAccessToken' => isset($body['accessToken']),
    ], JSON_PRETTY_PRINT).PHP_EOL;

    $kernel->terminate($request, $response);
}
