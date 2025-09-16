<?php

require __DIR__.'/vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->load();

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

function logoutKeycloak($token,$refresh_token) {

    $fields_string = json_encode(array('client_id' => KEYCLOAK_CLIENT_ID, 'refresh_token' => $refresh_token));
    // Prepare new cURL resource
    $ch = curl_init(KEYCLOAK_URL.'admin/realms/'.KEYCLOAK_REALM.'/protocol/openid-connect/logout');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    // Set HTTP Header for POST request 
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Content-Type: application/json',
            'Authorization: Bearer '.$token
    ));

    curl_setopt($ch, CURLINFO_HEADER_OUT, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $fields_string);
    $result = curl_exec($ch);
    curl_close($ch);
    if ($result===FALSE){
        throw new Exception("ERROR: unable to logout", 404);
    }
}

function getKeyCloakTokens() {
    $data = array(
        'grant_type' => 'client_credentials',
        'client_id' => KEYCLOAK_CLIENT_ID,
        'client_secret' => KEYCLOAK_SECRET
    );
    
    $fields_string = http_build_query($data);
    // Prepare new cURL resource
    $url = KEYCLOAK_URL.'realms/'.KEYCLOAK_REALM.'/protocol/openid-connect/token';
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLINFO_HEADER_OUT, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/x-www-form-urlencoded'));
    curl_setopt($ch, CURLOPT_POSTFIELDS, $fields_string);
    curl_setopt($ch, CURLOPT_VERBOSE, true);
 
 
    // Submit the POST request
    $result = curl_exec($ch);
    // Close cURL session handle
    if(!$result) {
        throw new Exception("ERROR: unable to connect to Keycloak: ".curl_error($ch), 404);
    }
    curl_close($ch);
    $res = json_decode($result);
    
    if(isset($res->error)) {
        $mes = (isset($res->error_message)) ? $res->error_message : $res->error;
        throw new Exception("Keycloak error: ".$mes, 404);
    }
    
    $refresh_token = (isset($res->refresh_token)) ? $res->refresh_token : $res->access_token;
    $token = $res->access_token;
    if (!$token){
        throw new Exception("ERROR: unable to get Keycloak token", 404);    
    }
    return array('refresh_token' => $refresh_token, "token" => $token);
}

function getKeyCloakUsers($token='',$query='') {
    if (!$token){
        $tokens = getKeyCloakTokens();
        $token = $tokens['token'];
    }
    $ch = curl_init(KEYCLOAK_URL.'admin/realms/'.KEYCLOAK_REALM.'/users'.($query?"?".$query:""));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
 
    // Set HTTP Header for POST request 
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Content-Type: application/json',
        'Authorization: Bearer '.$token
    ));
 
    // Submit the POST request
    $result = curl_exec($ch);
    curl_close($ch);
    
    $res = json_decode($result,JSON_OBJECT_AS_ARRAY);   
    if(isset($res['error'])) {
        throw new Exception("Keycloak error: ".json_encode($res), 404);
    }
    return $res;
}

function getKeyCloakUsersByRole($role,$token='') {
    if (!$token){
        $tokens = getKeyCloakTokens();
        $token = $tokens['token'];
    }
    $ch = curl_init(KEYCLOAK_URL.'admin/realms/'.KEYCLOAK_REALM.'/roles/'.$role.'/users');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
 
    // Set HTTP Header for POST request 
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Content-Type: application/json',
        'Authorization: Bearer '.$token
    ));
 
    // Submit the POST request
    $result = curl_exec($ch);
    curl_close($ch);
    
    $res = json_decode($result,JSON_OBJECT_AS_ARRAY);   
    if(isset($res['error'])) {
        throw new Exception("Keycloak error: ".$res['error'], 404);
    }
    return $res;
}



function getKeyCloakGroups($token = '')
{
    if (!$token) {
        $tokens = getKeyCloakTokens();
        $token = $tokens['token'];
    }
    $ch = curl_init(KEYCLOAK_URL.'admin/realms/'.KEYCLOAK_REALM.'/groups');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    // Set HTTP Header for POST request
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Content-Type: application/json',
        'Authorization: Bearer '.$token
    ));

    // Submit the POST request
    $result = curl_exec($ch);
    curl_close($ch);

    $res = json_decode($result,JSON_OBJECT_AS_ARRAY);   
    if(isset($res['error'])) {
        throw new Exception("Keycloak error: ".$res['error']." ".$group_id, 404);
    }
    return $res;
} 

