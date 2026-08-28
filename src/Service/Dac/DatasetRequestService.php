<?php

namespace App\Service\Dac;

use App\Service\Auth\Keycloak;
use App\Service\RabbitMq\RabbitMqInterface;
use App\Service\Utility\GeneralHelperService;
use MeekroDB;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Serializer\SerializerInterface;

class DatasetRequestService
{
    private MeekroDB $db;
    private MailerInterface $mailer;
    private SerializerInterface $serializer;
    private RabbitMqInterface $rabbitmq;
    private GeneralHelperService $helper;

    public function __construct(MeekroDB $db, MailerInterface $mailer, SerializerInterface $serializer, RabbitMqInterface $rabbitmq, GeneralHelperService $helper)
    {
        $this->db = $db;
        $this->mailer = $mailer;
        $this->serializer = $serializer;
        $this->rabbitmq = $rabbitmq;
        $this->helper = $helper;
    }


    public function sendRequest(Keycloak $auth, string $properties, string $dacRequestId = ''): array
    {
        if ($auth->isGuest()) {
            return [
                "status"    => "error",
                "message"   => 'Unauthorized',
                "exit_code" => 401
            ];
        }

        $user = $auth->getDetails();
        
        $c4gh_public_key = $user['c4gh-public-key'][0] ?? "";
        if (!$c4gh_public_key){
            return [
                "status" => "error",
                "message" => "Missing C4GH public key",
                "exit_code" => 500
            ];
        }
        
        $form = json_decode($properties, true);
        if ($user['name'] !== $form['username']) {
            return [
                "status"    => "error",
                "message"   => 'Unauthorized',
                "exit_code" => 401
            ];
        }
        $field = $this->helper->checkUuid($form['dataset_id']) ? 'id' : 'public_id';

        $resource_id = $this->db->queryFirstField("SELECT id from dataset_view where %b = %s and status_type_id in ('PUB','REV','RES') and released_date <= '" . date('Y-m-d') . "'", $field, $form['dataset_id']);
        if (!$resource_id) {
            return [
                "status"    => "error",
                "message"   => 'Dataset not available',
                "exit_code" => 500
            ];
        }
        $user_id = $this->db->queryFirstField("SELECT id from \"user\" where id = %i_id and external_id = %s_preferred_username", $user);
        if (!$user_id) {
            return [
                "status"    => "error",
                "message"   => 'Unauthorized',
                "exit_code" => 401
            ];
        }

        $propertiesData = json_decode($properties, true);
        if ($dacRequestId) {
            $propertiesData['dac_request_id'] = $dacRequestId;
        }
        $propertiesData['c4gh_public_key'] = $c4gh_public_key;
        $propertiesJson = json_encode($propertiesData);

        $db_request = array(
            "dataset_id"  => $resource_id,
            "user_id"     => $user_id,
            "status_type" => "DAP",
            "validator_id" => null,
            "properties"  => $propertiesJson
        );
        $db_request['id'] = $this->db->queryFirstField("SELECT id from dataset_requests where user_id = %i_user_id and dataset_id = %s_dataset_id", $db_request);
        if ($db_request['id']) {
            $this->db->update(
                'dataset_requests',
                ['status_type' => 'DAP', 'properties' => $propertiesJson, 'action_time' => date('Y-m-d H:i:s')],
                'id = %s',
                $db_request['id']
            );
            return [
                "status"    => "success",
                "message"   => 'Dataset request updated',
                "exit_code" => 200
            ];
        } else {
            $db_request['id'] = Uuid::uuid4();
        }
        $this->db->insert("dataset_requests", $db_request);
        $datasetRequest = $this->db->queryFirstRow("SELECT * from dataset_request_view where request_id = %s", $db_request['id']);

        $title = 'Swiss-FEGA: New dataset access request';
        $content = "A new dataset access request has been registered.\r\n\r\n";
        $content .= "  Study: " . $datasetRequest['study_public_id'] . ": " . $datasetRequest['study'] . "\r\n";
        $content .= "  Dataset: " . $datasetRequest['dataset_public_id'] . ": " . $datasetRequest['dataset'] . "\r\n";
        $content .= "  Requester: " . $datasetRequest['requester'] . " <" . $datasetRequest['requester_email'] . ">" . "\r\n";
        
        if (isset($datasetRequest['requester_comment']) && $datasetRequest['requester_comment']){
            $content .= "  Comment: " . $datasetRequest['requester_comment'] . "\r\n\r\n";            
        }
        $email = (new Email())
            ->from($_ENV['NO_REPLY_EMAIL'])
            ->to($_ENV['GOS_DAC_EMAIL'])
            ->subject($title)
            ->text($content);

        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface $e) {
            throw new \Exception("Error: email not sent: " . $e->getMessage(), 501);
        }

