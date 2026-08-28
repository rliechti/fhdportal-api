<?php

namespace App\Controller;

use App\Service\Auth\Keycloak;
use OpenApi\Attributes as OA;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api')]
class ExperimentController extends ResourceController
{
    // Define the resource type handled by this controller
    protected function getResourceType(): string
    {
        return 'Experiment';
    }

    // Define route prefix for URL paths for this resource
    protected function getRoutePrefix(): string
    {
        return 'experiments';
    }

    #[Route('/submissions/{study_id}/experiments', name: 'get_study_experiments', methods: ['GET'])]
    #[OA\Get(
        path: '/api/submissions/{study_id}/experiments',
        summary: 'Get experiments for a study',
        tags: ['Experiments'],
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
                description: 'Returns the experiments for the specified study',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(type: 'object'))
            )
        ]
    )]
    public function getStudyExperiments(Keycloak $auth, string $study_id): JsonResponse
    {
        return $this->listResources($auth, $study_id, 'edit');
    }

    #[Route('/submissions/{study_id}/experiments', name: 'post_experiment', methods: ['POST'])]
    #[OA\Post(
        path: '/api/submissions/{study_id}/experiments',
        summary: 'Create a new experiment',
        tags: ['Experiments'],
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
                description: 'Experiment created successfully',
                content: new OA\JsonContent(type: 'object')
            ),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 400, description: 'Invalid input')
        ]
    )]
    public function postExperiment(Request $request, Keycloak $auth, string $study_id, LoggerInterface $logger): JsonResponse
    {
        return $this->postResource($request, $auth, $study_id, $logger);
    }

    #[Route('/submissions/{study_id}/experiments/{experiment_id}', name: 'put_experiment', methods: ['PUT'])]
    #[OA\Put(
        path: '/api/submissions/{study_id}/experiments/{experiment_id}',
        summary: 'Update an experiment',
        tags: ['Experiments'],
        parameters: [
            new OA\Parameter(
                name: 'study_id',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string')
            ),
            new OA\Parameter(
                name: 'experiment_id',
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
                description: 'Experiment updated successfully',
                content: new OA\JsonContent(type: 'object')
            ),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 400, description: 'Invalid input')
        ]
    )]
    public function putExperiment(Request $request, Keycloak $auth, string $study_id, string $experiment_id, LoggerInterface $logger): JsonResponse
    {
        return $this->putResource($request, $auth, $study_id, $experiment_id, $logger);
    }

    #[Route('/submissions/{study_id}/experiments/{experiment_id}', name: 'delete_experiment', methods: ['DELETE'])]
    #[OA\Delete(
        path: '/api/submissions/{study_id}/experiments/{experiment_id}',
        summary: 'Delete an experiment',
        tags: ['Experiments'],
        parameters: [
            new OA\Parameter(
                name: 'study_id',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string')
            ),
            new OA\Parameter(
                name: 'experiment_id',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string')
            )
        ],
        responses: [
            new OA\Response(response: 200, description: 'Experiment deleted successfully'),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 404, description: 'Experiment not found')
        ]
    )]
    public function deleteExperiment(Keycloak $auth, string $study_id, string $experiment_id): JsonResponse
    {
        return $this->deleteResource($auth, $study_id, $experiment_id);
    }

    #[Route('/submissions/{study_id}/upload-experiments', name: 'upload_experiments', methods: ['POST'])]
    #[OA\Post(
        path: '/api/submissions/{study_id}/upload-experiments',
        summary: 'Upload experiments for a study',
        tags: ['Experiments'],
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
                description: 'Experiments uploaded successfully',
                content: new OA\JsonContent(type: 'object')
            ),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 400, description: 'Invalid input')
        ]
    )]
    public function uploadExperiments(Request $request, Keycloak $auth, string $study_id): JsonResponse
    {
        return $this->uploadResources($request, $auth, $study_id);
    }
}
