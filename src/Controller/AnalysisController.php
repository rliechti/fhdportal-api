<?php

namespace App\Controller;

use App\Service\Auth\Keycloak;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api')]
class AnalysisController extends ResourceController
{
    // Define the resource type handled by this controller
    protected function getResourceType(): string
    {
        return 'Analysis';
    }

    // Define route prefix for URL paths for this resource
    protected function getRoutePrefix(): string
    {
        return 'analyses';
    }

    #[OA\Get(
        path: "/api/submissions/{study_id}/analyses",
        summary: "Get analyses for a study",
        tags: ['Analyses'],
        parameters: [
            new OA\Parameter(
                name: "study_id",
                in: "path",
                required: true,
                schema: new OA\Schema(type: "string")
            )
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: "Returns the analyses for the specified study",
                content: new OA\JsonContent(type: "array", items: new OA\Items(type: "object"))
            )
        ]
    )]
    #[Route('/submissions/{study_id}/analyses', name: 'get_study_analyses', methods: ['GET'])]
    public function getStudyAnalyses(Keycloak $auth, string $study_id): JsonResponse
    {
        return $this->listResources($auth, $study_id);
    }

    #[OA\Post(
        path: "/api/submissions/{study_id}/analyses",
        summary: "Create a new analysis",
        tags: ['Analyses'],
        parameters: [
            new OA\Parameter(
                name: "study_id",
                in: "path",
                required: true,
                schema: new OA\Schema(type: "string")
            )
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(type: "object")
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: "Analysis created successfully",
                content: new OA\JsonContent(type: "object")
            ),
            new OA\Response(
                response: 401,
                description: "Unauthorized"
            ),
            new OA\Response(
                response: 400,
                description: "Invalid input"
            )
        ]
    )]
    #[Route('/submissions/{study_id}/analyses', name: 'post_analysis', methods: ['POST'])]
    public function postAnalysis(Request $request, Keycloak $auth, string $study_id): JsonResponse
    {
        return $this->postResource($request, $auth, $study_id);
    }

    #[OA\Put(
        path: "/api/submissions/{study_id}/analyses/{analysis_id}",
        summary: "Update an analysis",
        tags: ['Analyses'],
        parameters: [
            new OA\Parameter(
                name: "study_id",
                in: "path",
                required: true,
                schema: new OA\Schema(type: "string")
            ),
            new OA\Parameter(
                name: "analysis_id",
                in: "path",
                required: true,
                schema: new OA\Schema(type: "string")
            )
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(type: "object")
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: "Analysis updated successfully",
                content: new OA\JsonContent(type: "object")
            ),
            new OA\Response(
                response: 401,
                description: "Unauthorized"
            ),
            new OA\Response(
                response: 400,
                description: "Invalid input"
            )
        ]
    )]
    #[Route('/submissions/{study_id}/analyses/{analysis_id}', name: 'put_analysis', methods: ['PUT'])]
    public function putAnalysis(Request $request, Keycloak $auth, string $study_id, string $analysis_id): JsonResponse
    {
        return $this->putResource($request, $auth, $study_id, $analysis_id);
    }

    #[OA\Delete(
        path: "/api/submissions/{study_id}/analyses/{analysis_id}",
        summary: "Delete an analysis",
        tags: ['Analyses'],
        parameters: [
            new OA\Parameter(
                name: "study_id",
                in: "path",
                required: true,
                schema: new OA\Schema(type: "string")
            ),
            new OA\Parameter(
                name: "analysis_id",
                in: "path",
                required: true,
                schema: new OA\Schema(type: "string")
            )
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: "Analysis deleted successfully"
            ),
            new OA\Response(
                response: 401,
                description: "Unauthorized"
            ),
            new OA\Response(
                response: 404,
                description: "Analysis not found"
            )
        ]
    )]
    #[Route('/submissions/{study_id}/analyses/{analysis_id}', name: 'delete_analysis', methods: ['DELETE'])]
    public function deleteAnalysis(Keycloak $auth, string $study_id, string $analysis_id): JsonResponse
    {
        return $this->deleteResource($auth, $study_id, $analysis_id);
    }

    #[OA\Post(
        path: "/api/submissions/{study_id}/upload-analyses",
        summary: "Upload analyses for a study",
        tags: ['Analyses'],
        parameters: [
            new OA\Parameter(
                name: "study_id",
                in: "path",
                required: true,
                schema: new OA\Schema(type: "string")
            )
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(type: "object")
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: "Analyses uploaded successfully",
                content: new OA\JsonContent(type: "object")
            ),
            new OA\Response(
                response: 401,
                description: "Unauthorized"
            ),
            new OA\Response(
                response: 400,
                description: "Invalid input"
            )
        ]
    )]
    #[Route('/submissions/{study_id}/upload-analyses', name: 'upload_analyses', methods: ['POST'])]
    public function uploadAnalyses(Request $request, Keycloak $auth, string $study_id): JsonResponse
    {
        return $this->uploadResources($request, $auth, $study_id);
    }
}