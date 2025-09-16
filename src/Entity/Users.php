<?php

use App\Service\Auth\Keycloak;
use App\Service\JsonSchema\Validator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Ramsey\Uuid\Uuid;

// use App\Entity\User;
if (!defined("DEBUG_EMAIL")) {
    define("DEBUG_EMAIL", "robin.liechti@sib.swiss");
}
function getUsers($email = null): array
{
    $where = ($email) ? " where email = %s" : '';
    return DB::queryFirstRow('SELECT * from "user" '.$where, $email);
}

function getRoles(): array
{
    return DB::query('SELECT * from "role"');
}

function sendUserRequest(Keycloak $auth, array $params): array
{
    $user = $auth->getDetails();
    $contents = array();
    foreach ($params as $k => $v) {
        if ($k == 'role' && $auth->hasRole($v)) {
            throw new Exception("The ".$v." role is already assigned", 204);
        }
        $contents[] = $user['name']." (".$user['preferred_username'].") is requesting: ".$k." = ".$v;
    }
    $title = "FEGA user request";
    $content = implode("\r\n\r\n", $contents);
    $check = mail(DEBUG_EMAIL, $title, $content);
    if (!$check) {
        throw new Exception("Error: email not sent", 501);
    }
    return array("status" => "success");
}

function deleteUserKey(string $user_sub, string $public_key): string{
    
}