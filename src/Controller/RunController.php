<?php

namespace App\Controller;

use App\Service\Auth\Keycloak;
use OpenApi\Attributes as OA;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api')]
class RunController extends ResourceController
{
    // Define the resource type handled by this controller
    protected function getResourceType(): string
    {
        return 'Run';
    }

    // Define route prefix for URL paths for this resource
    protected function getRoutePrefix(): string
    {
        return 'runs';
    }

    #[Route('/submissions/{study_id}/runs', name: 'get_study_runs', methods: ['GET'])]
    #[OA\Get(
        path: '/api/submissions/{study_id}/runs',
        summary: 'Get runs for a study',
        tags: ['Runs'],
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
                description: 'Returns the runs for the specified study',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(type: 'object'))
            )
        ]
    )]
    public function getStudyRuns(Keycloak $auth, string $study_id): JsonResponse
    {
        return $this->listResources($auth, $study_id, 'edit');
    }

    #[Route('/submissions/{study_id}/runs', name: 'post_run', methods: ['POST'])]
    #[OA\Post(
        path: '/api/submissions/{study_id}/runs',
        summary: 'Create a new run',
        tags: ['Runs'],
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
                description: 'Run created successfully',
                content: new OA\JsonContent(type: 'object')
            ),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 400, description: 'Invalid input')
        ]
    )]
    public function postRun(Request $request, Keycloak $auth, string $study_id, LoggerInterface $logger): JsonResponse
    {
        return $this->postResource($request, $auth, $study_id, $logger);
    }

    #[Route('/submissions/{study_id}/runs/{run_id}', name: 'put_run', methods: ['PUT'])]
    #[OA\Put(
        path: '/api/submissions/{study_id}/runs/{run_id}',
        summary: 'Update a run',
        tags: ['Runs'],
        parameters: [
            new OA\Parameter(
                name: 'study_id',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string')
            ),
            new OA\Parameter(
                name: 'run_id',
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
                description: 'Run updated successfully',
                content: new OA\JsonContent(type: 'object')
            ),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 400, description: 'Invalid input')
        ]
    )]
    public function putRun(Request $request, Keycloak $auth, string $study_id, string $run_id, LoggerInterface $logger): JsonResponse
    {
        return $this->putResource($request, $auth, $study_id, $run_id, $logger);
    }

    #[Route('/submissions/{study_id}/runs/{run_id}', name: 'delete_run', methods: ['DELETE'])]
    #[OA\Delete(
        path: '/api/submissions/{study_id}/runs/{run_id}',
        summary: 'Delete a run',
        tags: ['Runs'],
        parameters: [
            new OA\Parameter(
                name: 'study_id',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string')
            ),
            new OA\Parameter(
                name: 'run_id',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string')
            )
        ],
        responses: [
            new OA\Response(response: 200, description: 'Run deleted successfully'),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 404, description: 'Run not found')
        ]
    )]
    public function deleteRun(Keycloak $auth, string $study_id, string $run_id): JsonResponse
    {
        return $this->deleteResource($auth, $study_id, $run_id);
    }

    #[Route('/submissions/{study_id}/upload-runs', name: 'upload_runs', methods: ['POST'])]
    #[OA\Post(
        path: '/api/submissions/{study_id}/upload-runs',
        summary: 'Upload runs for a study',
        tags: ['Runs'],
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
                description: 'Runs uploaded successfully',
                content: new OA\JsonContent(type: 'object')
            ),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 400, description: 'Invalid input')
        ]
    )]
    public function uploadRuns(Request $request, Keycloak $auth, string $study_id): JsonResponse
    {
        return $this->uploadResources($request, $auth, $study_id);
    }
}
