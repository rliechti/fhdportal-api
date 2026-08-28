<?php

namespace App\Service\Resource;

use App\Service\Utility\GeneralHelperService;
use MeekroDB;

final class ResourceRelationService
{
    public function __construct(
        private MeekroDB $db,
        private GeneralHelperService $helper,
        )
    {
    }


    public function checkRelationship(string $domain_id, string $range_id): bool|string
    {
        // check that dataset and study have relations
        $domain_field = $this->helper->checkUuid($domain_id) ? "domain_resource_id" : "domain_public_id";
        $range_field = $this->helper->checkUuid($range_id) ? "range_resource_id" : "range_public_id";
        $relation_id = $this->db->queryFirstField("SELECT id from relationship_view where %b = %s and %b = %s", $domain_field, $domain_id, $range_field, $range_id);
        if (!$relation_id) {
            return false;
        }
        return $relation_id;
    }
}