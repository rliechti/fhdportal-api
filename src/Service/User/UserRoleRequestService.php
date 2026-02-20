<?php

namespace App\Service\User;

use App\Service\Auth\Keycloak;
use App\Service\Auth\KeycloakService;
use MeekroDB;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Serializer\SerializerInterface;

class UserRoleRequestService
{
    private MeekroDB $db;
    private MailerInterface $mailer;
    private SerializerInterface $serializer;
    private KeycloakService $keycloak;

    public function __construct(MeekroDB $db, SerializerInterface $serializer, KeycloakService $keycloak, MailerInterface $mailer)
    {
        $this->db = $db;
        $this->serializer = $serializer;
        $this->keycloak = $keycloak;
        $this->mailer = $mailer;
    }

    public function createUserDirectory(string $destination, bool $writable = false): ?JsonResponse
    {
        if (!file_exists($destination)) {
            mkdir($destination, 0770, true);
            if (!file_exists($destination)) {
                return new JsonResponse($destination . " does not exist", 400);
            }
        }
        if ($writable && !is_writable($destination)) {
            chmod($destination, 0777);
            if (!is_writable($destination)) {
                return new JsonResponse($destination . " is not writable", 400);
            }
        }
        return null;
    }

    /**
     * Registers a user request with optional file upload and sends notification email.
     * @param Keycloak $auth
     * @param Request $request
     * @param string $projectDir
     * @param array $params
     * @return array
     * @throws \Exception
     */
    public function registerUserRequest(Keycloak $auth, Request $request, string $projectDir): array
    {
        $user = $auth->getDetails();

        $destination = $projectDir . '/data/dtpas/';
        $response = $this->createUserDirectory($destination, true);
        if ($response instanceof JsonResponse) {
            return ['error' => $response->getContent()];
        }

        $uploadedFile = $request->files->get("dtpa");



        if (!$uploadedFile) {
            return array(
                "status" => "error",
                "message" => 'No file uploaded.'
            );
        }

        // List of allowed extensions and MIME types
        $allowedExtensions = ['pdf', 'docx'];
        $allowedMimeTypes = [
            'application/pdf',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ];

        // Check file extension
        $fileExtension = $uploadedFile->getClientOriginalExtension();
        $fileMimeType = $uploadedFile->getClientMimeType();

        if (
            !in_array(strtolower($fileExtension), $allowedExtensions, true) ||
            !in_array($fileMimeType, $allowedMimeTypes, true)
        ) {
            return array(
                "status" => "error",
                "message" => 'Invalid file type. Only PDF and DOCX allowed.'
            );
        }


        $filepath = null;

        if ($uploadedFile instanceof UploadedFile) {
            if (!$uploadedFile->isValid()) {
                $errorMessages = [
                    UPLOAD_ERR_INI_SIZE => 'The uploaded file exceeds the maximum allowed size.',
                    UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the maximum allowed size specified in the form.',
                    UPLOAD_ERR_PARTIAL => 'The file was only partially uploaded.',
                    UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
                    UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder.',
                    UPLOAD_ERR_CANT_WRITE => 'Failed to write the file to disk.',
                    UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload.',
                ];
                $errorCode = $uploadedFile->getError();
                $errorMessage = $errorMessages[$errorCode] ?? 'Unknown error during file upload.';
                return ['error' => $errorMessage];
            }

            $filename = $uploadedFile->getClientOriginalName();
            $pathinfo = pathinfo($filename);
            $basename = $pathinfo['filename'];
            $ext = $pathinfo['extension'];
            $md5 = md5_file($uploadedFile->getPathname());

            $potentialPath = $destination . $basename . "_" . $md5 . "." . $ext;
            $filepath = str_replace($projectDir, "", $potentialPath);

            if (!file_exists($potentialPath)) {
                $uploadedFile->move($destination, basename($potentialPath));
                if (!file_exists($potentialPath)) {
                    return ['error' => $potentialPath . " not copied to final directory"];
                }
            }

            $fileProperties = [
                "name" => $filepath,
                "original_name" => $filename,
                "filesize" => filesize($potentialPath),
                "mime_type" => mime_content_type($potentialPath),
                "md5" => md5_file($potentialPath),
            ];

            $uuid = Uuid::uuid4();
            $resourceId = $uuid->toString();

            $fileResourceTypeId = $this->db->queryFirstField("SELECT id FROM resource_type WHERE name = 'File'");

            $filePropertiesJson = json_encode($fileProperties);

            $resource = [
                'id' => $resourceId,
                'properties' => $filePropertiesJson,
                'resource_type_id' => $fileResourceTypeId,
                'status_type_id' => 'DRA',
            ];

            $existingResourceId = $this->db->queryFirstField(
                "SELECT id FROM resource WHERE resource_type_id = %i AND properties->>'name' = %s",
                $fileResourceTypeId,
                $filepath
            );

            $actionTypeId = 'CRE';
            if ($existingResourceId) {
                $resource['id'] = $existingResourceId;
                $actionTypeId = 'MOD';
            } else {
                $this->db->insert("resource", $resource);
            }

            // Log resource action
            $logId = Uuid::uuid4()->toString();
            $log = [
                "id" => $logId,
                "resource_id" => $resource['id'],
                "user_id" => $user['id'],
                "action_type_id" => $actionTypeId,
                "properties" => $resource['properties']
            ];
            $this->db->insert("resource_log", $log);
        }

        $contents = [];
        $role = $request->get('role');
        if ($role) {
            $ROLE = strtoupper($role);
            if ($auth->hasRole($role)) {
                throw new \Exception("The " . $role . " role is already assigned", 204);
            }
            $contents[] = "{$user['name']} ({$user['email']}) is requesting a {$ROLE} role.\r\n\r\n";
            $contents[] = "Switch EduID is {$user['preferred_username']}\r\n\r\n";
            $contents[] = "Thanks for reviewing this request.";
        }

        $title = "FEGA user request";
        $content = implode("", $contents) . "\r\n\r\n";
        $email = (new Email())
            ->from($_SERVER['NO_REPLY_EMAIL'])
            ->to($_SERVER['HELPDESK_EMAIL'])
            ->subject($title)
            ->text($content);

        // Attach file if available
        if ($filepath !== null) {
            $email->attachFromPath($projectDir . $filepath);
        }

        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface $e) {
            throw new \Exception("Error: email not sent: " . $e->getMessage(), 501);
        }

        return ['status' => 'success'];
    }
}
