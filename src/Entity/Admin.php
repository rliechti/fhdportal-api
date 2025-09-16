<?php

require __DIR__."/../../tools//keycloak.php";

function getAllUsers()
{
    $kcusers = getKeyCloakUsers();
    $users = array();
    foreach ($kcusers as $kcuser) {
        $user = getKeyCloakUser($kcuser['id']);
        $users[] = $user;
    }
    return $users;
}

function setRoles($user_id, $roles)
{
    updateUserRoles($user_id, $roles);
    $user = getKeyCloakUser($user_id);
    return $user['roles'];
}
