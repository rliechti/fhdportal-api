<?php
use App\Kernel;
if (file_exists(dirname(__DIR__).'/vendor/autoload_runtime.php')){
    require_once dirname(__DIR__).'/vendor/autoload_runtime.php';    
}
elseif (file_exists(dirname(__DIR__).'/vendor/autoload.php')){
    require_once dirname(__DIR__).'/vendor/autoload.php';    
}
else{
    error_log("Cannot load composer autoload");
}
return function (array $context) {
    return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
};
