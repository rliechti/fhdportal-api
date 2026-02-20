<?php

namespace App\Service\Resource;

use App\Service\Auth\Keycloak;
use Exception;
use MeekroDB;
use Psr\Log\LoggerInterface;

/**
 * Service responsible for reading resources from the database.
 *
 * This final class uses an injected MeekroDB instance for database interactions.
 */
class ResourceReadService
{
    private MeekroDB $db;
    private LoggerInterface $logger;
    public function __construct(MeekroDB $db, LoggerInterface $logger)
    {
        $this->db = $db;
        $this->logger = $logger;
    }

    /**
     * Get list of valid resource types managed in the system.
     *
     * @return array List of resource type names as strings.
     */
    public function listResourceTypes(): array
    {
        return [
            'Study',
            'Sample',
            'MolecularExperiment',
            'MolecularRun',
            'MolecularAnalysis',
            'Dataset',
        ];
    }
    /**
     * Lists resources accessible to the authenticated user based on permissions, parent relations, and status filters.
     *
     * @param Keycloak $auth Authentication service object to check user roles and details.
     * @param string $resource_type The type of resource to list (e.g., 'Study', 'Sample').
     * @param string|null $parent_id Optional parent resource ID to restrict results to children of this resource.
     * @param string $permission Required permission string (e.g., 'read', 'write').
     * @param string|null $status Optional comma-separated string of desired statuses to filter resources by.
     *
     * @return array An array of resource data arrays including access and properties.
     *
     * @throws Exception Throws "Unauthorized" if user is a guest.
     */
    public function listResources(Keycloak $auth, string $resource_type, ?string $parent_id, string $permission, ?string $status = null, ?string $singlePublicId = null): array
    {
        // Parse the status string into an array if provided
        $status = $status ? explode(',', $status) : null;

        // Check authorization; guests are not allowed
        if ($auth->isGuest()) {
            throw new Exception('Unauthorized', 401);
        }

        // Retrieve user details for ID and permissions
        $user = $auth->getDetails();

        $join = '';
        $params = [
            'resource_type' => $resource_type,
            'user_id' => $user['id'],
            'permission' => $permission,
        ];

        // Base where clause with placeholders for querying resource_user_view
        $where = "WHERE resource_user_view.resource_type_name LIKE %ss_resource_type 
	              AND resource_user_view.user_id = %i_user_id 
	              AND resource_user_view.permissions LIKE %ss_permission 
	              AND resource_user_view.status_type_id <> 'DEL'";

        // If user has special DAC CLI role, override where clause to empty (full access?)
        if ($auth->isDacCli()) {
            $where = '';
        }

        // If parent_id is provided, join the relationship view to restrict resources accordingly
        if ($parent_id) {
            $join = "INNER JOIN relationship_view AS relationship 
	                 ON relationship.domain_resource_id = resource_user_view.resource_id ";
            $where .= " AND relationship.range_public_id = %s_parent_id";
            $params['parent_id'] = $parent_id;
        }

        // Query to get resource IDs matching the above criteria
        // $this->db->logfile = '/usr/local/log/sql.log';
        // $this->logger->debug('{query} and {params}', [
        //     'query' => "SELECT resource_id FROM resource_user_view $join $where",
        //     'params' => $params
        // ]);
        $resource_ids = $this->db->queryFirstColumn("SELECT resource_id FROM resource_user_view $join $where", $params);

        $this->db->logfile = '';
        // If permission is 'read' and no parent restriction, add published public resources
        if ($permission === 'read' && !$parent_id) {
            $where = $singlePublicId ? " AND properties->>'public_id'::text = %s_public_id " : "";
            $pub_params = ['resource_type' => $resource_type];
            if ($singlePublicId) {
                $pub_params['public_id'] = $singlePublicId;
            }
            $public_ids = $this->db->queryFirstColumn(
                "SELECT id FROM resource_view WHERE resource_view.resource_type LIKE %ss_resource_type AND resource_view.status_type_id='PUB' " . $where,
                $pub_params
            );
            foreach ($public_ids as $public_id) {
                $resource_ids[] = $public_id;
            }
        }

        // If no resources found, return empty list early
        if (!$resource_ids) {
            return [];
        }

        // Determine the database view to query based on resource type
        $db_tables = $this->db->tableList();
        $db_view = strtolower($resource_type) . '_view';
        if (!in_array($db_view, $db_tables)) {
            $db_view = 'resource'; // fallback to generic resource table/view
        }
        $params = [
            'status' => $status,
            'ids' => $resource_ids,
        ];

        // Compose the where clause for filtering by status or ownership
        $where = '';
        if ($status && is_array($status) && count($status) > 0) {
            if ($status[0] === 'own') {
                $params['status'] = $user['id'];
                $where = ' AND creator_id IN %ls_status';
            }
            else {
                $where = ' AND status IN %ls_status';
            }
        }
        // Non-DAC CLI users should exclude deleted resources
        if (!$auth->isDacCli()) {
            $where .= " AND status_type_id <> 'DEL' ";
        }

        if ($singlePublicId) {
            $where = " AND properties->>'public_id'::text = %s_public_id ";
            $params['public_id'] = $singlePublicId;
        }

        // Fetch the full resource records for the filtered IDs and status
        $resources = $this->db->query("SELECT * FROM $db_view WHERE id IN %ls_ids $where", $params);

        // Enrich resources with access info, decoded properties, and current user permission
        if (is_array($resources)) {
            $resources = array_map(function ($resource) use ($user) {
                $resource['access'] = $this->db->query(
                    "SELECT username, permissions, user_id, role, role_id FROM resource_user_view WHERE resource_id = %s",
                    $resource['id']
                );
                $resource['properties'] = json_decode($resource['properties'], true);
                $resource['current_permission'] = $this->db->queryFirstField(
                    "SELECT permissions FROM resource_user_view WHERE resource_id = %s AND user_id = %i",
                    $resource['id'],
                    $user['id']
                );
                return $resource;
            }, $resources);
        }

        return $resources;
    }
    /**
     * Retrieves detailed information about a specific resource, including access rights and relations.
     *
     * @param Keycloak $auth The authentication service, provides user details and permissions context.
     * @param string $resource_type The type of resource (e.g., 'Study', 'Sample').
     * @param string $resource_id The unique identifier or public ID of the resource.
     * @param string $permission The required permission level ('read', 'write', etc.).
     * @param string|null $status Optional status filter(s), comma-separated (e.g., "PUB,APPROVED").
     *
     * @return array The resource details, access info, owner info, and relation types.
     *
     * @throws Exception When user is unauthorized or resource is unknown.
     */
    public function getResource(
        Keycloak $auth,
        string $resource_type,
        string $resource_id,
        string $permission,
        ?string $status = null
        ): array
    {
        $status = ($status) ? explode(',', $status) : null;
        // Check if user is authenticated and has role
        if ($auth->isGuest()) {
            throw new Exception("Unauthorized", 401);
        }
        $user = $auth->getDetails();
        // Determine if resource identifier is public_id or id based on resource prefix
        $resource_prefix = $this->db->queryFirstField("SELECT public_id_prefix FROM resource_type WHERE name = %s", $resource_type);
        if ($resource_prefix) {
            $field = (strtolower(substr($resource_id, 0, strlen($resource_prefix))) === strtolower($resource_prefix))
                ? "public_id"
                : "id";
        }
        else {
            $field = "id";
        }
        // Check if resource view exists, default fallback
        $db_tables = $this->db->tableList();
        $db_view = strtolower($resource_type) . "_view";
        if (!in_array($db_view, $db_tables)) {
            $db_view = 'resource';
        }

        // Collect query parameters
        $params = ['resource_id' => $resource_id];

        // Build where clause based on status
        $where = '';
        if ($status && is_array($status) && count($status)) {
            if ($status[0] === 'own') {
                $params['status'] = $user['id'];
                $where = ' AND creator_id IN %ls_status';
            }
            else {
                $where = ' AND status IN %ls_status';
                $params['status'] = $status;
            }
        }

        // Fetch the resource info
        $resource = $this->db->queryFirstRow("SELECT * from " . $db_view . " where " . $field . " = %s_resource_id and status_type_id <> 'DEL' " . $where, $params);
        if (!$resource) {
            return ['error' => ['message' => 'Unknown resource', 'status' => 404]];
        }

        // Decode properties for further processing
        $resource['properties'] = json_decode($resource['properties'], true);

        // Initialize access and owner arrays
        $resource['access'] = [];
        $resource['owner'] = [];

        if (isset($user['id'])) {
            // Fetch user-level access info
            $resource['access'] = $this->db->queryFirstRow(
                "SELECT preferred_username, username, permissions, user_id, role, role_id, email, creator_sub FROM resource_user_view WHERE resource_id = %s AND user_id = %i",
                $resource['id'],
                $user['id']
            );
            // Record current permission
            $resource['current_permission'] = $this->db->queryFirstField(
                "SELECT permissions FROM resource_user_view WHERE resource_id = %s AND user_id = %i",
                $resource['id'],
                $user['id']
            );
        }

        // If resource is public or user is DAC CLI, give read access
        if ($resource['status_type_id'] === 'PUB' || $auth->isDacCli()) {
            $resource['access'] = ['permissions' => 'read'];
        }

        // Check user permissions to adjust access rights
        $auth_permissions = ($resource['access']) ? explode(',', $resource['access']['permissions']) : [];
        if (in_array('edit', $auth_permissions) || $auth->isDacCli()) {
            // Fetch full access details to all users
            $resource['access'] = $this->db->query(
                "SELECT preferred_username, username, permissions, user_id, role, role_id, email, creator_sub FROM resource_user_view WHERE resource_id = %s",
                $resource['id']
            );

            // Determine owner details
            foreach ($resource['access'] as $u) {
                if ($u['role_id'] === 'OWN') {
                    $resource['owner'] = [
                        'username' => $u['preferred_username'],
                        'name' => $u['username'],
                        'email' => $u['email'],
                        'sub' => $u['creator_sub']
                    ];
                }
            }
        }

        // If user lacks read permission, deny
        if (!in_array('read', $auth_permissions)) {
            return ['error' => ['message' => 'Unauthorized', 'status' => 401]];
        }

        // Fetch relation types for the resource
        $resource['relationTypes'] = $this->db->query(
            "SELECT domain_type_name AS label, domain_type_id AS resource_type_id FROM relationship_rule_view WHERE range_type_name = %s",
            $resource_type
        );

        return $resource;
    }
}