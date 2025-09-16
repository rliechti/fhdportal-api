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
class ExperimentController extends AbstractController
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
                description: 'Successful response',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(type: 'object'))
            )
        ]
    )]
    #[Route('/submissions/{study_id}/experiments', name: 'get_study_experiments', methods: ['GET'])]
    public function getStudyExperiments(Keycloak $auth, SerializerInterface $serializer, string $study_id, Validator $validator): JsonResponse
    {
        require __DIR__ . "/../Entity/Resource.php";
        $studies = listResources($auth, 'Experiment', $study_id, 'edit');
        $content = $serializer->serialize($studies, 'json');
        return new JsonResponse($content, json: true);
    }

    #[OA\Delete(
        path: '/api/submissions/{study_id}/experiments/{experiment_id}',
        summary: 'Delete experiments',
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
            new OA\Response(
                response: 200,
                description: 'Successful response',
                content: new OA\JsonContent(type: 'string')
            )
        ]
    )]
    #[Route('/submissions/{study_id}/experiments/{experiment_id}', name: 'delete_experiment', methods: ['DELETE'])]
    public function deleteExperiment(Request $request, Keycloak $auth, SerializerInterface $serializer, string $study_id, string $experiment_id): JsonResponse
    {
        require __DIR__ . "/../Entity/Resource.php";
        if (!$this->checkRelationship($experiment_id,$study_id)){
            return new JsonResponse("Error: this experiment is not part of this study", status: 500);
        }
        
        $deleted_id = setResourceStatus($auth, $experiment_id, 'DEL');
        $content = $serializer->serialize($deleted_id, 'json');
        return new JsonResponse($content, json: true);
    }

    #[OA\Get(
        path: '/api/experiments',
        summary: 'Get all experiments',
        tags: ['Experiments'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Successful response',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(type: 'object'))
            )
        ]
    )]
    #[Route('/experiments', name: 'get_experiments', methods: ['GET'])]
    public function getExperiments(Keycloak $auth, SerializerInterface $serializer): JsonResponse
    {
        require __DIR__ . "/../Entity/Resource.php";
        $studies = listResource($auth, 'Experiment', null, 'read');
        $content = $serializer->serialize($studies, 'json');
        return new JsonResponse($content, json: true);
    }

    #[OA\Post(
        path: '/api/submissions/{study_id}/experiments',
        summary: 'Create an experiment',
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
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'properties', type: 'object'),
                    new OA\Property(property: 'experiment_type', type: 'string')
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Success', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 401, description: 'Unauthorized', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 500, description: 'Internal Server Error', content: new OA\JsonContent(type: 'object'))
        ]
    )]
    #[Route('/submissions/{study_id}/experiments', name: 'post_experiment', methods: ['POST'])]
    public function postExperiment(Request $request, Keycloak $auth, SerializerInterface $serializer, Validator $validator, string $study_id): JsonResponse
    {
        if ($auth->isGuest()) {
            return new JsonResponse(['message' => 'Unauthorized'], status: 401);
        }
        require __DIR__ . "/../Entity/Resource.php";
        $project_dir = $this->getParameter('kernel.project_dir');
        $content = $request->getContent();
        $content = json_decode($content, true);
        $experiment = $content['properties'];
        $experiment_type = $content['experiment_type'];
        try {
            $experiment_id = (isset($experiment->id)) ? $experiment->id : null;
            $project_dir = $this->getParameter('kernel.project_dir');
            $experiment = editResource($experiment, $experiment_type, $study_id, $auth, $validator, $project_dir);
            $content = $serializer->serialize($experiment, 'json');
            return new JsonResponse($content, json: true);
        } catch (\Exception $e) {
            return new JsonResponse($e->getMessage(), status: 500);
        }
    }

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
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'properties', type: 'object'),
                    new OA\Property(property: 'experiment_type', type: 'string')
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Success', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 401, description: 'Unauthorized', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 500, description: 'Internal Server Error', content: new OA\JsonContent(type: 'object'))
        ]
    )]
    #[Route('/submissions/{study_id}/experiments/{experiment_id}', name: 'put_experiment', methods: ['PUT'])]
    public function putExperiment(Request $request, Keycloak $auth, SerializerInterface $serializer, Validator $validator, string $study_id, string $experiment_id): JsonResponse
    {
        if ($auth->isGuest()) {
            return new JsonResponse(['message' => 'Unauthorized'], status: 401);
        }
        
        if (!$this->checkRelationship($experiment_id,$study_id)){
            return new JsonResponse("Error: this experiment is not part of this study", status: 500);
        }
        
        
        require __DIR__ . "/../Entity/Resource.php";
        $project_dir = $this->getParameter('kernel.project_dir');
        $content = $request->getContent();
        $content = json_decode($content, true);
        $experiment = $content['properties'];
        $experiment_type = $content['experiment_type'];
        try {
            $project_dir = $this->getParameter('kernel.project_dir');
            $experiment = editResource($experiment, $experiment_type, $study_id, $auth, $validator, $project_dir);
            $content = $serializer->serialize($experiment, 'json');
            return new JsonResponse($content, json: true);
        } catch (\Exception $e) {
            return new JsonResponse($e->getMessage(), status: 500);
        }
    }

    #[OA\Post(
        path: '/api/submissions/{study_id}/upload-experiments',
        summary: 'Upload multiple experiments',
        tags: ['Experiments'],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'file', type: 'string', format: 'binary')
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Success', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 401, description: 'Unauthorized', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 500, description: 'Internal Server Error', content: new OA\JsonContent(type: 'object'))
        ]
    )]
    #[Route('/submissions/{study_id}/upload-experiments', name: 'upload_experiment', methods: ['POST'])]
    public function uploadExperiments(Request $request, Keycloak $auth, SerializerInterface $serializer, Validator $validator, string $study_id): JsonResponse
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
