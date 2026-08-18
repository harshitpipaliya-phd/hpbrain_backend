<?php
require "c:/Users/omshivay/Desktop/ADK/hp-enterprise-brain/vendor/autoload.php";
$app = require_once "c:/Users/omshivay/Desktop/ADK/hp-enterprise-brain/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Domain\Intelligence\IntelligenceEngine;
foreach (['1000010','1000000','7'] as $t) {
  $s=microtime(true);
  try { app(IntelligenceEngine::class)->forOrganization($t); printf("tenant %-8s cold: %6.1f s\n",$t,microtime(true)-$s); }
  catch (\Throwable $e) { printf("tenant %-8s ERR %s\n",$t,substr($e->getMessage(),0,90)); }
  $s=microtime(true);
  try { app(IntelligenceEngine::class)->forOrganization($t); printf("tenant %-8s warm: %6.0f ms\n",$t,(microtime(true)-$s)*1000); }
  catch (\Throwable $e) {}
}
