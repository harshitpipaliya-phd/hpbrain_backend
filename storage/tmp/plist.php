<?php
require "c:/Users/omshivay/Desktop/ADK/hp-enterprise-brain/vendor/autoload.php";
$app = require_once "c:/Users/omshivay/Desktop/ADK/hp-enterprise-brain/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
foreach(DB::select("SELECT ID,TIME,STATE,LEFT(INFO,120) q FROM information_schema.PROCESSLIST WHERE INFO IS NOT NULL") as $r) echo json_encode($r)."\n";
