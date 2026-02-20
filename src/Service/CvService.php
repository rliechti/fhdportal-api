<?php

namespace App\Service;

use MeekroDB;

class CvService
{
    private MeekroDB $db;

    public function __construct(MeekroDB $db)
    {
        $this->db = $db;
    }

    public function getStatusTypes(): array
    {
        return $this->db->query("SELECT * from status_type");
    }
}