function getKeyCloakGroupMembers($token, $group_id)
{
    $ch = curl_init(KEYCLOAK_URL.'admin/realms/'.KEYCLOAK_REALM.'/groups/'.$group_id."/members");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    // Set HTTP Header for POST request
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Content-Type: application/json',
        'Authorization: Bearer '.$token
    ));

    // Submit the POST request
    $result = curl_exec($ch);
    curl_close($ch);

    $res = json_decode($result, JSON_OBJECT_AS_ARRAY);
    if (isset($res['error'])) {
        throw new Exception("Keycloak error: ".$res['error']." ".$group_id, 404);
    }
    return $res;
}

function getKeyCloakClientIds($token = '')
{
    $logout = false;
    if (!$token) {
        $tokens = getKeyCloakTokens();
        $token = $tokens['token'];
        $refresh_token = $tokens['refresh_token'];
        $logout = true;
    }

    $ch = curl_init(KEYCLOAK_URL.'admin/realms/'.KEYCLOAK_REALM.'/clients');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    // Set HTTP Header for POST request
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Content-Type: application/json',
        'Authorization: Bearer '.$token
    ));

    // Submit the POST request
    $result = curl_exec($ch);
    curl_close($ch);

    $res = json_decode($result, JSON_OBJECT_AS_ARRAY);
    if (isset($res['error'])) {
        throw new Exception("Keycloak error: ".$res['error'], 404);
    }
    $clients = array_map(function ($c) {
        return array("id" => $c['id'], "name" => $c['clientId']);
    }, $res);

    if ($logout) {
        logoutKeycloak($token, $refresh_token);
    }

    return $clients;

}


function getKeyCloakUser($user_id, $token = '', $brief = false)
{
    if (!$token) {
        $logout = true;
        $tokens = getKeyCloakTokens();
        $token = $tokens['token'];
        $refresh_token = $tokens['refresh_token'];
    }
    $ch = curl_init(KEYCLOAK_URL.'admin/realms/'.KEYCLOAK_REALM.'/users/'.$user_id.'?briefRepresentation=true');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    // Set HTTP Header for POST request 
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Content-Type: application/json',
        'Authorization: Bearer '.$token
    ));
 
    // Submit the POST request
    $result = curl_exec($ch);
    curl_close($ch);

    $res = json_decode($result,JSON_OBJECT_AS_ARRAY);   
    if(isset($res['error']) && !$brief) {
        // throw new Exception("Keycloak error for ".$user_id.": ".$res['error'], 404);
        return false;
    }
    if (!isset($res['id']) || !$res['id']) {
        return false;
    }
    $user = array(
        "id" => $res['id'],
        "username" => $res['username'],
        "enabled" => $res['enabled'],
        "firstName" => (isset($res['firstName'])) ? $res['firstName'] : NULL,
        "lastName" => (isset($res['lastName'])) ? $res['lastName'] : NULL,
        "email" => (isset($res['email'])) ? $res['email'] : NULL,
        "public-ssh-key" => (isset($res['public-ssh-key'])) ? $res['email'] : array()
    );
        
    if ($brief) {
        return $user;
    }
        
    $ch = curl_init(KEYCLOAK_URL.'admin/realms/'.KEYCLOAK_REALM.'/users/'.$user_id.'/role-mappings/realm');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
 
    // Set HTTP Header for POST request 
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Content-Type: application/json',
        'Authorization: Bearer '.$token
    ));
 
    // Submit the POST request
    $result = curl_exec($ch);
    curl_close($ch);
    
    $roles = json_decode($result,JSON_OBJECT_AS_ARRAY); 
    if(isset($roles['error'])) {
        throw new Exception("Keycloak error: ".$roles['error'], 404);
    }
    $user['roles'] = array_map(function($r) {
        return $r['name'];
    },$roles);
        
    logoutKeycloak($token,$refresh_token);
    return $user;
}

