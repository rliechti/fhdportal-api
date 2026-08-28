<?php

namespace App\Service\User;

use App\Service\Auth\Keycloak;
use App\Service\Auth\KeycloakService;
use App\Service\Utility\GeneralHelperService;
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
    private const GRANTABLE_ROLES = ['submitter'];

    private MeekroDB $db;
    private MailerInterface $mailer;
    private SerializerInterface $serializer;
    private KeycloakService $keycloak;
    private GeneralHelperService $helper;

    public function __construct(MeekroDB $db, SerializerInterface $serializer, KeycloakService $keycloak, MailerInterface $mailer, GeneralHelperService $helper)
    {
        $this->db = $db;
        $this->serializer = $serializer;
        $this->keycloak = $keycloak;
        $this->mailer = $mailer;
        $this->helper = $helper;
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
        $response = $this->helper->createDirectory($destination, true);
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

        // Validate actual content, not client-supplied filename/MIME claims - both are
        // attacker-controlled and were previously trusted outright (security audit M-11).
        if ($uploadedFile->getSize() > 10 * 1024 * 1024) {
            return array(
                "status" => "error",
                "message" => 'File exceeds the 10 MB limit.'
            );
        }
        $allowedByRealMime = [
            'application/pdf' => 'pdf',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        ];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $realMime = finfo_file($finfo, $uploadedFile->getPathname());
        finfo_close($finfo);

        if (!isset($allowedByRealMime[$realMime])) {
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
            // Name the stored file from the verified content type and a content hash,
            // never from client input (removes the regex-injection/path exposure that
            // came from deriving it via pathinfo() on a client-supplied name).
            $ext = $allowedByRealMime[$realMime];
            $safeName = sprintf('dtpa_%s.%s', hash_file('sha256', $uploadedFile->getPathname()), $ext);

            $potentialPath = $destination . $safeName;
            $filepath = str_replace($projectDir, "", $potentialPath);

            if (!file_exists($potentialPath)) {
                $uploadedFile->move($destination, $safeName);
                if (!file_exists($potentialPath)) {
                    return ['error' => $potentialPath . " not copied to final directory"];
                }
            }

            $fileProperties = [
                "name" => $filepath,
                "original_name" => $filename,
                "filesize" => filesize($potentialPath),
                "mime_type" => $realMime,
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
        // The requested role is taken from an allowlist and never inlined raw into the
        // help-desk email, which a human reads to decide whether to grant a privileged role.
        $role = (string) $request->get('role', 'submitter');
        if (!in_array($role, self::GRANTABLE_ROLES, true)) {
            throw new \Exception('Unsupported role request', 400);
        }
        if ($auth->hasRole($role)) {
            throw new \Exception("The " . $role . " role is already assigned", 204);
        }
        $ROLE = strtoupper($role);
        $contents[] = "{$user['name']} ({$user['email']}) is requesting a {$ROLE} role.\r\n\r\n";
        $contents[] = "Switch EduID is {$user['preferred_username']}\r\n\r\n";
        $contents[] = "Thanks for reviewing this request.";

        $title = "FEGA user request";
        $content = implode("", $contents) . "\r\n\r\n";
        $email = (new Email())
            ->from($_ENV['NO_REPLY_EMAIL'])
            ->to($_ENV['HELPDESK_EMAIL'])
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
