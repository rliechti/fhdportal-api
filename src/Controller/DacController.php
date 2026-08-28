<?php

namespace App\Controller;

use App\Service\Auth\Keycloak;
use App\Service\Dac\DacRequestService;
use App\Service\Dac\DatasetRequestService;
use App\Service\Dac\PolicyService;
use App\Service\Utility\GeneralHelperService;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use stdClass;
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
        if ($auth->isGuest()) {
            return new JsonResponse(['message' => 'Unauthorized'], 401);
        }
        $dacs = [];
        $offset = 0;
        $limit = 99;
        $totalCount = -1;


        $dacs = array();
        $dacs = $this->policyService->fetchPolicies($auth, $offset, $limit, $dacs);

        $json_content = json_encode($dacs);
        return new JsonResponse($json_content, json: true);
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
        if ($auth->isGuest()) {
            return new JsonResponse(['message' => 'Unauthorized'], 401);
        }
        $schemas = $this->policyService->getPolicyForm($auth, $dataset_id, $policy_id, $form);
        $json_schemas = json_encode($schemas);
        return new JsonResponse($json_schemas, json: true);
    }

    #[Route('/dacs/requests', name: 'create_data_request', methods: ['POST'])]
    #[OA\Post(
        path: '/api/dacs/requests',
        summary: 'Create a new data request for a chosen dataset',
        tags: ['DAC'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                type: 'object',
                properties: [
                    new OA\Property(property: 'datasetIDs', type: 'array', items: new OA\Items(type: 'string'))
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Data request created successfully', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 400, description: 'Bad request'),
        ]
    )]
    public function createDataRequest(Keycloak $auth, Request $request, DacRequestService $dac): JsonResponse
    {
        if ($auth->isGuest()) {
            return new JsonResponse(['message' => 'Unauthorized'], 401);
        }
        $body = json_decode($request->getContent(), true);
        $datasetIDs = $body['datasetIDs'] ?? [];

        if (empty($datasetIDs)) {
            return new JsonResponse(['message' => 'datasetIDs is required'], 400);
        }

        $result = $dac->createDataRequest($auth, $datasetIDs);

        if ($result['status'] === 'success') {
            return new JsonResponse($result['content']);
        }

        return new JsonResponse(['message' => $result['message']], $result['code'] ?? 500);
    }

    #[Route('/dacs/requests/{id}/form', name: 'get_data_request_form', methods: ['GET'])]
    #[OA\Get(
        path: '/api/dacs/requests/{id}/form',
        summary: 'Get the JSON form for a data request',
        tags: ['DAC'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Form retrieved successfully', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 404, description: 'Data request not found'),
        ]
    )]
    public function getDataRequestForm(Keycloak $auth, DacRequestService $dac, DatasetRequestService $datasetRequestService, GeneralHelperService $helper, string $id): JsonResponse
    {
        if ($auth->isGuest()) {
            return new JsonResponse(['message' => 'Unauthorized'], 401);
        }
        if (!$helper->checkUuid($id)) {
            return new JsonResponse(['message' => 'Invalid data request id'], 400);
        }
        if (!$auth->isAdmin() && !$datasetRequestService->isOwnedByCaller($auth, $id)) {
            return new JsonResponse(['message' => 'Forbidden'], 403);
        }
        $result = $dac->getDataRequestForm($auth, $id);

        if ($result['status'] === 'success') {
            return new JsonResponse($result['content']);
        }

        return new JsonResponse(['message' => $result['message']], $result['code'] ?? 500);
    }

    #[Route('/dacs/requests/{id}', name: 'edit_data_request', methods: ['PATCH'])]
    #[OA\Patch(
        path: '/api/dacs/requests/{id}',
        summary: 'Edit a data request',
        tags: ['DAC'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                type: 'object',
                properties: [
                    new OA\Property(property: 'attachmentIDs', type: 'array', items: new OA\Items(type: 'string')),
                    new OA\Property(property: 'formValues', type: 'string'),
                    new OA\Property(property: 'status', type: 'string'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Data request updated successfully', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 400, description: 'Bad request'),
        ]
    )]
    public function editDataRequest(Keycloak $auth, Request $request, DacRequestService $dac, DatasetRequestService $datasetRequestService, GeneralHelperService $helper, string $id): JsonResponse
    {
        if ($auth->isGuest()) {
            return new JsonResponse(['message' => 'Unauthorized'], 401);
        }
        if (!$helper->checkUuid($id)) {
            return new JsonResponse(['message' => 'Invalid data request id'], 400);
        }
        if (!$auth->isAdmin() && !$datasetRequestService->isOwnedByCaller($auth, $id)) {
            return new JsonResponse(['message' => 'Forbidden'], 403);
        }

        $payload = json_decode($request->getContent(), true) ?? [];
        $formValues      = $payload['formValues'] ?? null;
        $status          = $payload['status'] ?? null;
        $attachments     = $payload['attachments'] ?? [];     // inline text/markdown objects
        $attachmentFiles = $payload['attachmentFiles'] ?? []; // binary files as base64
        $datasetId       = $payload['dataset_id'] ?? null;

        $attachmentIDs = [];

        // Upload binary attachments and collect their IDs.
        // Content is forwarded straight to the DAC portal; it is never staged on local disk
        // (that local write served no purpose and was the vector behind security audit C-3).
        $signedDaaUploaded = false;
        if (!empty($attachmentFiles)) {
            foreach ($attachmentFiles as $fileData) {
                $iri         = $fileData['iri'] ?? '';
                $fileName    = basename($fileData['fileName'] ?? 'attachment.pdf');
                $mimeType    = $fileData['mimeType'] ?? 'application/pdf';
                $fileContent = base64_decode($fileData['fileContent'] ?? '', true);
                if ($fileContent === false || empty($fileName) || empty($iri)) {
                    return new JsonResponse(['message' => 'Invalid attachment data for file: ' . $fileName], 400);
                }

                $uploadResult = $dac->uploadDataRequestAttachment($auth, $id, $fileContent, $fileName, $iri, $mimeType);
                if ($uploadResult['status'] !== 'success') {
                    return new JsonResponse(['message' => 'Failed to upload attachment: ' . $fileName], $uploadResult['code'] ?? 500);
                }

                $attachmentIDs[] = $uploadResult['content']['id'];

                if ($iri === 'https://ontology.swisscustodian.ch/schema/SignedDAA') {
                    $signedDaaUploaded = true;
                }
            }
        }

        $dacPayload = [];

        if ($formValues !== null) {
            $dacPayload['formValues'] = $this->stripUploadMarkdownText($formValues);
            if (!empty($attachments) || !empty($attachmentIDs)) {
                $existingBinaryIDs = !empty($attachments)
                    ? $this->getExistingBinaryAttachmentIDs($dac, $auth, $id)
                    : [];
                $dacPayload['attachmentIDs'] = array_values(array_unique(array_merge($existingBinaryIDs, $attachmentIDs)));
            }

            if (!empty($attachments)) {
                $dacPayload['attachments'] = $attachments;
            }
        }
        else {
            $dacPayload = json_decode('{"status":null,"attachmentIDs":null,"attachments":null,"formValues":{}}',true);
        }
        $result = $dac->editDataRequest($auth, $id, $dacPayload);

        if ($result['status'] !== 'success') {
            return new JsonResponse(['message' => $result['message']], $result['code'] ?? 500);
        }

        if ($status === 'pending' && $datasetId) {
            $user       = $auth->getDetails();
            $properties = json_encode([
                'username'   => $user['name'] ?? ($user['preferred_username'] ?? ''),
                'dataset_id' => $datasetId,
                'comment'    => '',
            ]);
            $datasetRequestService->sendRequest($auth, $properties, $id);
        }

        if ($signedDaaUploaded) {
            $datasetRequestService->updateRequestStatusByDacId($id, 'SUB');
        }

        return new JsonResponse($result['content']);
    }

    #[Route('/dacs/requests/{id}/daa', name: 'download_data_request_daa', methods: ['GET'])]
    #[OA\Get(
        path: '/api/dacs/requests/{id}/daa',
        summary: 'Download the DAA (PDF) for a data request',
        tags: ['DAC'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'DAA PDF', content: new OA\MediaType(mediaType: 'application/pdf', schema: new OA\Schema(type: 'string', format: 'binary'))),
            new OA\Response(response: 404, description: 'Data request not found'),
        ]
    )]
    public function downloadDataRequestDaa(Keycloak $auth, DacRequestService $dac, DatasetRequestService $datasetRequestService, GeneralHelperService $helper, string $id): Response
    {
        if ($auth->isGuest()) {
            return new JsonResponse(['message' => 'Unauthorized'], 401);
        }
        if (!$helper->checkUuid($id)) {
            return new JsonResponse(['message' => 'Invalid data request id'], 400);
        }
        if (!$auth->isAdmin() && !$datasetRequestService->isOwnedByCaller($auth, $id)) {
            return new JsonResponse(['message' => 'Forbidden'], 403);
        }
        $result = $dac->downloadDataRequestDaa($auth, $id);

        if ($result['status'] === 'success') {
            return new Response(
                $result['content'],
                200,
                [
                    'Content-Type'        => $result['content_type'],
                    'Content-Disposition' => $result['disposition'],
                ]
            );
        }

        return new JsonResponse(['message' => $result['message']], $result['code'] ?? 500);
    }

    private function stripUploadMarkdownText(array $values): array
    {
        $result = [];
        foreach ($values as $key => $value) {
            if (is_array($value)) {
                if ($key === 'upload-markdown') {
                    unset($value['text']);
                } else {
                    $value = $this->stripUploadMarkdownText($value);
                    if ($value === []) {
                        continue;
                    }
                }
            }
            $result[$key] = $value;
        }
        return $result;
    }

    private function getExistingBinaryAttachmentIDs(DacRequestService $dac, Keycloak $auth, string $dataRequestId): array
    {
        $formResult = $dac->getDataRequestForm($auth, $dataRequestId);
        if ($formResult['status'] !== 'success') {
            return [];
        }
        $initialValues = $formResult['content']['jsonForms']['initialValues'] ?? [];
        return $this->extractBinaryAttachmentIDs($initialValues);
    }

    private function extractBinaryAttachmentIDs(array $values): array
    {
        $ids = [];
        foreach ($values as $key => $value) {
            if (!is_array($value)) {
                continue;
            }
            if ($key === 'existing-attachments') {
                foreach ($value as $attachNode) {
                    if (is_array($attachNode) && isset($attachNode['id']) && !isset($attachNode['content'])) {
                        $ids[] = $attachNode['id'];
                    }
                }
            } else {
                $ids = array_merge($ids, $this->extractBinaryAttachmentIDs($value));
            }
        }
        return $ids;
    }

}
