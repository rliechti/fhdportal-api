<?php

namespace App\Service\User;

use App\Service\Auth\Keycloak;
use App\Service\Auth\KeycloakService;
use MeekroDB;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Process\Process;
use Symfony\Component\Serializer\SerializerInterface;

class UserKeyService
{
    private MeekroDB $db;
    private MailerInterface $mailer;
    private SerializerInterface $serializer;
    private KeycloakService $keycloak;

    public function __construct(MeekroDB $db, MailerInterface $mailer, SerializerInterface $serializer, KeycloakService $keycloak)
    {
        $this->db = $db;
        $this->mailer = $mailer;
        $this->serializer = $serializer;
        $this->keycloak = $keycloak;
    }


    public function deleteKey(Keycloak $auth, string $userSub, string $publicKey, string $keyType): array
    {
        if ($auth->isGuest()) {
            return array(
                "status" => 401,
                "error" => "Unauthorized",
                "message" => "Unauthorized"
            );
        }

        $user = $auth->getDetails();
        if ($user['sub'] !== $userSub) {
            return array(
                "status" => 401,
                "error" => "Unauthorized",
                "message" => "Unauthorized"
            );
        }

        if (!isset($user["{$keyType}-public-key"])) {
            $user["{$keyType}-public-key"] = [];
        }

        $matchedKey = '';
        foreach ($user["{$keyType}-public-key"] as $pc) {
            if (strpos($publicKey, $pc) !== false || strpos($pc, $publicKey) !== false) {
                $matchedKey = $pc;
                break;
            }
        }

        if (!$matchedKey) {
            return array(
                "status" => 400,
                "error" => "Public key is not associated to the user",
                "message" => "Public key is not associated to the user"
            );
        }

        $validKeys = array_filter($user["{$keyType}-public-key"], fn ($k) => $k !== $matchedKey);

        $updateResult = $this->keycloak->updateUserAttributes($user['sub'], array("{$keyType}-public-key" => array_values($validKeys)));

        $log = [
            'user_id' => $user['id'],
            'key_type' => $keyType,
            'key_sha' => hash('sha256', $matchedKey),
            'action_type_id' => 'DEL',
        ];
        $this->db->insert('user_key_log', $log);

        if (!empty($user['email'])) {
            $to = $user['email'];
            $subject = 'FEGA:' . strtoupper($keyType) . ' public key deleted';
            $message = sprintf(
                "A %s public key has been deleted.\r\n\r\nIts SHA256 hash is: %s\r\n\r\n",
                strtoupper($keyType),
                hash('sha256', $matchedKey)
            );
            $headers = [
                'From' => 'no-reply@sib.swiss',
                'Reply-To' => 'helpdesk@sib.swiss',
                'X-Mailer' => 'PHP/' . phpversion(),
            ];

            // Use mail() for simple notification as in original code
            mail($to, $subject, $message, $headers);
        }

        $content = json_encode($updateResult);
        return array(
            "status" => 200,
            "message" => "Key deleted successfully",
            "content" => $content
        );
    }


    public function registerKey(array $user, string $keyType, string $publicKey): array
    {

        // Validate public key based on key type
        if ($keyType === 'ssh') {
            $tmpfname = tempnam(sys_get_temp_dir(), "checkKey_" . uniqid()) . ".pub";
            $handle = fopen($tmpfname, "w");
            fwrite($handle, $publicKey);
            fclose($handle);
            try {
                $process = new Process(['ssh-keygen', '-l', '-f', $tmpfname]);
                $process->run();
                if (!$process->isSuccessful()) {
                    if (file_exists($tmpfname)) {
                        unlink($tmpfname);
                    }
                    return array(
                        "status" => 400,
                        "error" => "The provided key is not valid",
                        "message" => "The provided key is not valid"
                    );
                }                
            } finally  {
                if (file_exists($tmpfname)) {
                    unlink($tmpfname);
                }
            }
        } elseif ($keyType === 'c4gh') {
            if (strlen($publicKey) < 64){
                return array(
                    "status" => 400,
                    "error" => "The provided key is probably invalid (too short)",
                    "message" => "The provided key is probably invalid (too short)"
                );
            }
            $publicKey = preg_replace("/-{2,}BEGIN CRYPT4GH PUBLIC KEY-{2,}/","",$publicKey);
            $publicKey = preg_replace("/-{2,}END CRYPT4GH PUBLIC KEY-{2,}/","",$publicKey);
            $publicKey = str_replace("\n","",$publicKey);
            // Verify base64 encoding
            if (!preg_match('/^[A-Za-z0-9+\/=]+$/', $publicKey)) {
                return array(
                    "status" => 400,
                    "error" => "Error: Invalid C4GH key format",
                    "message" => "Error: Invalid C4GH key format"
                );
            }

            try {
                $decoded = base64_decode($publicKey, true);
                if ($decoded === false || strlen($decoded) !== 32) {
                    throw new Exception("Error: Invalid C4GH key", 400);
                }
            } catch (\Exception $e) {
                return array(
                    "status" => 400,
                    "error" => "Error: Invalid C4GH key: " . $e->getMessage(),
                    "message" => "Error: Invalid C4GH key: " . $e->getMessage()
                );
            }
        }

        // Check if key already associated to user
        if (isset($user["{$keyType}-public-key"])) {
            foreach ($user["{$keyType}-public-key"] as $existingKey) {
                if (strpos($publicKey, $existingKey) === 0) {
                    return array(
                        "status" => 400,
                        "error" => "Public key is already associated to the user",
                        "message" => "Public key is already associated to the user"
                    );
                }
            }
            $user["{$keyType}-public-key"][] = $publicKey;
        } else {
            $user["{$keyType}-public-key"] = [$publicKey];
        }

        // Update user attribute in Keycloak
        $updateResult = $this->keycloak->updateUserAttributes($user['sub'], array("{$keyType}-public-key" => $user["{$keyType}-public-key"]));

        // Log the key registration
        $this->db->insert("user_key_log", [
            "user_id" => $user['id'],
            "key_type" => $keyType,
            "key_sha" => hash('sha256', $publicKey),
            "action_type_id" => "CRE",
        ]);

        // Notify user by email
        if (!empty($user['email'])) {
            $to = $user['email'];
            $subject = 'FEGA: new ' . $keyType . " public key";
            $message = sprintf(
                "A new %s public key has been registered in FEGA.\r\n\r\nIts SHA256 hash is: %s\r\n\r\n",
                strtoupper($keyType),
                hash('sha256', $publicKey)
            );
            $headers = [
                'From' => 'no-reply@sib.swiss',
                'Reply-To' => 'helpdesk@sib.swiss',
                'X-Mailer' => 'PHP/' . phpversion(),
            ];
            mail($to, $subject, $message, $headers);
        }

        $content = json_encode($updateResult);
        return array(
            "status" => 200,
            "message" => "Key registered successfully",
            "content" => $content
        );
    }
}
