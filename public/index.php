<?php
use App\Kernel;
use Symfony\Component\Dotenv\Dotenv;

if (file_exists(dirname(__DIR__).'/vendor/autoload_runtime.php')){
    require_once dirname(__DIR__).'/vendor/autoload_runtime.php';    
}
elseif (file_exists(dirname(__DIR__).'/vendor/autoload.php')){
    require_once dirname(__DIR__).'/vendor/autoload.php';    
}
else{
    error_log("Cannot load composer autoload");
}
$POSTGRES_HOST = $_SERVER['POSTGRES_HOST'] ?? '';
$POSTGRES_DB = $_SERVER['POSTGRES_DB'] ?? '';
$POSTGRES_USER = $_SERVER['POSTGRES_USER'] ?? '';
$POSTGRES_PASSWORD = $_SERVER['POSTGRES_PASSWORD'] ?? '';
$dotenv = new Dotenv();
if (file_exists(__DIR__.'/.env')){
    $dotenv->loadEnv(__DIR__.'/.env', overrideExistingVars: true);    
    if (!$POSTGRES_HOST && isset($_ENV['POSTGRES_HOST'])) $POSTGRES_HOST = $_ENV['POSTGRES_HOST'];
    if (!$POSTGRES_DB && isset($_ENV['POSTGRES_DB'])) $POSTGRES_DB = $_ENV['POSTGRES_DB'];
    if (!$POSTGRES_USER && isset($_ENV['POSTGRES_USER'])) $POSTGRES_USER = $_ENV['POSTGRES_USER'];
    if (!$POSTGRES_PASSWORD && isset($_ENV['POSTGRES_PASSWORD'])) $POSTGRES_PASSWORD = $_ENV['POSTGRES_PASSWORD'];
}



DB::$dsn = 'pgsql:host='.$POSTGRES_HOST.';port=5432;dbname='.$POSTGRES_DB;
DB::$user = $POSTGRES_USER;
DB::$password = $POSTGRES_PASSWORD;

// DB::$dsn = 'pgsql:host=localhost;port=5432;dbname=meekrodb';
// DB::$user = 'my_database_user';
// DB::$password = 'my_database_password';
return function (array $context) {
    return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
};
