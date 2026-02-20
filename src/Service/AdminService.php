<?php

namespace App\Service;

use App\Service\Auth\KeycloakService;
use App\Service\User\UserRoleReadService;

class AdminService
{
    private KeycloakService $keycloak;
    private UserRoleReadService $roleRead;

    public function __construct(KeycloakService $keycloak, UserRoleReadService $roleRead)
    {
        $this->keycloak = $keycloak;
        $this->roleRead = $roleRead;
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
}
