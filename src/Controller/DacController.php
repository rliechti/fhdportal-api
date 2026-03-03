<?php

namespace App\Controller;

use App\Service\Auth\Keycloak;
use App\Service\Dac\DatasetRequestService;
use App\Service\Dac\PolicyService;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[Route('/api')]
class DacController extends AbstractController
{
    public function __construct(
        private SerializerInterface $serializer,
        private PolicyService $policyService
    ) {}

    #[Route('/dacs', name: 'get_dacs', methods: ['GET'])]
    #[OA\Get(
        path: '/api/dacs',
        summary: 'Get all DACs and policies',
        tags: ['DAC'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Successful response',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'title', type: 'string'),
                        new OA\Property(property: 'description', type: 'string'),
                        new OA\Property(property: 'dac_title', type: 'string'),
                        new OA\Property(property: 'dac_description', type: 'string'),
                        new OA\Property(property: 'policies', type: 'object')
                    ]
                )
            )
        ]
    )]
    public function getDacs(Keycloak $auth, HttpClientInterface $http): JsonResponse
    {
        $dacs = [];
        $offset = 0;
        $limit = 99;
        $totalCount = -1;


        $dacs = array();
        $dacs = $this->policyService->fetchPolicies($auth, $offset, $limit, $dacs);

        $json_content = json_encode($dacs);
        return new JsonResponse($json_content, json: true);
    }

    #[Route('/dacs/{dataset_id}/request-form', name: 'get_dataset_request_form_schemas', methods: ['GET'])]
    #[OA\Get(
        path: '/api/dacs/{dataset_id}/request-form',
        summary: 'Get Dataset Request form schemas from DAC',
        tags: ['DAC'],
        parameters: [
            new OA\Parameter(
                name: 'dataset_id',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string')
            )
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Form schemas retrieved successfully',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'formSchema', type: 'object'),
                        new OA\Property(property: 'uiSchema', type: 'object'),
                        new OA\Property(property: 'formData', type: 'object')
                    ]
                )
            )
        ]
    )]
    public function getDatasetRequestFormSchemas(Keycloak $auth, string $dataset_id): JsonResponse
    {
        $schemas = $this->policyService->getDataRequestFormSchemas($auth, $dataset_id);
        $json_schemas = json_encode($schemas);
        return new JsonResponse($json_schemas, json: true);
    }

    #[Route('/dacs/{dataset_id}/policies/{policy_id}/{form}', name: 'get_policy_form', methods: ['GET'])]
    #[OA\Get(
        path: '/api/dacs/{dataset_id}/policies/{policy_id}/{form}',
        summary: 'Get Policy DAA or DGA form',
        tags: ['DAC'],
        parameters: [
            new OA\Parameter(
                name: 'dataset_id',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string')
            ),
            new OA\Parameter(
                name: 'policy_id',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string')
            ),
            new OA\Parameter(
                name: 'form',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string')
            )
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Form schemas retrieved successfully',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'formSchema', type: 'object'),
                        new OA\Property(property: 'uiSchema', type: 'object'),
                        new OA\Property(property: 'formData', type: 'object')
                    ]
                )
            )
        ]
    )]
    public function getPolicyForm(Keycloak $auth, string $dataset_id, string $policy_id, string $form): JsonResponse
    {
        $schemas = $this->policyService->getPolicyForm($auth, $dataset_id, $policy_id, $form);
        $json_schemas = json_encode($schemas);
        return new JsonResponse($json_schemas, json: true);
    }

    #[Route('/dacs/{dataset_id}/request', name: 'post_dataset_request', methods: ['POST'])]
    #[OA\Post(
        path: '/api/dacs/{dataset_id}/request',
        summary: 'Submit dataset request',
        tags: ['DAC'],
        parameters: [
            new OA\Parameter(
                name: 'dataset_id',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string')
            )
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(type: 'object')
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Request submitted successfully',
                content: new OA\JsonContent(type: 'object')
            )
        ]
    )]
    public function postDatasetRequest(Keycloak $auth, Request $request, DatasetRequestService $dac, string $dataset_id): JsonResponse
    {
        $content = $request->getContent();
        $result = $dac->sendRequest($auth, $content);
        return new JsonResponse([$result['message']], $result['exit_code']);
    }
}
