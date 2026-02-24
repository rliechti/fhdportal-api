<?php

namespace App\Service\Dac;

use App\Service\Auth\Keycloak;
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

    public function __construct(MeekroDB $db, MailerInterface $mailer, SerializerInterface $serializer)
    {
        $this->db = $db;
        $this->mailer = $mailer;
        $this->serializer = $serializer;
    }


    public function sendRequest(Keycloak $auth, string $properties): array
    {
        if ($auth->isGuest()) {
            return [
                "status"    => "error",
                "message"   => 'Unauthorized',
                "exit_code" => 401
            ];
        }

        $user = $auth->getDetails();
        $form = json_decode($properties, true);
        if ($user['name'] !== $form['username']) {
            return [
                "status"    => "error",
                "message"   => 'Unauthorized',
                "exit_code" => 401
            ];
        }
        $resource_id = $this->db->queryFirstField("SELECT id from dataset_view where id = %s and status_type_id = 'PUB' and released_date <= '" . date('Y-m-d') . "'", $form['dataset_id']);
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
        $db_request = array(
            "dataset_id" => $resource_id,
            "user_id" => $user_id,
            "status_type" => "SUB",
            "validator_id" => null,
            "properties" => $properties
        );
        $db_request['id'] = $this->db->queryFirstField("SELECT id from dataset_requests where user_id = %i_user_id and dataset_id = %s_dataset_id", $db_request);
        if ($db_request['id']) {
            return [
                "status"    => "success",
                "message"   => 'Dataset already requested',
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
        $content .= "  Comment: " . $datasetRequest['requester_comment'] . "\r\n\r\n";
        $email = (new Email())
            ->from($_SERVER['NO_REPLY_EMAIL'])
            ->to($_SERVER['GOS_DAC_EMAIL'])
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

        return [
            "status"    => "success",
            "content"   => $requests,
            "message"   => count($requests) . " access requests",
            "exit_code" => 200
        ];
    }

    public function patchRequest(Keycloak $auth, string $request_id, array $params): array
    {
        if ($auth->isGuest() || !$auth->hasRole('admin-fega')) {
            return [
                "status"    => "error",
                "message"   => 'Unauthorized',
                "exit_code" => 401
            ];
        }
        $user = $auth->getDetails();
        $request = $this->db->queryFirstRow("SELECT * from dataset_requests where id = %s", $request_id);
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
        $validator_id = $this->db->queryFirstField("SELECT id from \"user\" where id = %i_id and external_id = %s_preferred_username", $user);
        if (!$validator_id) {
            return [
                "status"    => "error",
                "message"   => 'Reviewer is not valid',
                "exit_code" => 500
            ];
        }
        $request['id'] = Uuid::uuid4();
        $request['validator_id'] = $validator_id;
        $request['status_type'] = $db_status;
        unset($request['action_time']);
        $this->db->insert("dataset_requests", $request);
        $new_request = $this->db->queryFirstRow("SELECT * from dataset_request_view where request_id = %s_id", $request);
        return [
            "status"    => "success",
            "content"   => $new_request,
            "message"   => "Request updated successfully",
            "exit_code" => 200
        ];
    }

    public function getDatasetRequests(string $datasetId, int $userId): array
    {	
		$requests = $this->db->queryFirstRow(
            "SELECT
     			dataset_requests.dataset_id,
     			action_time,
     			status_type.\"name\" as request_status
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

}
