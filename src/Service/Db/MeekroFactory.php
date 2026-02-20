<?php

namespace App\Service\Db;

use MeekroDB;

final class MeekroFactory
{
    public static function init(
        string $host,
        string $user,
        string $password,
        string $database,
        string $encoding = 'utf8mb4'
    ): MeekroDB {
        $dsn = 'pgsql:host=' . $host . ';port=5432;dbname=' . $database;
        $db = new MeekroDB($dsn, $user, $password);
        $db->encoding = $encoding;

        $fn = function ($hash) {
            $query = $hash['query'];
            $params = $hash['params']; // array of params in the query
            $runtime = $hash['runtime']; // runtime in ms
            $func_name = $hash['func_name'];
            $error = $hash['error']; // error message
            $Exception = $hash['exception']; // this exception will be thrown after hooks run
            throw new \RuntimeException(sprintf(
                'MeekroDB error: %s (query: %s)',
                $error ?? 'unknown',
                $query ?? 'n/a'
            ));


            error_log("QUERY: $query ($runtime ms)");
            error_log("ERROR: " . $error);
            return;
        };

        $db->addHook('run_failed', $fn);
        return $db;
    }
}
