<?php

namespace App\Service\Dac;

use App\Service\Auth\Keycloak;
use App\Service\Dac\DacRequestService;
use App\Service\RabbitMq\RabbitMqInterface;
use App\Service\Utility\GeneralHelperService;
use MeekroDB;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use App\Service\Resource\ResourceRelationshipService;

class PolicyService
{
    private MeekroDB $db;
    private MailerInterface $mailer;
    private SerializerInterface $serializer;
    private RabbitMqInterface $rabbitmq;
    private HttpClientInterface $httpClient;
    private GeneralHelperService $helper;
    private DacRequestService $dac;
    private ValidatorInterface $validator;
    private ResourceRelationshipService $relationshipService;

    public function __construct(
        MeekroDB $db,
        MailerInterface $mailer,
        SerializerInterface $serializer,
        RabbitMqInterface $rabbitmq,
        HttpClientInterface $httpClient,
        GeneralHelperService $helper,
        DacRequestService $dac,
		ResourceRelationshipService $relationshipService,
        ValidatorInterface $validator
    ) {
        $this->db = $db;
        $this->mailer = $mailer;
        $this->serializer = $serializer;
        $this->rabbitmq = $rabbitmq;
        $this->httpClient = $httpClient;
        $this->helper = $helper;
        $this->dac = $dac;
        $this->relationshipService = $relationshipService;
        $this->validator = $validator;
        $this->httpClient->withOptions([
            'verify_peer' => true,
            'verify_host' => true,
        ]);
    }


    public function getDatasetPolicy(Keycloak $auth, string $datasetId): array
    {

        if ($this->helper->checkUuid($datasetId) === false) {
            $datasetId = $this->db->queryFirstField(
                "SELECT id FROM resource WHERE resource.properties->>'public_id' = %s",
                $datasetId
            );
        }

        if (!$datasetId) {
            return array(
                "success" => false,
                "error"   => 'unknown dataset: ' . $datasetId,
                "message" => 'unknown dataset: ' . $datasetId,
                "status"  => 500
            );
        }

        $policy = [
            'id'           => '',
            'submission_id' => '',
            'status'       => '',
        ];

        $token = $auth->getBearerToken();

        $response = $this->httpClient->request(
            'GET',
            $_ENV['DAC_API'] . '/submissions',
            [
                'headers' => [
                    'Content-Type'  => 'application/json',
                    'Authorization' => 'Bearer ' . $token,
                ],
                'query' => [
                    'datasetID' => $datasetId,
                ],
            ]
        );

        $statusCode = $response->getStatusCode();

        if ($statusCode === 200) {
            $content = $response->toArray();
            $data = isset($content['data']) ? $content['data'] : $content;

            foreach ($data as $d) {
                $d['datasetID'] ??= isset($d['dataset']['id']) ? $d['dataset']['id'] : '';
                $d['policyID'] ??= isset($d['policy']['id']) ? $d['policy']['id'] : '';

                if ($d['datasetID'] === $datasetId) {
                    $policy['id'] = $d['policyID'];
                    $policy['status'] = $d['status'];
                    $policy['submission_id'] = $d['id'];
                }
            }
        } else {
            error_log("Error: get DAC dataset ({$datasetId}) returns a {$statusCode} error");
        }

        return array(
            "success" => true,
            "content" => $policy
        );
    }

