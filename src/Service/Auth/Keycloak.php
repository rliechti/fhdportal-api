<?php

namespace App\Service\Auth;

// use Firebase\JWT\JWT;
// use Firebase\JWT\Key;
use DB;
use ReallySimpleJWT\Parse;
use ReallySimpleJWT\Jwt;
use ReallySimpleJWT\Token;
use ReallySimpleJWT\Decode;
use Symfony\Component\Dotenv\Dotenv;
use App\Service\UuidChecker;
$KEYCLOAK_SECRET = $_SERVER['KEYCLOAK_SECRET'];
$KEYCLOAK_REALM = $_SERVER['KEYCLOAK_REALM'];
$KEYCLOAK_CLIENT_ID = $_SERVER['KEYCLOAK_CLIENT_ID'];
$KEYCLOAK_URL = rtrim($_SERVER['KEYCLOAK_URL'], '/')."/";
if (!defined("KEYCLOAK_URL")) {
    define("KEYCLOAK_URL",$KEYCLOAK_URL);
}
if (!defined("KEYCLOAK_REALM")) {
    define("KEYCLOAK_REALM",$KEYCLOAK_REALM);
}
if (!defined("KEYCLOAK_SECRET")) {
    define("KEYCLOAK_SECRET",$KEYCLOAK_SECRET);
}
if (!defined("KEYCLOAK_CLIENT_ID")) {
    define("KEYCLOAK_CLIENT_ID",$KEYCLOAK_CLIENT_ID);
}

class Keycloak
{
    private $id;
    private $token = [];
    private $error;
    private $isDacMember = false;

    public function __construct()
    {
        $this->authenticate();
    }

    /**
     * Determine if the current user is authenticated.
     *
     * @return bool
     */
    public function isAuthenticated()
    {
        return $this->token !== [];
    }

    /**
     * Determine if the current user is a guest.
     *
     * @return bool
     */
    public function isGuest()
    {
        return !$this->isAuthenticated();
    }

    /**
     * Determine if the current user is a dac-cli.
     *
     * @return bool
     */
    public function isDacCli()
    {
        if ($this->isGuest()) {
            return false;
        }
        if ($this->isDacMember){
            return true;
        }
        return $this->token['preferred_username'] == "service-account-dac-cli";
    }

    /**
     * Get details about the authenticated user.
     */
    public function getDetails()
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
    public function hasRole($role)
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
    public function getToken()
    {
        return json_encode($this->token);
    }

    public function hasValidToken()
    {
        return is_null($this->error);
    }

    public function getTokenDecodingError()
    {
        return $this->error;
    }
    public function updateAttribute($attribute,$value)
    {
        require __DIR__."/../../../tools//keycloak.php";
        $user = $this->getDetails();
        updateUserAttributes($user['sub'],array($attribute => $value));
        $this->token[$attribute] = $value;
        return true;
    }
    private function authenticate()
    {
        $encodedToken = $this->getBearerToken();
        if (empty($encodedToken)) {
            return;
        }
        // JWT::$leeway = $this->config['leeway'];
        // $publicKey = $this->buildPublicKey($this->config['public_key']);

        try {
            // $user = $this->introspectToken();
            $user = Token::getPayload($encodedToken);
            if (strpos($user['preferred_username'],'service-account-') === FALSE){
                $dbUser = DB::queryFirstRow("SELECT * from \"user\" where external_id = %s", $user['preferred_username']);
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
                    DB::insert('user', $dbUser);
                    $dbUser['id'] = DB::insertId();
                } elseif ($dbUser['email'] != $user['email'] || $dbUser['properties'] != json_encode($properties)) {
                    DB::update("user", array("email" => $user['email'],"properties" => json_encode($properties)), "external_id = %s", $user['preferred_username']);
                }                
                $this->id = +$dbUser['id'];
            }
            $this->token = $user;

        } catch (\Exception $e) {
            $this->error = $e->getMessage();
            $this->token = [];
        }
    }

    private function getAuthorizationHeader()
    {
        $header = null;
        if (isset($_SERVER['HTTP_X_ACCESS_TOKEN'])) {
            $header = trim($_SERVER["HTTP_X_ACCESS_TOKEN"]);
        }
        return $header;
    }

    public function getBearerToken()
    {
        $header = $this->getAuthorizationHeader();
        if (!empty($header) && preg_match('/Bearer\s(\S+)/', $header, $matches)) {
            return $matches[1];
        }
        return null;
    }
    
    public function checkDacMember($dataset_id){
        require dirname(dirname(__DIR__))."/Entity/Dac.php";
        if (!$dataset_id){
            $this->isDacMember = false;
            return false;
        }
        $uuid = new UuidChecker($dataset_id);
        $field = $uuid->check() ? "id" : "properties->>'public_id'::text";
        $policy_id = DB::queryFirstField("SELECT resource.properties->>'policy_id' as policy_id from resource where ".$field." = %s",$dataset_id);
        if (!$policy_id){
            $this->isDacMember = false;            
            return false;
        }
        $dac_policy  = getDatasetPolicy($this,$dataset_id);
        $user = $this->getDetails();
        if ($dac_policy['id']){
            $policy = getPolicy($this,$dac_policy['id'],true);
            foreach($policy['dac']['members'] as $member){
                if ($member['userID'] === $user['sub']){
                    return true;
                }
            }
        }
        return false;
    }
}
