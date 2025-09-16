<?php

namespace App\Controller;

use App\Service\Auth\Keycloak;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Serializer\SerializerInterface;
use App\Service\JsonSchema\Validator;
use OpenApi\Attributes as OA;

#[Route('/api')]
class RunController extends AbstractController
{
    
    private function checkUuid($str)
    {
        return (is_string($str) && preg_match("/^[0-9A-F]{8}-[0-9A-F]{4}-4[0-9A-F]{3}-[89AB][0-9A-F]{3}-[0-9A-F]{12}$/i", $str));
    }

    private function checkRelationship($domain_id,$range_id){
        // check that dataset and study have relations
        $domain_field = $this->checkUuid($domain_id) ? "domain_resource_id" : "domain_public_id";
        $range_field = $this->checkUuid($range_id) ? "range_resource_id" : "range_public_id";
        $relation_id = \DB::queryFirstField("SELECT id from relationship_view where %b = %s and %b = %s",$domain_field,$domain_id,$range_field,$range_id);
        if (!$relation_id){
            return false;
        }
        return $relation_id;
    }
    
    #[OA\Get(
        path: '/api/submissions/{study_id}/runs',
        summary: 'Get study runs',
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
                description: 'Successful response',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(type: 'object'))
            )
        ]
    )]
    #[Route('/submissions/{study_id}/runs', name: 'get_study_runs', methods: ['GET'])]
    public function getStudyRuns(Keycloak $auth, SerializerInterface $serializer, string $study_id, Validator $validator): JsonResponse
    {
        require __DIR__ . "/../Entity/Resource.php";
        $runs = listResources($auth, 'Run', $study_id, 'read');
        $content = $serializer->serialize($runs, 'json');
        return new JsonResponse($content, json: true);
    }

    #[OA\Delete(
        path: '/api/submissions/{study_id}/runs/{run_id}',
        summary: 'Delete run',
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
            new OA\Response(
                response: 200,
                description: 'Successful response',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(type: 'string'))
            )
        ]
    )]
    #[Route('/submissions/{study_id}/runs/{run_id}', name: 'delete_run', methods: ['DELETE'])]
    public function deleteRun(Request $request, Keycloak $auth, SerializerInterface $serializer, string $study_id, string $run_id): JsonResponse
    {
        require __DIR__ . "/../Entity/Resource.php";
        if (!$this->checkRelationship($run_id,$study_id)){
            return new JsonResponse("Error: this run is not part of this study", status: 500);
        }
        
        $deleted_id = setResourceStatus($auth, $run_id, 'DEL');
        $content = $serializer->serialize($deleted_id, 'json');
        return new JsonResponse($content, json: true);
    }


    #[OA\Post(
        path: '/api/submissions/{study_id}/runs',
        summary: 'Create a new run',
        tags: ['Runs'],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(
                type: 'object',
                properties: [
                    new OA\Property(property: 'name', type: 'string'),
                    new OA\Property(property: 'description', type: 'string')
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Run created successfully',
                content: new OA\JsonContent(type: 'object')
            ),
            new OA\Response(
                response: 400,
                description: 'Invalid input'
            )
        ]
    )]
    #[Route('/submissions/{study_id}/runs', name: 'post_run', methods: ['POST'])]
    public function postRun(Request $request, Keycloak $auth, SerializerInterface $serializer, Validator $validator, string $study_id): JsonResponse
    {
        if ($auth->isGuest()) {
            return new JsonResponse(['message' => 'Unauthorized'], status: 401);
        }
        require __DIR__ . "/../Entity/Resource.php";
        $project_dir = $this->getParameter('kernel.project_dir');
        $content = $request->getContent();
        $content = json_decode($content, true);
        $run = $content['properties'];
        $runType = $content['run_type'];
        try {
            $run_id =  null;
            $project_dir = $this->getParameter('kernel.project_dir');
            $run = editResource($run, $runType, $study_id, $auth, $validator, $project_dir);
            $r = json_decode(json_encode($run), true);
            // if (isset($r['properties']['sdafile_public_ids']) && count($r['properties']['sdafile_public_ids'])){
            //  $rabbitmq->registerSDAfiles($r['study_public_id'],$r['properties']['sdafile_public_ids']);
            // }
            $content = $serializer->serialize($run, 'json');
            return new JsonResponse($content, json: true);
        } catch (\Exception $e) {
            return new JsonResponse($e->getMessage(), status: 500);
        }
    }

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
            content: new OA\JsonContent(
                type: 'object',
                properties: [
                    new OA\Property(property: 'name', type: 'string'),
                    new OA\Property(property: 'description', type: 'string')
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Run updated successfully',
                content: new OA\JsonContent(type: 'object')
            ),
            new OA\Response(
                response: 400,
                description: 'Invalid input'
            )
        ]
    )]
    #[Route('/submissions/{study_id}/runs/{run_id}', name: 'put_run', methods: ['PUT'])]
    public function putRun(Request $request, Keycloak $auth, SerializerInterface $serializer, Validator $validator, string $study_id, string $run_id): JsonResponse
    {
        if ($auth->isGuest()) {
            return new JsonResponse(['message' => 'Unauthorized'], status: 401);
        }
        if (!$this->checkRelationship($run_id,$study_id)){
            return new JsonResponse("Error: this run is not part of this study", status: 500);
        }
        
        require __DIR__ . "/../Entity/Resource.php";
        $project_dir = $this->getParameter('kernel.project_dir');
        $content = $request->getContent();
        $content = json_decode($content, true);
        $run = $content['properties'];
        $runType = $content['run_type'];
        try {
            $project_dir = $this->getParameter('kernel.project_dir');
            $run = editResource($run, $runType, $study_id, $auth, $validator, $project_dir);
            $r = json_decode(json_encode($run), true);
            // if (isset($r['properties']['sdafile_public_ids']) && count($r['properties']['sdafile_public_ids'])){
            //  $rabbitmq->registerSDAfiles($r['study_public_id'],$r['properties']['sdafile_public_ids']);
            // }
            $content = $serializer->serialize($run, 'json');
            return new JsonResponse($content, json: true);
        } catch (\Exception $e) {
            return new JsonResponse($e->getMessage(), status: 500);
        }
    }

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
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'file', type: 'string', format: 'binary')
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Runs uploaded successfully',
                content: new OA\JsonContent(type: 'object')
            ),
            new OA\Response(
                response: 400,
                description: 'Invalid file'
            ),
            new OA\Response(
                response: 401,
                description: 'Unauthorized'
            ),
            new OA\Response(
                response: 500,
                description: 'Internal server error'
            )
        ]
    )]
    #[Route('/submissions/{study_id}/upload-runs', name: 'upload_run', methods: ['POST'])]
    public function uploadRuns(Request $request, Keycloak $auth, SerializerInterface $serializer, Validator $validator, string $study_id): JsonResponse
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
