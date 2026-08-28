<?php

namespace App\Service\Auth;

use Exception;

class KeycloakService
{
    private string $url;
    private string $realm;
    private string $clientId;
    private string $clientSecret;

    public function __construct(string $url, string $realm, string $clientId, string $clientSecret)
    {
        $this->url = rtrim($url, '/') . '/';
        $this->realm = $realm;
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
    }

    private function doRequest(string $endpoint, string $method = 'GET', $headers = [], $body = null): ?array
    {
        $ch = curl_init($this->url . $endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLINFO_HEADER_OUT, true);
        if ($method == 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
        }
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        if (($_ENV['APP_ENV'] ?? 'prod') === 'dev') { curl_setopt($ch, CURLOPT_VERBOSE, true); }


        $result = curl_exec($ch);
        if ($result === false) {
            $error = curl_error($ch);
            throw new Exception("cURL error on $method $endpoint: $error");
        }

        // For logout endpoint, no meaningful response expected, so return null
        if (strpos($endpoint, '/protocol/openid-connect/logout') !== false) {
            return null;
        }
        $decoded = json_decode($result, true);
        if (isset($decoded['error'])) {
            throw new Exception("Keycloak error on $endpoint: " . ($decoded['error_description'] ?? $decoded['error']));
        }
        return $decoded;
    }

    public function getTokens(): array
    {
        $data = http_build_query([
            'grant_type' => 'client_credentials',
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
        ]);

        $decoded = $this->doRequest("realms/{$this->realm}/protocol/openid-connect/token", 'POST', [
            'Content-Type: application/x-www-form-urlencoded',
        ], $data);

        if (!isset($decoded['access_token'])) {
            throw new Exception("Unable to obtain access token from Keycloak.");
        }

        return [
            'token' => $decoded['access_token'],
            'refresh_token' => $decoded['refresh_token'] ?? $decoded['access_token'],
        ];
    }

    public function logout(string $token, string $refreshToken): void
    {
        $body = json_encode([
            'client_id' => $this->clientId,
            'refresh_token' => $refreshToken,
        ]);

        $this->doRequest(
            "/realms/{$this->realm}/protocol/openid-connect/logout",
            'POST',
            [
                "Authorization: Bearer $token",
                'Content-Type: application/json',
            ],
            $body
        );
    }

    public function getUsers(string $token = ''): array
    {
        if (empty($token)) {
            $tokens = $this->getTokens();
            $token = $tokens['token'];
        }
        return $this->doRequest("admin/realms/{$this->realm}/users", 'GET', [
            "Authorization: Bearer $token",
        ]);
    }

    /**
     * Look up users by email. The query string is built here, from a typed parameter,
     * rather than accepting a free-form query string from callers - a DAC member's
     * externally-supplied email was previously concatenated directly into the admin
     * API query string (security audit M-8).
     */
    public function findUsersByEmail(string $email, string $token = ''): array
    {
        if (empty($token)) {
            $tokens = $this->getTokens();
            $token = $tokens['token'];
        }
        $query = http_build_query(['email' => $email, 'exact' => 'true', 'max' => 2]);
        return $this->doRequest("admin/realms/{$this->realm}/users?{$query}", 'GET', [
            "Authorization: Bearer $token",
        ]) ?? [];
    }

    public function getUser(string $userId, string $token = '', bool $brief = false): ?array
    {
        if (empty($token)) {
            $tokens = $this->getTokens();
            $token = $tokens['token'];
            $refreshToken = $tokens['refresh_token'];
        }

        // Identifiers interpolated into the admin API path are encoded, defence in depth
        // against callers that pass an identifier sourced from an external system (security
        // audit M-8).
        $encodedUserId = rawurlencode($userId);

        $user = $this->doRequest("admin/realms/{$this->realm}/users/$encodedUserId?briefRepresentation=true", 'GET', [
            "Authorization: Bearer $token",
        ]);

        if (empty($user['id'])) {
            return null;
        }

        if ($brief) {
            return $user;
        }

        $roles = $this->doRequest("admin/realms/{$this->realm}/users/$encodedUserId/role-mappings/realm", 'GET', [
            "Authorization: Bearer $token",
        ]);

        $user['roles'] = array_map(fn ($r) => $r['name'], $roles);

        $this->logout($token, $refreshToken);

        return $user;
    }

    public function updateUserAttributes(string $userId, array $attributes, string $token = ''): bool
    {
        if (empty($token)) {
            $tokens = $this->getTokens();
            $token = $tokens['token'];
            $refreshToken = $tokens['refresh_token'];
        }

        $user = $this->getUser($userId, $token, true);
        if (!$user) {
            return false;
        }

        $user['attributes'] = array_merge($user['attributes'] ?? [], $attributes);
        $body = json_encode($user);

        $this->doRequest("admin/realms/{$this->realm}/users/" . rawurlencode($userId), 'PUT', [
            "Authorization: Bearer $token",
            'Content-Type: application/json'
        ], $body);

        return true;
    }

    public function updateUserRoles(string $userId, array $roles, string $token = ''): ?bool
    {
        if (empty($token)) {
            $tokens = $this->getTokens();
            $token = $tokens['token'];
        }

        $encodedUserId = rawurlencode($userId);

        $availableRoles = $this->doRequest("admin/realms/{$this->realm}/users/$encodedUserId/role-mappings/realm/available", 'GET', [
            "Authorization: Bearer $token",
        ]);
        if (empty($availableRoles)) {
            return false;
        }

        $currentRoles = $this->doRequest("admin/realms/{$this->realm}/users/$encodedUserId/role-mappings/realm", 'GET', [
            "Authorization: Bearer $token",
        ]);
        // Remove roles not in the new list
        foreach ($currentRoles as $currentRole) {
            if (!in_array($currentRole['name'], $roles, true)) {
                $body = json_encode([$currentRole]);
                $this->doRequest("admin/realms/{$this->realm}/users/$encodedUserId/role-mappings/realm", 'DELETE', [
                    "Authorization: Bearer $token",
                    'Content-Type: application/json'
                ], $body);
            }
        }

        // Add new roles
        foreach ($roles as $role) {
            foreach ($availableRoles as $availableRole) {
                if ($role === $availableRole['name']) {
                    $body = json_encode([$availableRole]);
                    $this->doRequest("admin/realms/{$this->realm}/users/$encodedUserId/role-mappings/realm", 'POST', [
                        "Authorization: Bearer $token",
                        'Content-Type: application/json'
                    ], $body);
                }
            }
        }

        return true;
    }
}
