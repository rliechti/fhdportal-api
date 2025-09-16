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
class SampleController extends AbstractController
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
        path: "/api/submissions/{study_id}/samples",
        summary: "Get samples for a study",
        tags: ['Samples'],
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
                description: "Returns the samples for the specified study",
                content: new OA\JsonContent(type: "array", items: new OA\Items(type: "object"))
            )
        ]
    )]
    #[Route('/submissions/{study_id}/samples', name: 'get_study_samples', methods: ['GET'])]
    public function getStudySamples(Keycloak $auth, SerializerInterface $serializer, string $study_id, Validator $validator): JsonResponse
    {
        require __DIR__ . "/../Entity/Resource.php";
        $samples = listResources($auth, 'Sample', $study_id, 'read');
        $content = $serializer->serialize($samples, 'json');
        return new JsonResponse($content, json: true);
        // return new JsonResponse($studies);
    }

    #[OA\Delete(
        path: "/api/submissions/{study_id}/samples/{sample_id}",
        summary: "Delete a specific sample",
        tags: ['Samples'],
        parameters: [
            new OA\Parameter(
                name: "study_id",
                in: "path",
                required: true,
                schema: new OA\Schema(type: "string")
            ),
            new OA\Parameter(
                name: "sample_id",
                in: "path",
                required: true,
                schema: new OA\Schema(type: "string")
            )
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: "Returns sample uuid of the deleted sample",
                content: new OA\JsonContent(type: "string")
            )
        ]
    )]
    #[Route('/submissions/{study_id}/samples/{sample_id}', name: 'delete_sample', methods: ['DELETE'])]
    public function deleteSample(Request $request, Keycloak $auth, SerializerInterface $serializer, string $study_id, string $sample_id): JsonResponse
    {
        require __DIR__ . "/../Entity/Resource.php";
        if (!$this->checkRelationship($sample_id,$study_id)){
            return new JsonResponse("Error: this sample is not part of this study", status: 500);
        }
        
        $deleted_id = setResourceStatus($auth, $sample_id, 'DEL');
        $content = $serializer->serialize($deleted_id, 'json');
        return new JsonResponse($content, json: true);
    }

    #[OA\Get(
        path: "/api/samples",
        summary: "Get all samples",
        tags: ['Samples'],
        responses: [
            new OA\Response(
                response: 200,
                description: "Returns all samples",
                content: new OA\JsonContent(type: "array", items: new OA\Items(type: "object"))
            )
        ]
    )]
    #[Route('/samples', name: 'get_samples', methods: ['GET'])]
    public function getSamples(Keycloak $auth, SerializerInterface $serializer): JsonResponse
    {
        require __DIR__ . "/../Entity/Resource.php";
        $samples = listResources($auth, 'Sample', null, 'read');
        $content = $serializer->serialize($samples, 'json');
        return new JsonResponse($content, json: true);
        // return new JsonResponse($studies);
    }
    #[OA\Post(
        path: "/api/submissions/{study_id}/samples",
        summary: "Create a new sample",
        tags: ['Samples'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: "application/json",
                schema: new OA\Schema(
                    type: "object",
                    properties: [
                        new OA\Property(property: "name", type: "string"),
                        new OA\Property(property: "description", type: "string")
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: "Sample created successfully",
                content: new OA\JsonContent(type: "object")
            ),
            new OA\Response(
                response: 400,
                description: "Bad Request",
                content: new OA\JsonContent(type: "object", properties: [new OA\Property(property: "message", type: "string")])
            )
        ]
    )]
    #[Route('/submissions/{study_id}/samples', name: 'post_sample', methods: ['POST'])]
    public function postSample(Request $request, Keycloak $auth, SerializerInterface $serializer, Validator $validator, string $study_id): JsonResponse
    {
        if ($auth->isGuest()) {
            return new JsonResponse(['message' => 'Unauthorized'], status: 401);
        }
        require __DIR__ . "/../Entity/Resource.php";
        $project_dir = $this->getParameter('kernel.project_dir');
        $content = $request->getContent();
        $content = json_decode($content, true);
        $sample = $content['properties'];
        $sampleType = $content['sample_type'];
        try {
            $sample_id = (isset($sample->id)) ? $sample->id : null;
            $project_dir = $this->getParameter('kernel.project_dir');
            $sample = editResource($sample, $sampleType, $study_id, $auth, $validator, $project_dir);
            $content = $serializer->serialize($sample, 'json');
            return new JsonResponse($content, json: true);
        } catch (\Exception $e) {
            return new JsonResponse($e->getMessage(), status: 500);
        }
    }
    #[OA\Put(
        path: "/api/submissions/{study_id}/samples/{sample_id}",
        summary: "Update an existing sample",
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
            content: new OA\MediaType(
                mediaType: "application/json",
                schema: new OA\Schema(
                    type: "object",
                    properties: [
                        new OA\Property(property: "name", type: "string"),
                        new OA\Property(property: "description", type: "string")
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: "Sample updated successfully",
                content: new OA\JsonContent(type: "object")
            ),
            new OA\Response(
                response: 400,
                description: "Bad Request",
                content: new OA\JsonContent(type: "object", properties: [new OA\Property(property: "message", type: "string")])
            )
        ]
    )]
    #[Route('/submissions/{study_id}/samples/{sample_id}', name: 'put_sample', methods: ['PUT'])]
    public function putSample(Request $request, Keycloak $auth, SerializerInterface $serializer, Validator $validator, string $study_id, string $sample_id): JsonResponse
    {
        if ($auth->isGuest()) {
            return new JsonResponse(['message' => 'Unauthorized'], status: 401);
        }
        if (!$this->checkRelationship($sample_id,$study_id)){
            return new JsonResponse("Error: this sample is not part of this study", status: 500);
        }
        
        require __DIR__ . "/../Entity/Resource.php";
        $project_dir = $this->getParameter('kernel.project_dir');
        $content = $request->getContent();
        $content = json_decode($content, true);
        $sample = $content['properties'];
        $sampleType = $content['sample_type'];
        try {
            $sample_id = (isset($sample->id)) ? $sample->id : null;
            $project_dir = $this->getParameter('kernel.project_dir');
            $sample = editResource($sample, $sampleType, $study_id, $auth, $validator, $project_dir);
            $content = $serializer->serialize($sample, 'json');
            return new JsonResponse($content, json: true);
        } catch (\Exception $e) {
            return new JsonResponse($e->getMessage(), status: 500);
        }
    }

    #[OA\Post(
        path: "/api/submissions/{study_id}/upload-samples",
        summary: "Upload samples for a study",
        tags: ['Samples'],
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
            content: new OA\MediaType(
                mediaType: "multipart/form-data",
                schema: new OA\Schema(
                    type: "object",
                    properties: [
                        new OA\Property(
                            property: "file",
                            type: "string",
                            format: "binary"
                        )
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: "Returns the response of the upload process",
                content: new OA\JsonContent(type: "object")
            ),
            new OA\Response(
                response: 401,
                description: "Unauthorized",
                content: new OA\JsonContent(type: "object", properties: [new OA\Property(property: "message", type: "string")])
            ),
            new OA\Response(
                response: 500,
                description: "Internal Server Error",
                content: new OA\JsonContent(type: "string")
            )
        ]
    )]
    #[Route('/submissions/{study_id}/upload-samples', name: 'upload_sample', methods: ['POST'])]
    public function uploadSamples(Request $request, Keycloak $auth, SerializerInterface $serializer, Validator $validator, string $study_id): JsonResponse
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
