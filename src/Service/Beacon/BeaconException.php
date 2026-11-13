<?php

namespace App\Service\Beacon;

/** A request that the Beacon cannot answer: the code is the HTTP status and the error code of the Beacon error response. */
class BeaconException extends \RuntimeException
{
    public function __construct(string $message, int $code = 400)
    {
        parent::__construct($message, $code);
    }
}
