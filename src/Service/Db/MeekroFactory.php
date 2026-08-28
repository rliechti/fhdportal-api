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
        string $encoding = 'utf8mb4',
        int $port = 5432,
        string $sslMode = 'prefer',
        ?string $sslRootCert = null
    ): MeekroDB {
        // sslmode=prefer (libpq's default when unset) silently falls back to plaintext
        // if the server doesn't require TLS, and never validates the server certificate
        // either way. verify-full is the mode that actually guarantees both (security
        // audit M-5) - it also requires hostssl to be enforced server-side in pg_hba.conf,
        // since that is the control that actually prevents a plaintext connection.
        $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s;sslmode=%s', $host, $port, $database, $sslMode);
        if ($sslRootCert) {
            $dsn .= ';sslrootcert=' . $sslRootCert;
        }
        $db = new MeekroDB($dsn, $user, $password);
        $db->connect_options = [\PDO::ATTR_PERSISTENT => true];
        $db->encoding = $encoding;

        $fn = function ($hash) {
            $query = $hash['query'];
            $params = $hash['params']; // array of params in the query
            $runtime = $hash['runtime']; // runtime in ms
            $func_name = $hash['func_name'];
            $error = $hash['error']; // error message
            $Exception = $hash['exception']; // this exception will be thrown after hooks run
            throw new \RuntimeException(sprintf(
                'MeekroDB error: %s',
                $error ?? 'unknown'
            ));


            // error_log("QUERY: $query ($runtime ms)");
            // error_log("ERROR: " . $error);
            return;
        };

        $db->addHook('run_failed', $fn);
        return $db;
    }
}
