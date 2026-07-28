<?php
declare(strict_types=1);
return ["default" => env("CACHE_STORE", "database"), "stores" => ["database" => ["driver" => "database", "table" => "cache"], "file" => ["driver" => "file", "path" => storage_path("framework/cache/data")]]];
