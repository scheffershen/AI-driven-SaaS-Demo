<?php
require dirname(__DIR__).'/vendor/autoload.php';

// Resolve storage at runtime so cached containers cannot reuse demo data.
$testDataDir = sys_get_temp_dir().'/video-functional-'.bin2hex(random_bytes(8));
mkdir($testDataDir.'/sessions', 0700, true);
putenv('APP_DATA_DIR='.$testDataDir);
$_ENV['APP_DATA_DIR'] = $_SERVER['APP_DATA_DIR'] = $testDataDir;
