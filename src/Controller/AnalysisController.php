<?php

namespace App\Controller;

use App\Service\Auth\Keycloak;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Serializer\SerializerInterface;
use App\Service\JsonSchema\Validator;
use App\Service\UuidChecker;
use OpenApi\Attributes as OA;

#[Route('/api')]
class AnalysisController extends AbstractController
{
    
    
    private function checkRelationship($domain_id,$range_id){
        // check that dataset and study have relations
        $uuid = new UuidChecker($domain_id);
        $domain_field = ($uuid->check()) ? "domain_resource_id" : "domain_public_id";
        $uuid = new UuidChecker($range_id);
        $range_field = ($uuid->check()) ? "range_resource_id" : "range_public_id";
        $relation_id = \DB::queryFirstField("SELECT id from relationship_view where %b = %s and %b = %s",$domain_field,$domain_id,$range_field,$range_id);
        if (!$relation_id){
            return false;
        }
        return $relation_id;
    }
    
    #[OA\Get(
        path: '/api/submissions/{study_id}/analyses',
        summary: 'Get analyses for a study',
        tags: ['Analyses'],
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
                description: 'Successful response',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(type: 'object'))
            )
        ]
    )]
    #[Route('/submissions/{study_id}/analyses', name: 'get_study_analyses', methods: ['GET'])]
    public function getStudyAnalyses(Keycloak $auth, SerializerInterface $serializer, string $study_id, Validator $validator): JsonResponse
    {
        require __DIR__ . "/../Entity/Resource.php";
        $analyses = listResources($auth, 'Analysis', $study_id, 'read');
        $content = $serializer->serialize($analyses, 'json');
        return new JsonResponse($content, json: true);
    }

    #[OA\Delete(
        path: '/api/submissions/{study_id}/analyses/{analysis_id}',
        summary: 'Delete an analysis by ID',
        tags: ['Analyses'],
        parameters: [
            new OA\Parameter(
                name: 'study_id',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string')
            ),
            new OA\Parameter(
                name: 'analysis_id',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string')
            )
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Successful response',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(type: 'string'))
            )
        ]
    )]
    #[Route('/submissions/{study_id}/analyses/{analysis_id}', name: 'delete_analysis', methods: ['DELETE'])]
    public function deleteAnalysis(Request $request, Keycloak $auth, SerializerInterface $serializer, string $study_id, string $analysis_id): JsonResponse
    {
        require __DIR__ . "/../Entity/Resource.php";
        if (!$this->checkRelationship($analysis_id,$study_id)){
            return new JsonResponse("Error: this analysis is not part of this study", status: 500);
        }
        
        $deleted_id = setResourceStatus($auth, $analysis_id, 'DEL');
        $content = $serializer->serialize($deleted_id, 'json');
        return new JsonResponse($content, json: true);
    }

    #[OA\Get(
        path: '/api/analyses',
        summary: 'Get all public analyses',
        tags: ['Analyses'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Successful response',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(type: 'object'))
            )
        ]
    )]
    #[Route('/analyses', name: 'get_analyses', methods: ['GET'])]
    public function getAnalyses(Keycloak $auth, SerializerInterface $serializer): JsonResponse
    {
        require __DIR__ . "/../Entity/Resource.php";
        $analyses = listResources($auth, 'Analysis', null, 'read');
        $content = $serializer->serialize($analyses, 'json');
        return new JsonResponse($content, json: true);
    }

    #[OA\Post(
        path: '/api/submissions/{study_id}/analyses',
        summary: 'Create a new analysis for a study',
        tags: ['Analyses'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(type: 'object')
        ),
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
                response: 201,
                description: 'Analysis created successfully',
                content: new OA\JsonContent(type: 'object')
            ),
            new OA\Response(
                response: 400,
                description: 'Invalid input'
            )
        ]
    )]
    #[Route('/submissions/{study_id}/analyses', name: 'post_analysis', methods: ['POST'])]
    public function postAnalysis(Request $request, Keycloak $auth, SerializerInterface $serializer, Validator $validator, string $study_id): JsonResponse
    {
        if ($auth->isGuest()) {
            return new JsonResponse(['message' => 'Unauthorized'], status: 401);
        }
        require __DIR__ . "/../Entity/Resource.php";
        $project_dir = $this->getParameter('kernel.project_dir');
        $content = $request->getContent();
        $content = json_decode($content, true);
        $analysis = $content['properties'];
        $analysisType = $content['analysis_type'];
        try {
            $project_dir = $this->getParameter('kernel.project_dir');
            $analysis = editResource($analysis, $analysisType, $study_id, $auth, $validator, $project_dir);
            $r = json_decode(json_encode($analysis), true);

            $content = $serializer->serialize($analysis, 'json');
            return new JsonResponse($content, json: true);
        } catch (\Exception $e) {
            return new JsonResponse($e->getMessage(), status: 500);
        }
    }

    #[OA\Put(
        path: '/api/submissions/{study_id}/analyses/{analysis_id}',
        summary: 'Create a new analysis for a study',
        tags: ['Analyses'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(type: 'object')
        ),
        parameters: [
            new OA\Parameter(
                name: 'study_id',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string')
            ),
            new OA\Parameter(
                name: 'analysis_id',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string')
            )
        ],
        responses: [
            new OA\Response(
                response: 201,
                description: 'Analysis updated successfully',
                content: new OA\JsonContent(type: 'object')
            ),
            new OA\Response(
                response: 400,
                description: 'Invalid input'
            )
        ]
    )]
    #[Route('/submissions/{study_id}/analyses/{analysis_id}', name: 'put_analysis', methods: ['PUT'])]
    public function putAnalysis(Request $request, Keycloak $auth, SerializerInterface $serializer, Validator $validator, string $study_id, string $analysis_id): JsonResponse
    {
        if ($auth->isGuest()) {
            return new JsonResponse(['message' => 'Unauthorized'], status: 401);
        }
        if (!$this->checkRelationship($analysis_id,$study_id)){
            return new JsonResponse("Error: this analysis is not part of this study", status: 500);
        }
        
        require __DIR__ . "/../Entity/Resource.php";
        $project_dir = $this->getParameter('kernel.project_dir');
        $content = $request->getContent();
        $content = json_decode($content, true);
        $analysis = $content['properties'];
        $analysisType = $content['analysis_type'];
        try {
            $project_dir = $this->getParameter('kernel.project_dir');
            $analysis = editResource($analysis, $analysisType, $study_id, $auth, $validator, $project_dir);
            $r = json_decode(json_encode($analysis), true);

            $content = $serializer->serialize($analysis, 'json');
            return new JsonResponse($content, json: true);
        } catch (\Exception $e) {
            return new JsonResponse($e->getMessage(), status: 500);
        }
    }

    #[OA\Post(
        path: '/api/submissions/{study_id}/upload-analyses',
        summary: 'Upload analyses for a study',
        tags: ['Analyses'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(type: 'object')
            )
        ),
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
                description: 'Analyses uploaded successfully',
                content: new OA\JsonContent(type: 'object')
            ),
            new OA\Response(
                response: 401,
                description: 'Unauthorized'
            ),
            new OA\Response(
                response: 400,
                description: 'Invalid input'
            )
        ]
    )]
    #[Route('/submissions/{study_id}/upload-analyses', name: 'upload_analysis', methods: ['POST'])]
    public function uploadAnalyses(Request $request, Keycloak $auth, SerializerInterface $serializer, Validator $validator, string $study_id): JsonResponse
    {
        if ($auth->isGuest()) {
            return new JsonResponse(['message' => 'Unauthorized'], status: 401);
        }
        require __DIR__ . "/../Entity/Resource.php";
        $content = $request->request->all();
        $project_dir = $this->getParameter('kernel.project_dir');
        $uploadResponse = uploadResources($auth, $study_id, $request, $project_dir, $content, $validator, $serializer);
        return new JsonResponse($uploadResponse, json: true);
    }
}
