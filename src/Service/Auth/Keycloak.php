<?php

namespace App\Service\Auth;

use App\Service\Dac\PolicyService;
use App\Service\Utility\GeneralHelperService;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use MeekroDB;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

$KEYCLOAK_SECRET    = $_ENV['KEYCLOAK_SECRET'];
$KEYCLOAK_REALM     = $_ENV['KEYCLOAK_REALM'];
$KEYCLOAK_CLIENT_ID = $_ENV['KEYCLOAK_CLIENT_ID'];
$KEYCLOAK_URL       = rtrim($_ENV['KEYCLOAK_URL'], '/') . "/";
$KEYCLOAK_ISSUER_URL = rtrim($_ENV['KEYCLOAK_ISSUER_URL'] ?? $_ENV['KEYCLOAK_URL'], '/') . "/";

if (!defined("KEYCLOAK_URL")) {
    define("KEYCLOAK_URL", $KEYCLOAK_URL);
}
if (!defined("KEYCLOAK_ISSUER_URL")) {
    define("KEYCLOAK_ISSUER_URL", $KEYCLOAK_ISSUER_URL);
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
    private $isHelpDeskMember = false;
    private PolicyService $policyService;
    private GeneralHelperService $helper;
    private MeekroDB $db;
    private CacheInterface $cache;

    public function __construct(PolicyService $policyService, GeneralHelperService $helper, MeekroDB $db, CacheInterface $cache)
    {
        $this->policyService = $policyService;
        $this->helper = $helper;
        $this->db = $db;
        $this->cache = $cache;
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
     * Determine if the current user is a admin .
     *
     * @return bool
     */
    public function isAdmin(): bool
    {
        return $this->hasRole('admin-fega');
    }

    /**
     * True when the caller is the DAC integration client.
     *
     * Authorization is based on the token's authorized-party claim and a realm
     * role granted in Keycloak - never on preferred_username, which is a profile
     * attribute and not an authorization claim (security audit H-1).
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

        return $this->hasRole('dac-cli')
            && ($this->token['azp'] ?? null) === ($_ENV['KEYCLOAK_DAC_CLIENT_ID'] ?? 'dac-cli');
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
            $keys    = JWK::parseKeySet($this->getSigningKeys(), 'RS256');
            $decoded = JWT::decode($encodedToken, $keys);
            $user    = json_decode(json_encode($decoded), true);            
            $expectedIssuer = KEYCLOAK_ISSUER_URL . 'realms/' . KEYCLOAK_REALM;
            if (($user['iss'] ?? null) !== $expectedIssuer) {
                throw new \Exception('Unexpected token issuer: ' . ($user['iss'] ?? '(none)'));
            }
            // Audience: this API must be a named recipient of the token. Without this,
            // any token signed by the realm - issued for any other client - is accepted
            // here too (security audit H-2).
            $expectedAudience = $_ENV['KEYCLOAK_EXPECTED_AUDIENCE'] ?? KEYCLOAK_CLIENT_ID;
            $aud = $user['aud'] ?? [];
            $aud = is_array($aud) ? $aud : [$aud];
            if (!in_array($expectedAudience, $aud, true)) {
                throw new \Exception('Token audience does not include this API');
            }

            // Authorized party: the token must come from a client we trust.
            $allowedParties = array_filter(explode(',', $_ENV['KEYCLOAK_ALLOWED_AZP'] ?? ''));
            if ($allowedParties && !in_array($user['azp'] ?? '', $allowedParties, true)) {
                throw new \Exception('Token authorized party is not allowed');
            }

            // Required claims must exist before any downstream code reads them.
            foreach (['sub', 'preferred_username'] as $claim) {
                if (!isset($user[$claim]) || !is_string($user[$claim])) {
                    throw new \Exception("Token missing required claim: {$claim}");
                }
            }

            if (strpos($user['preferred_username'], 'service-account-') === false) {
                $dbUser = $this->db->queryFirstRow("SELECT * from \"user\" where external_id = %s_preferred_username", $user);
                // if (!$dbUser){
                //     $dbUser = $this->db->queryFirstRow("SELECT * from \"user\" where email = %s_email", $user);
                // }
                
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
                    $this->db->update("user", array("email" => $user['email'], "properties" => json_encode($properties), "external_id" => $user['preferred_username']), "id = %s", $dbUser['id']);
                }
                $this->id = +$dbUser['id'];
            }
            $this->token = $user;
        } catch (\Exception $e) {
            $this->error = $e->getMessage();
            $this->token = [];
        }
    }

    /**
     * Returns Keycloak's realm signing keys (JWKS), cached to avoid
     * fetching them on every request.
     */
    private function getSigningKeys(): array
    {
        return $this->cache->get('keycloak_jwks_' . KEYCLOAK_REALM, function (ItemInterface $item) {
            $item->expiresAfter(3600);
            return $this->fetchJwks();
        });
    }

    private function fetchJwks(): array
    {
        $ch = curl_init(KEYCLOAK_URL . 'realms/' . KEYCLOAK_REALM . '/protocol/openid-connect/certs');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        $result = curl_exec($ch);
        if ($result === false) {
            throw new \Exception('Unable to fetch Keycloak signing keys: ' . curl_error($ch));
        }
        $jwks = json_decode($result, true);
        if (!isset($jwks['keys'])) {
            throw new \Exception('Invalid JWKS response from Keycloak');
        }
        return $jwks;
    }

    private function getAuthorizationHeader(): ?string
    {
        $header = null;

        // Check for standard Authorization header
        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $header = trim($_SERVER["HTTP_AUTHORIZATION"]);
        }
        // Check for Authorization header via Apache rewrite
        #elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        #    $header = trim($_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
        #}
        // Check for Authorization header via Apache environment
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
        if ($header === null || $header === '') {
            return null;
        }
        // Anchored, exact-form match only: "Bearer <token>". The previous unanchored
        // regex matched Bearer anywhere in a malformed composite header, and a header
        // that didn't start with "Bearer " at all was passed through verbatim as a
        // token - JWT::decode() would still reject anything invalid, but neither
        // behaviour was intended (security audit M-15).
        return preg_match('/^Bearer\s+([A-Za-z0-9\-._~+\/]+=*)$/', $header, $m) ? $m[1] : null;
    }

    public function checkDacMember(string $datasetId): bool
    {
        if (!$datasetId) {
            $this->isDacMember = false;
            return false;
        }
        if ($this->isDacCli()) {
            return true;
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
