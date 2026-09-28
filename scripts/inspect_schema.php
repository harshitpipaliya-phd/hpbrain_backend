<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$tables = [
    'hpbrain_signals',
    'hpbrain_evidence',
    'hpbrain_cases',
    'hpbrain_decisions',
    'hpbrain_eso_definitions',
    'hpbrain_eso_executions',
    'hpbrain_outcomes',
    'hpbrain_learnings',
];

foreach ($tables as $t) {
    echo "=== $t ===" . PHP_EOL;
    $cols = DB::select("SHOW COLUMNS FROM $t");
    foreach ($cols as $c) {
        echo "{$c->Field}: {$c->Type} (" . ($c->Null === 'YES' ? 'NULL' : 'NOT NULL') . ")" . PHP_EOL;
    }
}
