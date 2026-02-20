<?php

namespace App\Controller;

use App\Service\AdminService;
use App\Service\Auth\Keycloak;
use App\Service\Dac\DatasetRequestService;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\SerializerInterface;

#[Route('/api/admin')]
class AdminController extends AbstractController
{
    private AdminService $adminService;
    private Keycloak $auth;
    private SerializerInterface $serializer;

    public function __construct(AdminService $adminService, Keycloak $auth, SerializerInterface $serializer)
    {
        $this->adminService = $adminService;
        $this->auth = $auth;
        $this->serializer = $serializer;
    }

    #[Route('/users', name: 'get_all_users', methods: ['GET'])]
    #[OA\Get(
        path: '/api/admin/users',
        summary: 'Get all users',
        tags: ['Admin'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'List of all users',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(type: 'object'))
            ),
            new OA\Response(response: 401, description: 'Unauthorized')
        ]
    )]
    public function getAllUsers(): JsonResponse
    {
        if (!$this->auth->hasRole('admin-fega')) {
            return $this->json(['message' => 'Unauthorized'], 401);
        }

        $users = $this->adminService->getAllUsers();
        $content = json_encode($users);
        return new JsonResponse($content, 200, [], true);
    }

    #[Route('/roles', name: 'get_roles', methods: ['GET'])]
    #[OA\Get(
        path: '/api/admin/roles',
        summary: 'Get all roles',
        tags: ['Admin'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'List of all roles',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(type: 'object'))
            ),
            new OA\Response(response: 401, description: 'Unauthorized')
        ]
    )]
    public function getRoles(): JsonResponse
    {
        if (!$this->auth->hasRole('admin-fega')) {
            return $this->json(['message' => 'Unauthorized'], 401);
        }

        $roles = $this->adminService->getRoles();
        $content = json_encode($roles);
        return new JsonResponse($content, 200, [], true);
    }

    #[Route('/users/{user_id}/roles', name: 'set_roles', methods: ['PUT'])]
    #[OA\Put(
        path: '/api/admin/users/{user_id}/roles',
        summary: 'Set user roles',
        tags: ['Admin'],
        parameters: [
            new OA\Parameter(
                name: 'user_id',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string')
            )
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(type: 'array', items: new OA\Items(type: 'string'))
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'User roles updated successfully',
                content: new OA\JsonContent(type: 'object')
            ),
            new OA\Response(response: 401, description: 'Unauthorized')
        ]
    )]
    public function setRoles(Request $request, string $user_id): JsonResponse
    {
        if (!$this->auth->hasRole('admin-fega')) {
            return $this->json(['message' => 'Unauthorized'], 401);
        }

        $roles = json_decode($request->getContent(), true);
        $updatedRoles = $this->adminService->setRoles($user_id, $roles);
        $content = json_encode($updatedRoles);
        return new JsonResponse($content, 200, [], true);
    }

    #[Route('/requests', name: 'get_all_requests', methods: ['GET'])]
    #[OA\Get(
        path: '/api/admin/requests',
        summary: 'Get all dataset requests',
        tags: ['Requests'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'List of all dataset requests',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(type: 'object'))
            )
        ]
    )]
    public function getAllRequests(Request $request, Keycloak $auth, SerializerInterface $serializer, DatasetRequestService $dac): JsonResponse
    {
        $result = $dac->getAllRequests($auth);
        if ($result['status'] !== 'success') {
            return new JsonResponse([$result['message']], $result['exit_code']);
        }
        return new JsonResponse($result['content'], $result['exit_code']);
    }

    #[Route('/requests/{request_id}', name: 'patch_request', methods: ['PATCH'])]
    #[OA\Patch(
        path: '/api/admin/requests/{request_id}',
        summary: 'Update dataset request',
        tags: ['Requests'],
        parameters: [
            new OA\Parameter(
                name: 'request_id',
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
                description: 'Request updated successfully',
                content: new OA\JsonContent(type: 'object')
            )
        ]
    )]
    public function patchRequest(Request $request, Keycloak $auth, SerializerInterface $serializer, DatasetRequestService $dac, string $request_id): JsonResponse
    {
        $content = $request->getContent();
        $params = json_decode($content, true);
        $result = $dac->patchRequest($auth, $request_id, $params);
        if ($result['status'] !== 'success') {
            return new JsonResponse([$result['message']], $result['exit_code']);
        }
        return new JsonResponse($result['content'], $result['exit_code']);
    }
}
