<?php

namespace App\Service\Auth;

use App\Service\Dac\PolicyService;
use App\Service\Utility\GeneralHelperService;
use MeekroDB;
use ReallySimpleJWT\Token;

$KEYCLOAK_SECRET = $_SERVER['KEYCLOAK_SECRET'];
$KEYCLOAK_REALM = $_SERVER['KEYCLOAK_REALM'];
$KEYCLOAK_CLIENT_ID = $_SERVER['KEYCLOAK_CLIENT_ID'];
$KEYCLOAK_URL = rtrim($_SERVER['KEYCLOAK_URL'], '/') . "/";

if (!defined("KEYCLOAK_URL")) {
    define("KEYCLOAK_URL", $KEYCLOAK_URL);
}
if (!defined("KEYCLOAK_REALM")) {
    define("KEYCLOAK_REALM", $KEYCLOAK_REALM);
}
if (!defined("KEYCLOAK_SECRET")) {
    define("KEYCLOAK_SECRET", $KEYCLOAK_SECRET);
}
if (!defined("KEYCLOAK_CLIENT_ID")) {
    define("KEYCLOAK_CLIENT_ID", $KEYCLOAK_CLIENT_ID);
}

class Keycloak
{
    private $id;
    private $token = [];
    private $error;
    private $isDacMember = false;
    private PolicyService $policyService;
    private GeneralHelperService $helper;
    private MeekroDB $db;

    public function __construct(PolicyService $policyService, GeneralHelperService $helper, MeekroDB $db)
    {
        $this->policyService = $policyService;
        $this->helper = $helper;
        $this->db = $db;
        $this->authenticate();
    }

    /**
     * Determine if the current user is authenticated.
     *
     * @return bool
     */
    public function isAuthenticated(): bool
    {
        return $this->token !== [];
    }

    /**
     * Determine if the current user is a guest.
     *
     * @return bool
     */
    public function isGuest(): bool
    {
        return !$this->isAuthenticated();
    }

    /**
     * Determine if the current user is a dac-cli.
     *
     * @return bool
     */
    public function isDacCli(): bool
    {
        if ($this->isGuest()) {
            return false;
        }
        if ($this->isDacMember) {
            return true;
        }

        return $this->token['preferred_username'] == "service-account-dac-cli";
    }

    /**
     * Get details about the authenticated user.
     */
    public function getDetails(): array
    {
        if ($this->isGuest()) {
            return [];
        }
        $properties = [
            'sub',
            'preferred_username',
            'email',
            'name',
            'given_name',
            'family_name',
            'ssh-public-key',
            'c4gh-public-key'
        ];
        $user = array_intersect_key($this->token, array_flip($properties));
        $user['id'] = $this->id;
        return $user;
    }

    /**
     * Get all roles of the authenticated user.
     */
    public function getRoles()
    {
        if ($this->isGuest()) {
            return [];
        }
        $roles = $this->token['realm_access']['roles'];
        return $roles ?? [];
    }

    /**
     * Check if the authenticated user has a specific role.
     * @param string $role
     * @return bool
     */
    public function hasRole(string $role): bool
    {
        if ($this->isGuest()) {
            return false;
        }
        return in_array($role, $this->getRoles());
    }

    /**
     * Returns the decoded JWT token.
     *
     * @return mixed|null
     */
    public function getToken(): string|false
    {
        return json_encode($this->token);
    }

    public function hasValidToken(): bool
    {
        return is_null($this->error);
    }

    public function getTokenDecodingError(): ?string
    {
        return $this->error;
    }

    private function authenticate(): void
    {
        $encodedToken = $this->getBearerToken();
        if (empty($encodedToken)) {
            return;
        }
        try {
            $user = Token::getPayload($encodedToken);
            if (strpos($user['preferred_username'], 'service-account-') === false) {
                $dbUser = $this->db->queryFirstRow("SELECT * from \"user\" where external_id = %s_preferred_username or email = %s_email", $user);
                $propertyKeys = array(
                    "sub",
                    "realm_access",
                    "email_verified",
                    "name",
                    "preferred_username",
                    "given_name",
                    "family_name",
                    "email",
                    'ssh-public-key',
                    'c4gh-public-key'
                );
                $properties = array();
                foreach ($propertyKeys as $pk) {
                    if (isset($user[$pk])) {
                        $properties[$pk] = $user[$pk];
                    }
                }
                if (!$dbUser) {
                    $dbUser = array(
                        "email" => $user['email'],
                        "external_id" => $user['preferred_username'],
                        "properties" => json_encode($properties)
                    );
                    $this->db->insert('user', $dbUser);
                    $dbUser['id'] = $this->db->insertId();
                } elseif ($dbUser['email'] != $user['email'] || $dbUser['external_id'] != $user['preferred_username'] || $dbUser['properties'] != json_encode($properties)) {
                    $this->db->update("user", array("email" => $user['email'], "properties" => json_encode($properties), "external_id" => $user['preferred_username']), "id = %s_id", $dbUser);
                }
                $this->id = +$dbUser['id'];
            }
            $this->token = $user;
        } catch (\Exception $e) {
            $this->error = $e->getMessage();
            $this->token = [];
        }
    }

    private function getAuthorizationHeader(): ?string
    {
        $header = null;

        // Check for standard Authorization header
        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $header = trim($_SERVER["HTTP_AUTHORIZATION"]);
        }
        // Check for Authorization header via Apache rewrite
        elseif (isset($_ENV['HTTP_AUTHORIZATION'])) {
            $header = trim($_ENV["HTTP_AUTHORIZATION"]);
        }
        // Fallback to custom X-Access-Token header
        elseif (isset($_SERVER['HTTP_X_ACCESS_TOKEN'])) {
            $header = trim($_SERVER["HTTP_X_ACCESS_TOKEN"]);
        }

        return $header;
    }

    public function getBearerToken(): ?string
    {
        $header = $this->getAuthorizationHeader();
        if (!empty($header)) {
            if (preg_match('/Bearer\s(\S+)/', $header, $matches)) {
                return $matches[1];
            }
            if (!preg_match('/^Bearer\s/', $header)) {
                return trim($header);
            }
        }
        return null;
    }

    public function checkDacMember(string $datasetId): bool
    {
        if (!$datasetId) {
            $this->isDacMember = false;
            return false;
        }
        $field = $this->helper->checkUuid($datasetId) ? "id" : "properties->>'public_id'::text";
        $policyId = $this->db->queryFirstField("SELECT resource.properties->>'policy_id' as policy_id from resource where " . $field . " = %s", $datasetId);
        if (!$policyId) {
            $this->isDacMember = false;
            return false;
        }
        $dacPolicy  = $this->policyService->getDatasetPolicy($this, $datasetId);
        $user = $this->getDetails();
        if ($dacPolicy['id']) {
            $policy = $this->policyService->getPolicy($this, $dacPolicy['id'], true);
            foreach ($policy['dac']['members'] as $member) {
                if ($member['userID'] === $user['sub']) {
                    return true;
                }
            }
        }
        return false;
    }
}
