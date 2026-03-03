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
    public function createRelationship(string $domain_type_name, string $range_type_name, string $domain_id, string $range_id, int $userId, bool $verbose = false): string
    {
		$existing_relation='';
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
            $existing_relation = $this->db->queryFirstRow(
                "SELECT id, status_type_id from relationship where predicate_id = %i and relationship_rule_id = %i and range_resource_id = %s and domain_resource_id = %s",
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
					'status_type_id'=>'DRA',
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
				$existing_relation = array('id'=>$relation['id']); 
				$log_uuid = Uuid::uuid4();
				$relation_log = [
					'id' => $log_uuid->toString(),
					'relationship_id'=>$uuid->toString(),
					'user_id'=>$userId,
					'action_type_id'=>'CRE'
				];
				$this->db->insert("relationship_log", $relation_log);
            }
            else if ($existing_relation['status_type_id'] === 'DEL'){
                $this->db->update("relationship", array("status_type_id" => 'DRA'), "id = %s", $existing_relation['id']);
        		$log_uuid = Uuid::uuid4();
        		$relation_log = [
        			'id' => $log_uuid->toString(),
        			'relationship_id'=>$existing_relation['id'],
        			'user_id'=>$userId,
        			'action_type_id'=>'MOD'
        		];
        		$this->db->insert("relationship_log", $relation_log);
                
            }
        }
		return $existing_relation['id'];
    }

    /**
     * Delete relationship between two resources 
     */
    public function deleteRelationship(string $domain_id, string $range_id, int $userId): void
    {
        $relationshipId = $this->db->queryFirstField(
            "SELECT id from relationship where domain_resource_id = %s and range_resource_id = %s",
            $domain_id,
            $range_id
        );

        if ($relationshipId) {
            // $this->db->delete("relationship", "id = %s", $relationshipId);
	        $this->db->update("relationship", array("status_type_id" => 'DEL'), "id = %s", $relationshipId);
			$log_uuid = Uuid::uuid4();
			$relation_log = [
				'id' => $log_uuid->toString(),
				'relationship_id'=>$relationshipId,
				'user_id'=>$userId,
				'action_type_id'=>'DEL'
			];
			$this->db->insert("relationship_log", $relation_log);
        }
    }

    /**
     * Update relationship activity 
     */
    public function updateRelationshipStatus(string $domain_id, string $range_id, bool $isActive, int $userId): void
    {
        $relationshipId = $this->db->queryFirstField(
            "SELECT id from relationship where domain_resource_id = %s and range_resource_id = %s",
            $domain_id,
            $range_id
        );

        if (!$relationshipId) {
			$domain = $this->db->queryFirstField("SELECT resource_type from resource_view where id = %s",$domain_id);
			$range = $this->db->queryFirstField("SELECT resource_type from resource_view where id = %s",$range_id);
			if(!$domain){ throw new Exception("Error: resource not found : $domain_id", 500); }
			if(!$range){ throw new Exception("Error: resource not found : $range_id", 500); }
            throw new Exception("Error: this $domain was not linked to this $range", 500);
        }

        $this->db->update("relationship", array("is_active" => $isActive), "id = %s", $relationshipId);
		$log_uuid = Uuid::uuid4();
		$relation_log = [
			'id' => $log_uuid->toString(),
			'relationship_id'=>$relationshipId,
			'user_id'=>$userId,
			'action_type_id'=>'MOD'
		];
		$this->db->insert("relationship_log", $relation_log);
    }

    public function getStudyIdFromResource(string $resourceId): ?string
    {
        return $this->db->queryFirstField("SELECT range_resource_id as study_id from relationship_view where domain_resource_id = %s and range_type = 'Study'", $resourceId);
    }
}