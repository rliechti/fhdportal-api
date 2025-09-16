<?php
namespace App\Service;

use Ramsey\Uuid\Uuid;

class UuidChecker
{
    private $uuid;

    public function __construct(string $uuid)
    {
        $this->uuid = $uuid;
    }
    
    public function check()
    {
        return (is_string($this->uuid) && preg_match("/^[0-9A-F]{8}-[0-9A-F]{4}-4[0-9A-F]{3}-[89AB][0-9A-F]{3}-[0-9A-F]{12}$/i", $this->uuid));
    }
    
}
