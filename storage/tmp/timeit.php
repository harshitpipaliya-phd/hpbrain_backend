<?php
require "c:/Users/omshivay/Desktop/ADK/hp-enterprise-brain/vendor/autoload.php";
$app = require_once "c:/Users/omshivay/Desktop/ADK/hp-enterprise-brain/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
foreach (['1000010','1000000','7'] as $t) {
  $s=microtime(true);
  $r=DB::table('hpbrain_operational_records')->where('tenant_id',$t)
      ->selectRaw('COUNT(*) AS n, MAX(updated_date) AS t')->first();
  printf("tenant %-8s fingerprint: %6.0f ms  (%s rows)\n", $t, (microtime(true)-$s)*1000, number_format($r->n));
}
