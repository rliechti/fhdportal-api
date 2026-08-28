<?php

namespace App\Controller;

use App\Service\Auth\Keycloak;
use OpenApi\Attributes as OA;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api')]
class SampleController extends ResourceController
{
    // Define the resource type handled by this controller
    protected function getResourceType(): string
    {
        return 'Sample';
    }

    // Define route prefix for URL paths for this resource
    protected function getRoutePrefix(): string
    {
        return 'samples';
    }

    #[Route('/submissions/{study_id}/samples', name: 'get_study_samples', methods: ['GET'])]
    #[OA\Get(
        path: '/api/submissions/{study_id}/samples',
        summary: 'Get samples for a study',
        tags: ['Samples'],
        parameters: [
            new OA\Parameter(
                name: 'study_id',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string')
            )
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Returns the samples for the specified study',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(type: 'object'))
            )
        ]
    )]
    public function getStudySamples(Keycloak $auth, string $study_id): JsonResponse
    {
        return $this->listResources($auth, $study_id, 'edit');
    }

    #[Route('/submissions/{study_id}/samples', name: 'post_sample', methods: ['POST'])]
    #[OA\Post(
        path: '/api/submissions/{study_id}/samples',
        summary: 'Create a new sample',
        tags: ['Samples'],
        parameters: [
            new OA\Parameter(
                name: 'study_id',
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
                description: 'Sample created successfully',
                content: new OA\JsonContent(type: 'object')
            ),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 400, description: 'Invalid input')
        ]
    )]
    public function postSample(Request $request, Keycloak $auth, string $study_id, LoggerInterface $logger): JsonResponse
    {
        return $this->postResource($request, $auth, $study_id, $logger);
    }

    #[Route('/submissions/{study_id}/samples/{sample_id}', name: 'put_sample', methods: ['PUT'])]
    #[OA\Put(
        path: '/api/submissions/{study_id}/samples/{sample_id}',
        summary: 'Update a sample',
        tags: ['Samples'],
        parameters: [
            new OA\Parameter(
                name: 'study_id',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string')
            ),
            new OA\Parameter(
                name: 'sample_id',
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
                description: 'Sample updated successfully',
                content: new OA\JsonContent(type: 'object')
            ),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 400, description: 'Invalid input')
        ]
    )]
    public function putSample(Request $request, Keycloak $auth, string $study_id, string $sample_id, LoggerInterface $logger): JsonResponse
    {
        return $this->putResource($request, $auth, $study_id, $sample_id, $logger);
    }

    #[Route('/submissions/{study_id}/samples/{sample_id}', name: 'delete_sample', methods: ['DELETE'])]
    #[OA\Delete(
        path: '/api/submissions/{study_id}/samples/{sample_id}',
        summary: 'Delete a sample',
        tags: ['Samples'],
        parameters: [
            new OA\Parameter(
                name: 'study_id',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string')
            ),
            new OA\Parameter(
                name: 'sample_id',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string')
            )
        ],
        responses: [
            new OA\Response(response: 200, description: 'Sample deleted successfully'),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 404, description: 'Sample not found')
        ]
    )]
    public function deleteSample(Keycloak $auth, string $study_id, string $sample_id): JsonResponse
    {
        return $this->deleteResource($auth, $study_id, $sample_id);
    }

    #[Route('/submissions/{study_id}/upload-samples', name: 'upload_samples', methods: ['POST'])]
    #[OA\Post(
        path: '/api/submissions/{study_id}/upload-samples',
        summary: 'Upload samples for a study',
        tags: ['Samples'],
        parameters: [
            new OA\Parameter(
                name: 'study_id',
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
                description: 'Samples uploaded successfully',
                content: new OA\JsonContent(type: 'object')
            ),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 400, description: 'Invalid input')
        ]
    )]
    public function uploadSamples(Request $request, Keycloak $auth, string $study_id): JsonResponse
    {
        return $this->uploadResources($request, $auth, $study_id);
    }
}
