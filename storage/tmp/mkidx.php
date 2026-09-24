<?php
require "c:/Users/omshivay/Desktop/ADK/hp-enterprise-brain/vendor/autoload.php";
$app = require_once "c:/Users/omshivay/Desktop/ADK/hp-enterprise-brain/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
$t=microtime(true);
DB::statement('CREATE INDEX idx_oprec_tenant_updated ON hpbrain_operational_records (tenant_id, updated_date)');
echo "index created in ".round(microtime(true)-$t,1)."s\n";