    public function fetchPolicies(
        Keycloak $auth,
        int $offset,
        int $limit,
        array $dacs
    ): array {
        $token = $auth->getBearerToken();

        $response = $this->httpClient->request(
            'GET',
            $_ENV['DAC_API'] . '/policies',
            [
                'headers' => [
                    'Content-Type'  => 'application/json',
                    'Authorization' => 'Bearer ' . $token,
                ],
                'query' => [
                    'limit'  => $limit,
                    'offset' => $offset,
                ],
            ]
        );

        $statusCode = $response->getStatusCode();

        if ($statusCode === 200) {
            $content = $response->toArray();
            $policies = isset($content['data']) ? $content['data'] : $content;

            foreach ($policies as $p) {
                if (!isset($dacs[$p['dacID']])) {
                    $dacResponse = $this->httpClient->request(
                        'GET',
                        $_ENV['DAC_API'] . '/dacs/' . $p['dacID'],
                        [
                            'headers' => [
                                'Content-Type'  => 'application/json',
                                'Authorization' => 'Bearer ' . $token,
                            ],
                        ]
                    );

                    $dac = $dacResponse->toArray();
                    $dacs[$p['dacID']] = [
                        'name'        => $dac['name'],
                        'description' => $dac['description'],
                        'policies'    => [],
                    ];
                }

                $dacs[$p['dacID']]['policies'][] = $p;
            }

            // Recursive pagination for json-server fake DAC
            if (
                isset($content['pagination'])
                && $content['pagination']['totalCount'] > $content['pagination']['offset'] + $content['pagination']['limit']
                && count($content['data'] ?? [])
            ) {
                $nextOffset = $offset + $limit + 1;
                $dacs = $this->fetchPolicies($auth, $nextOffset, $limit, $dacs);
            }
        } else {
            error_log("ERROR: {$statusCode} when fetchPolicies");
        }

        return $dacs;
    }


    public function getPolicy(Keycloak $auth, string $policyId, bool $includeMembers = false): array
    {

        $token = $auth->getBearerToken();
        $policy = [];

        $response = $this->httpClient->request(
            'GET',
            $_ENV['DAC_API'] . '/policies/' . $policyId,
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

            $policy = [
                'title'       => $content['title'],
                'url'         => $content['url'],
                'dac_id'      => $content['dacID'],
                'description' => $content['description'],
            ];

            $policy['dac'] = $this->dac->getDac($auth, $content['dacID'], $includeMembers);
        } else {
            error_log($statusCode);
            $content = $response->getContent();
            error_log($content);
        }

        return $policy;
    }


