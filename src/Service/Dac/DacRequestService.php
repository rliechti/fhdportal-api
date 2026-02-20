<?php

namespace App\Service\Dac;

use App\Service\Auth\Keycloak;
use App\Service\RabbitMq\RabbitMq;
use App\Service\Utility\GeneralHelperService;
use MeekroDB;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class DacRequestService
{
    private MeekroDB $db;
    private MailerInterface $mailer;
    private SerializerInterface $serializer;
    private RabbitMq $rabbitmq;
    private HttpClientInterface $httpClient;
    private GeneralHelperService $helper;

    public function __construct(MeekroDB $db, MailerInterface $mailer, SerializerInterface $serializer, RabbitMq $rabbitmq, HttpClientInterface $httpClient, GeneralHelperService $helper)
    {
        $this->db = $db;
        $this->mailer = $mailer;
        $this->serializer = $serializer;
        $this->rabbitmq = $rabbitmq;
        $this->httpClient = $httpClient;
        $this->helper = $helper;
        $this->httpClient->withOptions(
            [
                'verify_peer' => true,
                'verify_host' => true,
            ]
        );
    }

    public function getDac(Keycloak $auth, string $dacId, bool $includeMembers = false): array
    {
        $token = $auth->getBearerToken();
        $dac = [];

        $response = $this->httpClient->request(
            'GET',
            $_SERVER['DAC_API'] . '/dacs/' . $dacId,
            [
                'headers' => [
                    'Content-Type'  => 'application/json',
                    'Authorization' => 'Bearer ' . $token,
                ],
            ]
        );

        $statusCode = $response->getStatusCode();

        if ($statusCode === 200) {
            $content = $response->toArray();

            $dac = [
                'name'        => $content['name'],
                'description' => $content['description'],
            ];

            if ($includeMembers) {
                $dac['members'] = $content['members'];
            }
        } else {
            $content = $response->getContent();
        }

        return $dac;
    }


    public function getDatasetPolicy(Keycloak $auth, string $datasetId): array
    {
        $return = array(
            "status" => "",
            "message" => "",
            "policy" => array(
                "id" => '',
                "submission_id" => '',
                "status" => ''
            ),
            "errors" => ""
        );
        if ($this->helper->checkUuid($datasetId) === false) {
            $datasetId = $this->db->queryFirstField("SELECT id from resource where resource.properties ->> 'public_id' = %s", $datasetId);
        }
        if (!$datasetId) {
            $return['status'] = 'error';
            $return['errors'] = "Unable to get dataset with $datasetId";
            return $return;
        }
        $token = $auth->getBearerToken();
        $response = $this->httpClient->request(
            'GET',
            $_SERVER['DAC_API'] . '/submissions',
            array(
                'headers' => [
                    'Content-Type' => 'application/json',
                    "Authorization" => 'Bearer ' . $token
                ],
                'query' => [
                    "datasetID" => $datasetId
                ]
            )
        );

        $statusCode = $response->getStatusCode();
        if ($statusCode === 200) {
            $return['status'] = 'success';
            $return['message'] = "policy retrived successfully";
            $content = $response->toArray();
            // condition for json-server based fake DAC
            $data = (isset($content['data'])) ? $content['data'] : $content;
            foreach ($data as $d) {
                if (!isset($d['datasetID'])) {
                    $d['datasetID'] = (isset($d['dataset']) && isset($d['dataset']['id'])) ? $d['dataset']['id'] : "";
                }
                if (!isset($d['policyID'])) {
                    $d['policyID'] = (isset($d['policy']) && isset($d['policy']['id'])) ? $d['policy']['id'] : "";
                }
                if ($d['datasetID'] == $datasetId) {
                    $return['policy']['id']  = $d['policyID'];
                    $return['policy']['status'] = $d['status'];
                    $return['policy']['submission_id'] = $d['id'];
                }
            }
        } else {
            $return['status'] = "error";
            $return['message'] = "Unable to get policy from the DAC portal";
            error_log("Error: get DAC dataset ($datasetId) returns a " . $statusCode . " error");
        }
        return $return;
    }
}
