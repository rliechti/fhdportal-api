<?php

namespace App\Controller;

use App\Service\Auth\Keycloak;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api')]
class AdminController extends AbstractController
{
    #[OA\Get(
        path: '/api/admin/users',
        summary: 'Get all users',
        tags: ['Users'],
        parameters: [],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Successful response',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(type: 'object'))
            )
        ]
    )]
    #[Route('/admin/users', name: 'get_all_users', methods: ['GET'])]
    public function getAllUsers(Request $request, Keycloak $auth, SerializerInterface $serializer): JsonResponse
    {
        if (!$auth->hasRole('admin-fega')) {
            return new JsonResponse(['message' => 'Unauthorized'], status: 401);
        }
        require __DIR__."/../Entity/Admin.php";
        $users = getAllUsers();
        $content = $serializer->serialize($users, 'json');
        return new JsonResponse($content, json: true);
    }

    #[OA\Get(
        path: '/api/admin/roles',
        summary: 'Get all user roles',
        tags: ['Users'],
        parameters: [],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Successful response',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(type: 'object'))
            )
        ]
    )]
    #[Route('/admin/roles', name: 'get_roles', methods: ['GET'])]
    public function getRoles(Request $request, Keycloak $auth, SerializerInterface $serializer): JsonResponse
    {
        if (!$auth->hasRole('admin-fega')) {
            return new JsonResponse(['message' => 'Unauthorized'], status: 401);
        }
        require __DIR__."/../Entity/Admin.php";
        $roles = getRoles();
        $content = $serializer->serialize($roles, 'json');
        return new JsonResponse($content, json: true);
    }

    #[OA\Put(
        path: '/api/admin/users/{user_id}/roles',
        summary: 'Set roles of a user',
        tags: ['Users'],
        parameters: [
            new OA\Parameter(
                name: 'user_id',
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
    #[Route('/admin/users/{user_id}/roles', name: 'set_roles', methods: ['PUT'])]
    public function setRoles(Request $request, Keycloak $auth, SerializerInterface $serializer, string $user_id): JsonResponse
    {
        if (!$auth->hasRole('admin-fega')) {
            return new JsonResponse(['message' => 'Unauthorized'], status: 401);
        }
        require __DIR__."/../Entity/Admin.php";
        $content = $request->getContent();
        $roles = json_decode($content);

        $roles = setRoles($user_id, $roles);
        $content = $serializer->serialize($roles, 'json');
        return new JsonResponse($content, json: true);
    }
}
