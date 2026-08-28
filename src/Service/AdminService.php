<?php

namespace App\Service;
use Exception;
use MeekroDB;
use Psr\Log\LoggerInterface;
use App\Service\Auth\KeycloakService;
use App\Service\Auth\Keycloak;
use App\Service\User\UserRoleReadService;
use App\Service\Resource\ResourceReadService;
class AdminService
{
    protected KeycloakService $keycloak;
    protected UserRoleReadService $roleRead;
    protected ResourceReadService $resourceRead;
    protected MeekroDB $db;
    protected LoggerInterface $logger;
    public function __construct(MeekroDB $db, KeycloakService $keycloak, UserRoleReadService $roleRead, ResourceReadService $resourceRead, LoggerInterface $logger)
    {
        $this->db           = $db;
        $this->keycloak     = $keycloak;
        $this->roleRead     = $roleRead;
        $this->resourceRead = $resourceRead;
        $this->logger       = $logger;
    }

    public function getAllUsers(): array
    {
        $kcUsers = $this->keycloak->getUsers();
        $users = [];
        foreach ($kcUsers as $kcUser) {
            $users[] = $this->keycloak->getUser($kcUser['id']);
        }
        return $users;
    }

    public function getRoles(): array
    {
        return $this->roleRead->listAll();
    }

    public function setRoles(string $userId, array $roles): array
    {
        $this->keycloak->updateUserRoles($userId, $roles);
        $user = $this->keycloak->getUser($userId);
        return $user['roles'] ?? [];
    }

    public function getDatasets(Keycloak $auth): array
    {
        try {
            $datasets = $this->resourceRead->listResources($auth, 'Dataset', null, 'read', 'approved,published');
            $datasets = array_map(function($d)
                {
                    // list files waiting to be registered on SDA. If some files are in REG status, then, the dataset is not ready for release
                    $nbRegisteredFiles = $this->db->queryFirstField("SELECT count(sdafile_public_id) as nb FROM sdafile_study_dataset_view WHERE dataset_id=%s_id and status_type_id='REG'::text;",$d);
                    $status = $d['status'];
                    if (intval($nbRegisteredFiles) > 0){
                        $status = "processing on SDA";
                    }
                    $datasetReview = $this->db->queryFirstRow("
                        SELECT
                        	resource_log.action_time as validation_time,
                        	\"user\".properties->>'name' as validator
                        FROM
                        	resource_log
                        	inner join \"user\" on resource_log.user_id = \"user\".id
                        WHERE
                        	resource_log.resource_id = %s_id
                        	AND resource_log.action_type_id = 'PUB'
                        ORDER BY
                        	resource_log.action_time DESC
                        LIMIT
                        	1;
                    ",$d);
                    return [
                        "id"              => $d["id"],
                        "study_id"        => $d["study_id"],
                        "study_public_id" => $d["study_public_id"],
                        "study_title"     => $d["study_title"],
                        "public_id"       => $d["public_id"],
                        "status_type_id"  => $d["status_type_id"],
                        "title"           => $d["title"],
                        "status"          => $status,
                        "dataset_type"    => $d["dataset_type"],
                        "creation_date"   => $d["creation_date"],
                        "last_update"     => $d["last_update"],
                        "creator_id"      => $d["creator_id"],
                        "creator_name"    => $d["creator_name"],
                        "creator_email"   => $d["creator_email"],
                        "released_date"   => $d["released_date"],
                        "validator"       => $datasetReview["validator"] ?? "",
                        "validation_time" => $datasetReview["validation_time"] ?? ""
                    ];
                },
                $datasets
            );
            return [
                "status" => "success",
                "content" => $datasets,
                "exit_code" => 200
            ];
        } catch (Exception $e) {
            $this->logger->error('Failed to list admin datasets', ['exception' => $e]);
            return [
                "status" => "error",
                "message" => 'Unable to retrieve datasets',
                "exit_code" => 500
            ];
        }
    }

}
