<?php

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

foreach (['development_erp', 'hp_erp'] as $db) {
    $tables = ['school_setup', 'institute_detail', 'org_details'];
    echo "database={$db}".PHP_EOL;
    foreach ($tables as $table) {
        $cols = DB::table('information_schema.columns')
            ->where('table_schema', $db)
            ->where('table_name', $table)
            ->orderBy('ordinal_position')
            ->pluck('column_name')
            ->all();
        echo $table.'='.json_encode($cols).PHP_EOL;
    }
}
