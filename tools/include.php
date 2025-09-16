<?php
require __DIR__.'/vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->load();
$host = (php_sapi_name() === 'cli' && stripos(PHP_OS, "Linux") !== false) ? $_SERVER['POSTGRES_HOST'] : "0.0.0.0";
$port = (php_sapi_name() === 'cli' && stripos(PHP_OS, "Linux") !== false) ? 5432 : 5433;
// $port = 5432;
// $host =  $_SERVER['POSTGRES_HOST'];
DB::$dsn = 'pgsql:host='.$host.';port='.$port.';dbname='.$_SERVER['POSTGRES_DB'];

DB::$user = $_SERVER['POSTGRES_USER'];
DB::$password = $_SERVER['POSTGRES_PASSWORD'];

?>