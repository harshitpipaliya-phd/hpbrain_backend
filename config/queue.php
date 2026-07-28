<?php
declare(strict_types=1);
return ["default" => env("QUEUE_CONNECTION", "database"), "connections" => ["sync" => ["driver" => "sync"], "database" => ["driver" => "database", "table" => "jobs", "queue" => "default", "retry_after" => 90]]];