    public function registerDatasetPolicy(Keycloak $auth, string $datasetId, string $policyId): array
    {
        $policyPublicId = $this->db->queryFirstField(
            "SELECT resource.properties->>'public_id' AS policy_public_id
             FROM resource
             INNER JOIN resource_type ON resource.resource_type_id = resource_type.id
             WHERE resource_type.name = 'Policy'
               AND resource.id = %s",
            $policyId
        );

        if (!$policyPublicId) {
            $dacPolicy = $this->getPolicy($auth, $policyId);

            if (isset($dacPolicy['id'])) {
                unset($dacPolicy['id']);
            }

            if (isset($dacPolicy['dac'])) {
                unset($dacPolicy['dac']);
            }

            if (isset($dacPolicy['title'])) {

                // Retrieve the JSON schema from the resource type
                $jsonSchemas = $this->db->queryFirstField(
                    'SELECT resource_type.properties
                     FROM resource_type
                     WHERE resource_type."name" = \'Policy\''
                );

                $schemas = json_decode($jsonSchemas);

                // Validation commented out in original code
                // $validationErrors = $validator->validate((object) $dacPolicy, $schemas->data_schema);
                // if (!empty($validationErrors)) {
                //     return new JsonResponse([
                //         'message' => 'Validation failed',
                //         'errors' => $validationErrors,
                //     ], 400);
                // }

                $resource = [
                    'id'               => $policyId,
                    'properties'       => json_encode($dacPolicy),
                    'resource_type_id' => $this->db->queryFirstField(
                        'SELECT id FROM resource_type WHERE "name" = \'Policy\''
                    ),
                    'status_type_id'   => 'PUB',
                ];

                $this->db->insert('resource', $resource);

                $policyPublicId = $this->db->queryFirstField(
                    "SELECT resource.properties->>'public_id' AS policy_public_id
                     FROM resource
                     INNER JOIN resource_type ON resource.resource_type_id = resource_type.id
                     WHERE resource_type.name = 'Policy'
                       AND resource.id = %s",
                    $policyId
                );
            } else {
                return array(
                    "success" => false,
                    "error" => 'Error: unable to get policy',
                    "status" => 500
                );
            }
        }
		

		// TODO : use ResourceRelationshipService
		$user = $auth->getDetails();
		$relation_id = $this->relationshipService->createRelationship('Dataset','Policy',$datasetId, $policyId,$user['id'],false);
		if(!$relation_id){
			return array(
				"success" => false,
				"error" => 'Error: unable to register the link between Dataset and Policy',
				"status" => 500
			);
		}
        // $relationshipId = $this->db->queryFirstField(
     //        'SELECT id
     //         FROM relationship
     //         WHERE domain_resource_id = %s
     //           AND range_resource_id = %s',
     //        $datasetId,
     //        $policyId
     //    );
     //
     //    if (!$relationshipId) {
     //        $uuid = Uuid::uuid4();
     //        $relationshipId = $uuid->toString();
     //
     //        $relationshipRuleId = $this->db->queryFirstField(
     //            'SELECT id
     //             FROM relationship_rule_view
     //             WHERE domain_type_name = \'Dataset\'
     //               AND range_type_name = \'Policy\''
     //        );
     //
     //        if ($relationshipRuleId) {
     //            $relationship = [
     //                'id'                 => $relationshipId,
     //                'relationship_rule_id' => $relationshipRuleId,
     //                'domain_resource_id' => $datasetId,
     //                'predicate_id'       => 1,
     //                'range_resource_id'  => $policyId,
     //                'sequence_number'    => 1,
     //                'is_active'          => true,
     //            ];
     //
     //            $this->db->insert('relationship', $relationship);
     //
     //            $relationshipId = $this->db->queryFirstField(
     //                'SELECT id
     //                 FROM relationship
     //                 WHERE domain_resource_id = %s
     //                   AND range_resource_id = %s',
     //                $datasetId,
     //                $policyId
     //            );
     //        } else {
     //            return array(
     //                "success" => false,
     //                "error" => 'Error: unable to register the link between Dataset and Policy',
     //                "status" => 500
     //            );
     //        }
     //    }

        $token = $auth->getBearerToken();
        $dacSubmission = $this->getDatasetPolicy($auth, $datasetId);

        $studyId = $this->db->queryFirstField(
            'SELECT dataset_view.study_id
             FROM dataset_view
             WHERE dataset_view.id = %s',
            $datasetId
        );

        if (!$dacSubmission['success'] || !isset($dacSubmission['content']['id']) || !$dacSubmission['content']['id']) {
            $submissionBody = [
                'datasetID' => $datasetId,
                'externalID' => $studyId,
                'policyID' => $policyId,
            ];

            $response = $this->httpClient->request(
                'POST',
                $_ENV['DAC_API'] . '/submissions',
                [
                    'headers' => [
                        'Content-Type'  => 'application/json',
                        'Authorization' => 'Bearer ' . $token,
                    ],
                    'body' => json_encode($submissionBody),
                ]
            );

            $statusCode = $response->getStatusCode();

            if ($statusCode < 200 || $statusCode >= 300) {
                return array(
                    'success' => false,
                    "error"   => $response->toArray(),
                    "status"  => $statusCode
                );
            }
        }

        return [
            'success'        => true,
            'dataset_id'     => $datasetId,
            'relationship_id' => $relationshipId,
            'policy_id'      => $policyId,
        ];
    }

    public function getPolicyForm(
        Keycloak $auth,
        string $datasetId,
        string $policyId,
        string $form
    ): array {

        $token = $auth->getBearerToken();

        $response = $this->httpClient->request(
            'GET',
            $_ENV['DAC_API'] . '/policies/' . $policyId . '/' . $form,
            [
                'headers' => [
                    'Content-Type'  => 'application/json',
                    'Authorization' => 'Bearer ' . $token,
                ],
                'query' => [
                    'format' => 'jsonform',
                ],
            ]
        );

        $statusCode = $response->getStatusCode();

        if ($statusCode === 200) {
            return $response->toArray();
        }

        return [];
    }

    public function getDataRequestFormSchemas(
        Keycloak $auth,
        string $datasetId
    ): array {
        $field = $this->helper->checkUuid($datasetId) ? 'domain_resource_id' : 'domain_public_id';

        $policyId = $this->db->queryFirstField(
            "SELECT range_resource_id
             FROM relationship_view
             WHERE range_type = 'Policy'
               AND domain_type = 'Dataset'
               AND $field = %s
               AND is_active = TRUE",
            $datasetId
        );

        if (!$policyId) {
            throw new \Exception('No policy attached to this dataset', 500);
        }

        return $this->getPolicyForm($auth, $datasetId, $policyId, 'daa-form');
    }
}
