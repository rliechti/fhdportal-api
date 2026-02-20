<?php

namespace App\Service\User;

use MeekroDB;

final class UserRoleReadService
{
    public function __construct(private readonly MeekroDB $db)
    {
    }

    /**
     * Returns users; if email is provided, returns a single match as a 1-element array or empty array.
     */
    public function listAll(): array
    {

        return $this->db->query('SELECT * FROM "role"');
    }
}
