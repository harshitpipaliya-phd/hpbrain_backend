<?php
require "c:/Users/omshivay/Desktop/ADK/hp-enterprise-brain/vendor/autoload.php";
$app = require_once "c:/Users/omshivay/Desktop/ADK/hp-enterprise-brain/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
foreach(DB::select("SELECT ID,USER,TIME,STATE,LEFT(REPLACE(REPLACE(INFO,'\n',' '),'  ',''),90) q FROM information_schema.PROCESSLIST WHERE INFO LIKE '%operational_records%' AND TIME > 60 ORDER BY TIME DESC") as $r) echo json_encode($r)."\n";
