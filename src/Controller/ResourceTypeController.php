<?php

namespace App\Controller;

use App\Repository\ResourceTypeRepository;
use App\Service\Auth\Keycloak;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Serializer\SerializerInterface;
use OpenApi\Attributes as OA;

#[Route('/api')]
class ResourceTypeController extends AbstractController
{
    #[Route('/resource-types', name: 'get_resource_types', methods: ['GET'])]
    #[OA\Get(
        path: '/api/resource-types',
        summary: 'Get all resource types',
        tags: ['Resource Types'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Successful response',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(type: "object"))
            )
        ]
    )]
    public function getResourceTypes(ResourceTypeRepository $resourceTypeRepository, SerializerInterface $serializer): JsonResponse
    {
        // $resourceTypes = $resourceTypeRepository->findAll();
        // $resourceTypes = ['test'];
        require __DIR__ . "/../Entity/Resource.php";
        $resourceTypes = listResourceTypes();

        $content = $serializer->serialize($resourceTypes, 'json');
        return new JsonResponse($content, json: true);
    }

    #[Route('/resource-types/{id}', name: 'get_resource_type', methods: ['GET'])]
    #[OA\Get(
        path: '/api/resource-types/{id}',
        summary: 'Get a resource type by ID',
        tags: ['Resource Types'],
        parameters: [
            new OA\Parameter(
                name: 'id',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer')
            )
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Successful response',
                content: new OA\JsonContent(type: "object")
            ),
            new OA\Response(
                response: 404,
                description: 'Resource type not found'
            )
        ]
    )]
    public function getResourceType(ResourceTypeRepository $resourceTypeRepository, SerializerInterface $serializer, int $id): JsonResponse
    {
        $resourceType = $resourceTypeRepository->find($id);
        $content = $serializer->serialize($resourceType, 'json');
        return new JsonResponse($content, json: true);
    }

    #[Route('/resource-types', name: 'create_resource_type', methods: ['POST'])]
    #[OA\Post(
        path: '/api/resource-types',
        summary: 'Create a new resource type',
        tags: ['Resource Types'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(type: "object")
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Resource type created'
            ),
            new OA\Response(
                response: 401,
                description: 'Unauthorized'
            )
        ]
    )]
    public function createResourceType(Request $request, Keycloak $auth, EntityManagerInterface $entityManager, SerializerInterface $serializer): JsonResponse
    {
        if ($auth->isGuest()) {
            return new JsonResponse(['message' => 'Unauthorized'], status: 401);
        }
        $content = $request->getContent();
        $resourceType = $serializer->deserialize($content, 'App\Entity\ResourceType', 'json');
        $entityManager->persist($resourceType);
        $entityManager->flush();
        return new JsonResponse(['message' => 'Resource type created'], status: 201);
    }
}