        return [
            "status"    => "success",
            "message"   => 'Request registered successfully',
            "exit_code" => 200
        ];
    }

    public function getAllRequests(Keycloak $auth): array
    {
        if ($auth->isGuest() || !$auth->hasRole('admin-fega')) {
            return [
                "status"    => "error",
                "message"   => 'Unauthorized',
                "exit_code" => 401
            ];
        }
        $requests = $this->db->query("SELECT * from dataset_request_view");

        // Strip DOA download credentials from the admin listing for the same reason as
        // getUserRequests(): they're short-lived secrets meant to be issued on demand via
        // getRequestTokens(), not surfaced by a general status-listing endpoint.
        foreach ($requests as &$request) {
            unset($request['doa_access_key'], $request['doa_secret_key'], $request['doa_session_token']);
        }
        unset($request);

        return [
            "status"    => "success",
            "content"   => $requests,
            "message"   => count($requests) . " access requests",
            "exit_code" => 200
        ];
    }

    public function getUserRequests(Keycloak $auth): array
    {
        if ($auth->isGuest()) {
            return [
                "status"    => "error",
                "message"   => 'Unauthorized',
                "exit_code" => 401
            ];
        }

        $user = $auth->getDetails();

        // DOA access/secret keys and session tokens are intentionally excluded here: they are
        // short-lived download credentials, minted on demand and scoped to a single request via
        // getRequestTokens(), and shouldn't be exposed by a general status-listing endpoint.
        $requests = $this->db->query(
            "SELECT
                request_id, dataset_id, dataset, dataset_public_id,
                study, study_public_id,
                doa_bucket_name, doa_endpoint, doa_object_expiration,
                doa_sts_token_expiration, action_time, request_status, policy_id, error_type, error_message, c4gh_public_key
            FROM dataset_request_view
            WHERE requester_id = %i_id",
            $user
        );

        return [
            "status"    => "success",
            "content"   => $requests,
            "message"   => count($requests) . " access requests",
            "exit_code" => 200
        ];
    }

    public function patchRequest(Keycloak $auth, string $request_id, array $params): array
    {
        if ($auth->isGuest() || !$auth->isDacCli()) {
            return [
                "status"    => "error",
                "message"   => 'Unauthorized',
                "exit_code" => 401
            ];
        }
        $request = $this->db->queryFirstRow("SELECT * from dataset_requests where id = %s or dataset_requests.properties->>'dac_request_id' = %s", $request_id,$request_id);
        if (!$request) {
            return [
                "status"    => "error",
                "message"   => 'Dataset request not found',
                "exit_code" => 500
            ];
        }
        $db_status = $this->db->queryFirstField("SELECT id  from status_type where id = %s", $params['request_status']);
        if (!$db_status) {
            return [
                "status"    => "error",
                "message"   => 'Request status is not valid',
                "exit_code" => 500
            ];
        }

        $this->db->update("dataset_requests", array("status_type" => $db_status), " id = %s", $request['id']);

        // TODO: log dataset_requests modifications //

        $new_request = $this->db->queryFirstRow("SELECT * from dataset_request_view where request_id = %s_id", $request);
        if ($db_status == 'APR') {
            $test = $this->rabbitmq->requestDownload($new_request);
        } else if ($request['status_type'] == 'APR') {
            $test = $this->rabbitmq->revokeDownload($new_request);
        }
        return [
            "status"    => "success",
            "content"   => $new_request,
            "message"   => "Request updated successfully",
            "exit_code" => 200
        ];
    }

    /**
     * True when the given DAC-portal request id belongs to the calling user.
     * Mirrors the scoping already used in getRequestTokens()/getDatasetRequests() by user_id.
     */
    public function isOwnedByCaller(Keycloak $auth, string $dacRequestId): bool
    {
        $user = $auth->getDetails();
        return (bool) $this->db->queryFirstField(
            "SELECT id FROM dataset_requests WHERE properties->>'dac_request_id' = %s AND user_id = %i",
            $dacRequestId,
            $user['id'] ?? 0
        );
    }

    public function updateRequestStatusByDacId(string $dacRequestId, string $status): void
    {
        $this->db->query(
            "UPDATE dataset_requests SET status_type = %s, action_time = NOW() WHERE properties->>'dac_request_id' = %s",
            $status,
            $dacRequestId
        );
    }

    public function getDatasetRequests(string $datasetId, int $userId): array
    {
        $requests = $this->db->queryFirstRow(
            "SELECT
     			dataset_requests.dataset_id,
     			action_time,
     			status_type.\"name\" as request_status,
     			dataset_requests.properties ->> 'dac_request_id' as dac_request_id
     		FROM
     			dataset_requests
     			inner join status_type on dataset_requests.status_type = status_type.id
     			INNER JOIN (
     				SELECT
     					max(action_time) as last_action_time,
     					dataset_id
     				FROM
     					dataset_requests
     				WHERE
     					user_id = %i_user_id
     				GROUP BY
     					dataset_id
     			) AS last_requests ON dataset_requests.dataset_id = last_requests.dataset_id and dataset_requests.action_time = last_requests.last_action_time
     		WHERE
     			user_id = %i_user_id
     			and dataset_requests.dataset_id = %s_dataset_id
     		ORDER BY
     			action_time;",
            array("user_id" => $userId, "dataset_id" => $datasetId)
        );
        return ($requests) ? $requests : array();
    }

    public function getRequestTokens(Keycloak $auth, string $request_id): array
    {
        if ($auth->isGuest()) {
            return [
                "status"    => "error",
                "message"   => 'Unauthorized',
                "exit_code" => 401
            ];
        }
        $user = $auth->getDetails();
        $user_id = $this->db->queryFirstField("SELECT id from \"user\" where id = %i_id and external_id = %s_preferred_username", $user);
        if (!$user_id) {
            return [
                "status"    => "error",
                "message"   => 'Unauthorized',
                "exit_code" => 401
            ];
        }
        $request = $this->db->queryFirstField("SELECT properties from dataset_requests where id = %s and user_id = %i and status_type = 'APR'", $request_id, $user_id);
        if (!$request) {
            return [
                "status"    => "error",
                "message"   => 'Unknown dataset download request',
                "exit_code" => 401
            ];
        }
        $properties = json_decode($request, true);
        $old_session_token = $properties['session_token'] ?? "";
        $tokens = $this->rabbitmq->refreshRequestTokens($request_id, $user['email']);
        $trials = 0;
        $session_token = $old_session_token;
        while ($session_token === $old_session_token && $trials < 10) {
            usleep(500);
            $trials++;
            $json = $this->db->queryFirstField("SELECT properties from dataset_requests where id = %s", $request_id);
            $properties = json_decode($json, true);
            $session_token = $properties['session_token'] ?? "";
        }
        return [
            "status"    => "success",
            "content"   => $properties,
            "exit_code" => 200
        ];
    }
}
