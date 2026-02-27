<?php

namespace App\Service\Resource;

use Exception;
use MeekroDB;
use Ramsey\Uuid\Uuid;

class ResourceRelationshipService
{
    private MeekroDB $db;

    public function __construct(MeekroDB $db)
    {
        $this->db = $db;
    }

    /**
     * Create relationship between two resources
     *
     * @param string $domain_type_name Domain resource type name
     * @param string $range_type_name Range resource type name
     * @param string $domain_id Domain resource ID
     * @param string $range_id Range resource ID
     * @param bool $verbose Whether to output debug information
     */
    public function createRelationship(string $domain_type_name, string $range_type_name, string $domain_id, string $range_id, bool $verbose = false): void
    {
        $relation_rule = $this->db->queryFirstRow(
            "SELECT id, predicate_id, default_is_active from relationship_rule_view where predicate_name = 'isPartOf' and lower(domain_type_name) = %s and lower(range_type_name) = %s",
            strtolower($domain_type_name),
            strtolower($range_type_name)
        );

        if (!$relation_rule) {
            $relation_rule = $this->db->queryFirstRow(
                "SELECT id, predicate_id, default_is_active from relationship_rule_view where predicate_name = 'isLinkedTo' and lower(domain_type_name) = %s and lower(range_type_name) = %s",
                strtolower($range_type_name),
                strtolower($domain_type_name)
            );
            if ($relation_rule) {
                $tmp_id = $domain_id;
                $domain_id = $range_id;
                $range_id = $tmp_id;
            }
        }

        if ($relation_rule) {
            $existing_relation = $this->db->queryFirstField(
                "SELECT id from relationship where predicate_id = %i and relationship_rule_id = %i and range_resource_id = %s and domain_resource_id = %s",
                $relation_rule['predicate_id'],
                $relation_rule['id'],
                $range_id,
                $domain_id
            );

            if (!$existing_relation) {
                if ($verbose) {
                    print("\tcreate relationship\t");
                }

                $uuid = Uuid::uuid4();
                $relation = [
                    'id' => $uuid->toString(),
                    'relationship_rule_id' => $relation_rule['id'],
                    'predicate_id' => $relation_rule['predicate_id'],
                    'domain_resource_id' => $domain_id,
                    'range_resource_id' => $range_id,
                    'is_active' => $relation_rule['default_is_active']
                ];

                $sequence_nb = $this->db->queryFirstField(
                    "SELECT max(sequence_number) from relationship where predicate_id = %i and relationship_rule_id = %i and range_resource_id = %s",
                    $relation_rule['id'],
                    $relation_rule['predicate_id'],
                    $range_id
                );

                if ($sequence_nb) {
                    $relation['sequence_number'] = $sequence_nb + 1;
                }

                $this->db->insert("relationship", $relation);
            }
        }
    }

    /**
     * Delete relationship between two resources
     */
    public function deleteRelationship(string $resourceId, string $policyId): void
    {
        $relationshipId = $this->db->queryFirstField(
            "SELECT id from relationship where domain_resource_id = %s and range_resource_id = %s",
            $resourceId,
            $policyId
        );

        if ($relationshipId) {
            $this->db->delete("relationship", "id = %s", $relationshipId);
        }
    }

    /**
     * Update relationship activity
     */
    public function updateRelationshipStatus(string $resourceId, string $policyId, bool $isActive): void
    {
        $relationshipId = $this->db->queryFirstField(
            "SELECT id from relationship where domain_resource_id = %s and range_resource_id = %s",
            $resourceId,
            $policyId
        );

        if (!$relationshipId) {
            throw new Exception('Error: this policy was not linked to this dataset', 500);
        }

        $this->db->update("relationship", array("is_active" => $isActive), "id = %s", $relationshipId);
    }

    public function getStudyIdFromResource(string $resourceId): ?string
    {
        return $this->db->queryFirstField("SELECT range_resource_id as study_id from relationship_view where domain_resource_id = %s and range_type = 'Study'", $resourceId);
    }
}