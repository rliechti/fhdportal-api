<?php

namespace App\Service\Dac;

use App\Service\Auth\Keycloak;
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
    private HttpClientInterface $httpClient;
    private GeneralHelperService $helper;

    public function __construct(MeekroDB $db, MailerInterface $mailer, SerializerInterface $serializer, HttpClientInterface $httpClient, GeneralHelperService $helper)
    {
        $this->db = $db;
        $this->mailer = $mailer;
        $this->serializer = $serializer;
        $this->helper = $helper;
        // Always verify. For local development, trust a mounted dev CA bundle
        // instead of disabling verification (security audit M-4).
        $options = ['verify_peer' => true, 'verify_host' => true];
        if (!empty($_ENV['DEV_CA_BUNDLE'])) {
            $options['cafile'] = $_ENV['DEV_CA_BUNDLE'];
        }
        $this->httpClient = $httpClient->withOptions($options);
    }

    public function getDac(Keycloak $auth, string $dacId, bool $includeMembers = false): array
    {
        $token = $auth->getBearerToken();
        $dac = [];

        $response = $this->httpClient->request(
            'GET',
            $_ENV['DAC_API'] . '/dacs/' . rawurlencode($dacId),
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
            $_ENV['DAC_API'] . '/submissions',
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
            $return['message'] = "policy retrieved successfully";
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

    public function createDataRequest(Keycloak $auth, array $datasetIDs): array
    {
        $token = $auth->getBearerToken();

        // Resolve public IDs to UUIDs
        $resolvedIDs = [];
        foreach ($datasetIDs as $id) {
            if ($this->helper->checkUuid($id) === false) {
                $uuid = $this->db->queryFirstField("SELECT id from resource where resource.properties ->> 'public_id' = %s", $id);
                if (!$uuid) {
                    return [
                        'status'  => 'error',
                        'message' => 'Dataset not found: ' . $id,
                        'code'    => 404,
                    ];
                }
                $resolvedIDs[] = $uuid;
            } else {
                $resolvedIDs[] = $id;
            }
        }

        $response = $this->httpClient->request(
            'POST',
            $_ENV['DAC_API'] . '/datarequests',
            [
                'headers' => [
                    'Content-Type'  => 'application/json',
                    'Authorization' => 'Bearer ' . $token,
                ],
                'json' => ['datasetIDs' => $resolvedIDs],
            ]
        );
        $statusCode = $response->getStatusCode();
        if ($statusCode === 200) {
            return [
                'status'  => 'success',
                'content' => $response->toArray(),
            ];
        }

        error_log("Error: createDataRequest returns a " . $statusCode . " error");
        return [
            'status'  => 'error',
            'message' => 'Unable to create data request',
            'code'    => $statusCode,
        ];
    }

    public function getDataRequestForm(Keycloak $auth, string $dataRequestId): array
    {
        $token = $auth->getBearerToken();

        $response = $this->httpClient->request(
            'GET',
            $_ENV['DAC_API'] . '/datarequests/' . rawurlencode($dataRequestId) . '/requester-form',
            [
                'headers' => [
                    'Content-Type'  => 'application/json',
                    'Authorization' => 'Bearer ' . $token,
                ],
            ]
        );
        $statusCode = $response->getStatusCode();
        if ($statusCode === 200) {
            return [
                'status'  => 'success',
                'content' => $response->toArray(),
            ];
        }

        error_log("Error: getDataRequestForm ($dataRequestId) returns a " . $statusCode . " error");
        return [
            'status'  => 'error',
            'message' => 'Unable to get data request form',
            'code'    => $statusCode,
        ];
    }

    public function uploadDataRequestAttachment(Keycloak $auth, string $dataRequestId, string $fileContent, string $fileName, string $iri, string $contentType = 'application/pdf'): array
    {
        $token = $auth->getBearerToken();
        $response = $this->httpClient->request(
            'POST',
            $_ENV['DAC_API'] . '/datarequests/' . rawurlencode($dataRequestId) . '/attachments',
            [
                'headers' => [
                    'Content-Type'  => $contentType,
                    'Authorization' => 'Bearer ' . $token,
                ],
                'query' => [
                    'fileName' => $fileName,
                    'iri'      => $iri,
                ],
                'body' => $fileContent,
            ]
        );
        $statusCode = $response->getStatusCode();
        if ($statusCode === 200) {
            return [
                'status'  => 'success',
                'content' => $response->toArray(),
            ];
        }

        error_log("Error: uploadDataRequestAttachment ($dataRequestId) returns a " . $statusCode . " error");
        return [
            'status'  => 'error',
            'message' => 'Unable to upload attachment',
            'code'    => $statusCode,
        ];
    }

    public function editDataRequest(Keycloak $auth, string $dataRequestId, array $payload): array
    {
        $token = $auth->getBearerToken();        
        $response = $this->httpClient->request(
            'PATCH',
            $_ENV['DAC_API'] . '/datarequests/' . rawurlencode($dataRequestId),
            [
                'headers' => [
                    'Content-Type'  => 'application/json',
                    'Authorization' => 'Bearer ' . $token,
                ],
                'body' => json_encode($payload ?: new \stdClass(),JSON_UNESCAPED_SLASHES)
            ]
        );
        $statusCode = $response->getStatusCode();
        if ($statusCode === 200) {
            return [
                'status'  => 'success',
                'content' => $response->toArray(),
            ];
        }

        $errorBody = $response->getContent(false);
        return [
            'status'  => 'error',
            'message' => 'Unable to edit data request',
            'code'    => $statusCode,
            'detail'  => $errorBody,
        ];
    }

    public function downloadDataRequestDaa(Keycloak $auth, string $dataRequestId): array
    {
        $token = $auth->getBearerToken();

        $response = $this->httpClient->request(
            'GET',
            $_ENV['DAC_API'] . '/datarequests/' . rawurlencode($dataRequestId) . '/daa',
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                ],
            ]
        );
        $statusCode = $response->getStatusCode();
        if ($statusCode === 200) {
            return [
                'status'       => 'success',
                'content'      => $response->getContent(),
                'content_type' => $response->getHeaders()['content-type'][0] ?? 'application/pdf',
                'disposition'  => $response->getHeaders()['content-disposition'][0] ?? 'attachment; filename="daa.pdf"',
            ];
        }

        error_log("Error: downloadDataRequestDaa ($dataRequestId) returns a " . $statusCode . " error");
        return [
            'status'  => 'error',
            'message' => 'Unable to download DAA',
            'code'    => $statusCode,
        ];
    }
}