function updateUserAttributes ($user_id, $attributes,$token=""){
    if (!$token) {
        $tokens = getKeyCloakTokens();
        $token = $tokens['token'];
        $refresh_token = $tokens['refresh_token'];      
    }
    $ch = curl_init(KEYCLOAK_URL.'admin/realms/'.KEYCLOAK_REALM.'/users/'.$user_id.'?briefRepresentation=true');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    // Set HTTP Header for POST request 
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Content-Type: application/json',
        'Authorization: Bearer '.$token
    ));
 
    // Submit the POST request
    $result = curl_exec($ch);
    curl_close($ch);

    $res = json_decode($result,JSON_OBJECT_AS_ARRAY);       
    if(isset($res['error']) && !$brief) {
        // throw new Exception("Keycloak error for ".$user_id.": ".$res['error'], 404);
        return false;
    }
    if (!isset($res['id']) || !$res['id']) {
        return false;
    }
    if (!isset($res['attributes'])){
        $res['attributes'] = array();
    }
    foreach($res['attributes'] as $k => $v){
        $res['attributes'][$k] = (isset($attributes[$k])) ? $attributes[$k] : $v;
    }
    foreach($attributes as $k => $v){
        if (!isset($res['attributes'][$k])){
            $res['attributes'][$k] = $v;
        }
    }
    $fields_string = json_encode($res);
    
    $ch = curl_init(KEYCLOAK_URL.'/admin/realms/'.KEYCLOAK_REALM.'/users/'.$user_id);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    // Set HTTP Header for PUT request 
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Content-Type: application/json',
        'Authorization: Bearer '.$token
    ));

    curl_setopt($ch, CURLINFO_HEADER_OUT, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
    curl_setopt($ch, CURLOPT_POSTFIELDS, $fields_string);
    $result = curl_exec($ch);
    // Close cURL session handle
    if($result === FALSE) { 
        throw new Exception("ERROR: unable to udpate user attributes", 404);
    }
    curl_close($ch);
    return true;
        
}
    
function updateUserRoles ($user_id,$roles,$token=""){

    if (!$token) {
        $tokens = getKeyCloakTokens();
        $token = $tokens['token'];
        $refresh_token = $tokens['refresh_token'];      
    }
    // if ($logout) {
    //  logoutKeycloak($token,$refresh_token);
    // }
    $kcroles = array();
    $ch = curl_init(KEYCLOAK_URL.'/admin/realms/'.KEYCLOAK_REALM.'/users/'.$user_id.'/role-mappings/realm/available');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    // Set HTTP Header for POST request 
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Content-Type: application/json',
        'Authorization: Bearer '.$token
    ));

    // Submit the POST request
    $result = curl_exec($ch);
    curl_close($ch);
    $kcroles = json_decode($result,JSON_OBJECT_AS_ARRAY);   
    if(isset($res['error']) && !$brief) {
        // throw new Exception("Keycloak error for ".$user_id.": ".$res['error'], 404);
        return false;
    }
    if (count($kcroles) === 0){
        return false;
    }
    $kcuserroles = array();
    $ch = curl_init(KEYCLOAK_URL.'/admin/realms/'.KEYCLOAK_REALM.'/users/'.$user_id.'/role-mappings/realm');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    // Set HTTP Header for POST request 
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Content-Type: application/json',
        'Authorization: Bearer '.$token
    ));

    // Submit the POST request
    $result = curl_exec($ch);
    curl_close($ch);
    $kcuserroles = json_decode($result,JSON_OBJECT_AS_ARRAY);   
    if(isset($result['error']) && !$brief) {
        // throw new Exception("Keycloak error for ".$user_id.": ".$res['error'], 404);
        return false;
    }
    foreach($kcuserroles as $r){
        if (!in_array($r['name'],$roles)){
            $fields_string = json_encode(array($r));
            // Prepare new cURL resource
            $ch = curl_init(KEYCLOAK_URL.'/admin/realms/'.KEYCLOAK_REALM.'/users/'.$user_id.'/role-mappings/realm');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

            // Set HTTP Header for POST request 
            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                'Content-Type: application/json',
                    'Authorization: Bearer '.$token
            ));

            curl_setopt($ch, CURLINFO_HEADER_OUT, true);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
            curl_setopt($ch, CURLOPT_POSTFIELDS, $fields_string);
            $result = curl_exec($ch);
            // Close cURL session handle
            if($result === FALSE) { 
                // throw new Exception("ERROR: unable to post a role", 404);
                return false;
            }
            curl_close($ch);
        }
    }
    
    foreach($roles as $role){
        foreach($kcroles as $kcrole) {
            $role_id = '';
            if ($role == $kcrole['name']){
                $fields_string = json_encode(array($kcrole));
                // Prepare new cURL resource

                $ch = curl_init(KEYCLOAK_URL.'/admin/realms/'.KEYCLOAK_REALM.'/users/'.$user_id.'/role-mappings/realm');
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

                // Set HTTP Header for POST request 
                curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                    'Content-Type: application/json',
                        'Authorization: Bearer '.$token
                ));

                curl_setopt($ch, CURLINFO_HEADER_OUT, true);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $fields_string);


                // Submit the POST request
                $result = curl_exec($ch);
                // Close cURL session handle
                if($result === FALSE) { 
                    // throw new Exception("ERROR: unable to post a role", 404);
                    return false;
                }
                curl_close($ch);
                
            }
        }
    }
    return null;
}
