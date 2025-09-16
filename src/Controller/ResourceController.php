<?php

namespace App\Controller;

use App\Repository\ResourceRepository;
use App\Repository\ResourceTypeRepository;
use App\Repository\StatusTypeRepository;
use App\Service\Auth\Keycloak;
use App\Service\JsonSchema\Validator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Serializer\SerializerInterface;
use OpenApi\Attributes as OA;

#[Route('/api')]
class ResourceController extends AbstractController
{
    #[Route('/resources', name: 'get_resources', methods: ['GET'])]
    #[OA\Get(
        path: '/api/resources',
        summary: 'Get all resources',
        tags: ['Resources'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Successful response',
                content: new OA\JsonContent(
                    type: 'array',
                    items: new OA\Items(type: 'object')
                )
            )
        ]
    )]
    public function getResources(
        ResourceRepository $resourceRepository,
        SerializerInterface $serializer
    ): JsonResponse {
        $resources = $resourceRepository->findAll();
        $content = $serializer->serialize($resources, 'json');
        return new JsonResponse($content, json: true);
    }

    #[Route('/resources/{id}', name: 'get_resource', methods: ['GET'])]
    #[OA\Get(
        path: '/api/resources/{id}',
        summary: 'Get a resource by ID',
        tags: ['Resources'],
        parameters: [
            new OA\Parameter(
                name: 'id',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string')
            )
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Successful response',
                content: new OA\JsonContent(type: 'object')
            ),
            new OA\Response(
                response: 404,
                description: 'Resource not found'
            )
        ]
    )]
    public function getResource(
        ResourceRepository $resourceRepository,
        SerializerInterface $serializer,
        string $id
    ): JsonResponse {
        $resource = $resourceRepository->find($id);
        $content = $serializer->serialize($resource, 'json');
        return new JsonResponse($content, json: true);
    }

    #[Route('/resources', name: 'create_resource', methods: ['POST'])]
    #[OA\Post(
        path: '/api/resources',
        summary: 'Create a new resource',
        tags: ['Resources'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(type: 'object')
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Resource created successfully',
                content: new OA\JsonContent(type: 'object')
            ),
            new OA\Response(
                response: 400,
                description: 'Invalid input'
            )
        ]
    )]
    public function createResource(
        Request $request,
        Keycloak $auth,
        EntityManagerInterface $entityManager,
        SerializerInterface $serializer,
        ResourceTypeRepository $resourceTypeRepository,
        StatusTypeRepository $statusTypeRepository,
        Validator $validator
    ): JsonResponse {
        if ($auth->isGuest()) {
            return new JsonResponse(['message' => 'Unauthorized'], status: 401);
        }

        $content = $request->getContent();
        $data = json_decode($content);

        // Ensure a resource type identifier is provided
        if (!isset($data->resource_type_id)) {
            return new JsonResponse(['message' => 'resource_type_id is required'], status: 400);
        }

        // Find the resource type entity
        $resourceType = $resourceTypeRepository->find($data->resource_type_id);
        if (!$resourceType) {
            return new JsonResponse(['message' => 'Invalid resource_type_id'], status: 400);
        }

        // Get the draft status type
        $resourceStatus = $statusTypeRepository->findOneBy(['name' => 'draft']);
        if (!$resourceStatus) {
            return new JsonResponse(['message' => 'Status type not found'], status: 500);
        }

        // Retrieve the JSON schema from the resource type
        $schema = $resourceType->getProperties()['data_schema'];

        // Validate the data against the schema
        $validationErrors = $validator->validate($data->properties, $schema);
        if (!empty($validationErrors)) {
            return new JsonResponse([
                'message' => 'Validation failed',
                'errors' => $validationErrors
            ], status: 400);
        }

        // Deserialize the resource entity
        $resource = $serializer->deserialize($content, 'App\Entity\Resource', 'json');
        $resource->setType($resourceType);
        $resource->setStatus($resourceStatus);

        // Persist the resource entity
        $entityManager->persist($resource);
        $entityManager->flush();

        return new JsonResponse(['message' => 'Resource created'], status: 201);
    }

    #[Route('/resources/{id}', name: 'delete_resource', methods: ['DELETE'])]
    #[OA\Delete(
        path: '/api/resources/{id}',
        summary: 'Delete a resource by ID',
        tags: ['Resources'],
        parameters: [
            new OA\Parameter(
                name: 'id',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string')
            )
        ],
        responses: [
            new OA\Response(
                response: 204,
                description: 'Resource deleted successfully'
            ),
            new OA\Response(
                response: 404,
                description: 'Resource not found'
            )
        ]
    )]
    public function deleteResource(
        ResourceRepository $resourceRepository,
        EntityManagerInterface $entityManager,
        string $id
    ): JsonResponse {
        $resource = $resourceRepository->find($id);
        if (!$resource) {
            return new JsonResponse(['message' => 'Resource not found'], 404);
        }

        $entityManager->remove($resource);
        $entityManager->flush();

        return new JsonResponse(null, 204);
    }
}
