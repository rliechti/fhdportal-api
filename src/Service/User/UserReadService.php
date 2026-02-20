<?php

namespace App\Service\User;

use MeekroDB;

final class UserReadService
{
    private MeekroDB $db;
    public function __construct(MeekroDB $db)
    {
        $this->db = $db;
    }

    /**
     * Returns users; if email is provided, returns a single match as a 1-element array or empty array.
     */
    public function findUsers(?string $email = null): array
    {
        if ($email !== null) {
            $row = $this->db->queryFirstRow('SELECT * FROM "user" WHERE email = %s', $email);
            return $row ? [$row] : [];
        }

        return $this->db->query('SELECT * FROM "user"');
    }

    public function listRoles(): array
    {
        return $this->db->query('SELECT * FROM "role"');
    }
}
