<?php
require "c:/Users/omshivay/Desktop/ADK/hp-enterprise-brain/vendor/autoload.php";
$app = require_once "c:/Users/omshivay/Desktop/ADK/hp-enterprise-brain/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use App\Domain\Intelligence\IntelligenceEngine;
$me = DB::selectOne("SELECT CONNECTION_ID() id")->id;
file_put_contents("c:/Users/omshivay/Desktop/ADK/hp-enterprise-brain/storage/tmp/warm.pid", $me);
echo "warming as connection {$me}\n";
$s=microtime(true);
app(IntelligenceEngine::class)->forOrganization('1000010');
printf("LIONS COLD COMPUTE: %.1f s\n", microtime(true)-$s);
$s=microtime(true);
app(IntelligenceEngine::class)->forOrganization('1000010');
printf("LIONS WARM READ: %.0f ms\n", (microtime(true)-$s)*1000);
