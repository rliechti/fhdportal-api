<?php

namespace App\Service;

use App\Service\Auth\Keycloak;
use App\Service\Auth\KeycloakService;
// use App\Service\RabbitMq\RabbitMqInterface;
use App\Service\Dac\PolicyService;
use App\Service\Resource\ResourceRelationshipService;
use Ramsey\Uuid\Uuid;
use MeekroDB;

class SubmissionService
{
    private ResourceRelationshipService $relationshipService;
    public function __construct(
        private MeekroDB $db,
        // private RabbitMqInterface $rabbitMq,
        private PolicyService $policy,
        private KeycloakService $keycloak,
	    ResourceRelationshipService $relationshipService,
    ) {
	    $this->relationshipService = $relationshipService;
    }

    public function handleSubmission(Keycloak $auth, string $studyId, string $field, array $user): array
    {
        $sdafiles = $this->db->query(
            "SELECT sdafile_public_id, dataset_public_id, study_public_id
             FROM sdafile_study_dataset_view
             WHERE {$field} = %s AND dataset_public_id IS NOT NULL",
            $studyId
        );

        // if ($sdafiles && isset($_ENV['MQ_HOST'])) {
        //     $this->rabbitMq->mapSDAfiles($sdafiles);
        // }

        $datasets = $this->db->query(
            "SELECT id AS dataset_id, properties->>'policy_id' AS policy_id, study_id
             FROM dataset_view
             WHERE $field = %s",
            $studyId
        );

        foreach ($datasets as $d) {
            if (!$d['policy_id']) {
                continue;
            }

            $result = $this->policy->registerDatasetPolicy($auth, $d['dataset_id'], $d['policy_id']);

            if (!$result['success']) {
                return $result;
            }

            $policy = $this->policy->getPolicy($auth, $d['policy_id'], true);
            $this->syncDacMembersAcl($policy, $d['study_id'], $grant = true);
        }

        return ['success' => true];
    }

    public function handleUnsubmission(Keycloak $auth, string $studyId, string $field, array $user): void
    {
        $datasets = $this->db->query(
            "SELECT id AS dataset_id, properties->>'policy_id' AS policy_id, study_id
             FROM dataset_view
             WHERE $field = %s",
            $studyId
        );

        foreach ($datasets as $d) {
            if (!$d['policy_id']) {
                continue;
            }

            $this->db->query(
                "UPDATE resource
                 SET properties = jsonb_set(properties, '{policy_id}', '\"\"')
                 WHERE id = %s",
                $d['dataset_id']
            );

            $properties = $this->db->queryFirstField(
                'SELECT properties FROM resource WHERE id = %s',
                $d['dataset_id']
            );

            $logId = Uuid::uuid4()->toString();

            $this->db->insert('resource_log', [
                'id'             => $logId,
                'resource_id'    => $d['dataset_id'],
                'user_id'        => $user['id'],
                'action_type_id' => 'DEL',
                'properties'     => $properties,
            ]);


			$this->relationshipService->updateRelationshipStatus($d['dataset_id'],$d['policy_id'],false,$user['id']);
            // $relationshipId = $this->db->queryFirstField(
 //                "SELECT id
 //                 FROM relationship
 //                 WHERE domain_resource_id = %s_dataset_id
 //                   AND range_resource_id = %s_policy_id",
 //                $d
 //            );
 //
 //            if ($relationshipId) {
 //                $this->db->update('relationship', ['is_active' => false], 'id = %s', $relationshipId);
 //
 //                $relLogId = Uuid::uuid4()->toString();
 //
 //                $this->db->insert('relationship_log', [
 //                    'id'              => $relLogId,
 //                    'relationship_id' => $relationshipId,
 //                    'user_id'         => $user['id'],
 //                    'action_type_id'  => 'DEL',
 //                ]);
 //            }

            $policy = $this->policy->getPolicy($auth, $d['policy_id'], true);
            $this->syncDacMembersAcl($policy, $d['study_id'], $grant = false);
        }
    }

    private function syncDacMembersAcl(array $policy, string $studyId, bool $grant): void
    {
        if (!isset($policy['dac']['members'])) {
            return;
        }

        foreach ($policy['dac']['members'] as $member) {
            if (empty($member['email'])) {
                continue;
            }

            $users = $this->keycloak->getUsers('', 'email=' . $member['email']);

            foreach ($users as $u) {
                $userId = $this->db->queryFirstField(
                    'SELECT id FROM "user" WHERE external_id = %s',
                    $u['username']
                );

                if ($grant) {
                    if (!$userId) {
                        $this->db->insert('user', [
                            'email'       => $member['email'],
                            'external_id' => $u['username'],
                        ]);
                        $userId = $this->db->insertId();
                    }

                    $access = $this->db->queryFirstRow(
                        'SELECT * FROM resource_acl WHERE resource_id = %s AND user_id = %i',
                        $studyId,
                        $userId
                    );

                    if (!$access) {
                        $this->db->insert('resource_acl', [
                            'user_id'     => $userId,
                            'resource_id' => $studyId,
                            'role_id'     => 'COM',
                        ]);
                    } elseif (!\in_array($access['role_id'], ['COM', 'OWN'], true)) {
                        $this->db->update(
                            'resource_acl',
                            ['role_id' => 'COM'],
                            'resource_id = %s AND user_id = %i',
                            $studyId,
                            $userId
                        );
                    }
                } elseif ($userId) {
                    $this->db->delete(
                        'resource_acl',
                        'user_id = %i AND resource_id = %s AND role_id = \'COM\'',
                        $userId,
                        $studyId
                    );
                }
            }
        }
    }
}
