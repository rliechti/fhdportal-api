<?php

namespace App\Controller;

use App\Service\Auth\Keycloak;
use App\Service\User\UserKeyService;
use App\Service\User\UserReadService;
use App\Service\User\UserRoleReadService;
use App\Service\User\UserRoleRequestService;
use MeekroDB;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api')]
final class UserController extends AbstractController
{
    public function __construct(
        private readonly UserReadService $userRead,
        private readonly UserRoleReadService $roleRead,
        private readonly UserRoleRequestService $roleRequest,
        private readonly UserKeyService $userKey
    ) {
    }

    #[Route('/users', name: 'get_users', methods: ['GET'])]
    #[OA\Get(
        path: '/api/users',
        summary: 'Get users (admin only)',
        tags: ['Users'],
        parameters: [
            new OA\Parameter(
                name: 'email',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string')
            )
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'List of users',
                content: new OA\JsonContent(
                    type: 'array',
                    items: new OA\Items(
                        type: 'object',
                        properties: [
                            new OA\Property(property: 'id', type: 'string'),
                            new OA\Property(property: 'username', type: 'string'),
                            new OA\Property(property: 'enabled', type: 'boolean'),
                            new OA\Property(property: 'firstName', type: 'string'),
                            new OA\Property(property: 'lastName', type: 'string'),
                            new OA\Property(property: 'email', type: 'string'),
                            new OA\Property(
                                property: 'roles',
                                type: 'array',
                                items: new OA\Items(type: 'string')
                            ),
                        ]
                    )
                )
            ),
            new OA\Response(response: 401, description: 'Unauthorized')
        ]
    )]
    public function getUsers(Request $request, Keycloak $auth): JsonResponse
    {
        if ($auth->isGuest()) {
            return $this->json(['message' => 'Unauthorized'], 401);
        }

        $email = $request->query->get('email');
        $users = $this->userRead->findUsers(email: $email);

        return $this->json($users);
    }

    #[Route('/users/dtpa', name: 'get_user_dtpas', methods: ['GET'])]
    #[OA\Get(
        path: '/api/users/dtpa',
        summary: 'Get DTPA submissions for the current user',
        tags: ['Users'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'List of DTPA submissions',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(type: 'object'))
            ),
            new OA\Response(response: 401, description: 'Unauthorized')
        ]
    )]
    public function getUserDTPA(Keycloak $auth, MeekroDB $db): JsonResponse
    {
        if ($auth->isGuest()) {
            return $this->json(['message' => 'Unauthorized'], 401);
        }

        $user = $auth->getDetails();
        $dtpas = $db->query(
            "SELECT
                r.properties->>'mime_type' as dtpa_document_type,
                r.properties->>'original_name' as dtpa_document_name,
                rl.action_time as request_date
             FROM resource r
             JOIN resource_log rl ON r.id = rl.resource_id
             JOIN resource_type rt ON r.resource_type_id = rt.id
             WHERE rt.name = 'File'
               AND r.properties->>'name' LIKE '/data/dtpas/%'
               AND rl.user_id = %i
             ORDER BY rl.action_time DESC",
            $user['id']
        );

        return $this->json($dtpas ?: []);
    }

    #[Route('/users/request', name: 'send_user_request', methods: ['POST'])]
    #[OA\Post(
        path: '/api/users/request',
        summary: 'Request user role',
        tags: ['Users'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                type: 'object',
                required: ['role'],
                properties: [
                    new OA\Property(property: 'role', type: 'string')
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Role request registered',
                content: new OA\JsonContent(type: 'object')
            ),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 400, description: 'Invalid input'),
            new OA\Response(response: 422, description: 'Validation failed')
        ]
    )]
    public function sendUserRequest(Request $request, Keycloak $auth, ValidatorInterface $validator): JsonResponse
    {
        if ($auth->isGuest()) {
            return $this->json(['message' => 'Unauthorized'], 401);
        }

        $project_dir = $this->getParameter('kernel.project_dir');
        $result = $this->roleRequest->registerUserRequest($auth, $request, $project_dir);

        return $this->json($result);
    }

    #[Route('/users/{user_sub}/public-key', name: 'register_user_key', methods: ['POST'])]
    #[OA\Post(
        path: '/api/users/{user_sub}/public-key',
        summary: 'Register user public key',
        tags: ['Users'],
        parameters: [
            new OA\Parameter(
                name: 'user_sub',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string')
            )
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                type: 'object',
                required: ['params'],
                properties: [
                    new OA\Property(
                        property: 'params',
                        type: 'object',
                        required: ['userKey', 'type'],
                        properties: [
                            new OA\Property(property: 'userKey', type: 'string'),
                            new OA\Property(
                                property: 'type',
                                type: 'string',
                                enum: ['ssh', 'c4gh']
                            )
                        ]
                    )
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Key registered successfully'),
            new OA\Response(response: 400, description: 'Invalid key format'),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 422, description: 'Validation failed')
        ]
    )]
    public function registerUserKey(
        Request $request,
        Keycloak $auth,
        string $user_sub,
        ValidatorInterface $validator
    ): JsonResponse {
        if ($auth->isGuest()) {
            return $this->json(['message' => 'Unauthorized'], 401);
        }

        $user = $auth->getDetails();
        if (!isset($user['sub']) || $user['sub'] !== $user_sub) {
            return $this->json(['message' => 'User mismatch'], 401);
        }

        $payload = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $violations = $validator->validate($payload, new Assert\Collection([
            'params' => new Assert\Collection([
                'fields' => [
                    'userKey' => [
                        new Assert\NotBlank(),
                        new Assert\Type('string'),
                        new Assert\Length(['min' => 10])
                    ],
                    'type' => [
                        new Assert\NotBlank(),
                        new Assert\Choice(['ssh', 'c4gh'])
                    ]
                ]
            ])
        ]));

        if (count($violations) > 0) {
            return $this->json([
                'message' => 'Validation failed',
                'errors' => (string) $violations
            ], 422);
        }

        $publicKey = $payload['params']['userKey'];
        $keyType = $payload['params']['type'];

        $result = $this->userKey->registerKey($user, $keyType, $publicKey);

        if ($result['status'] === 200) {
            return $this->json($result['content']);
        }

        return $this->json($result['error'], $result['status']);
    }

    #[Route('/users/{user_sub}/public-key/{key_type}/{public_key}', name: 'delete_user_key', methods: ['DELETE'])]
    #[OA\Delete(
        path: '/api/users/{user_sub}/public-key/{key_type}/{public_key}',
        summary: 'Delete user public key',
        tags: ['Users'],
        parameters: [
            new OA\Parameter(
                name: 'user_sub',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string')
            ),
            new OA\Parameter(
                name: 'key_type',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string', enum: ['ssh', 'c4gh'])
            ),
            new OA\Parameter(
                name: 'public_key',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string')
            )
        ],
        responses: [
            new OA\Response(response: 200, description: 'Key deleted successfully'),
            new OA\Response(response: 400, description: 'Key not found'),
            new OA\Response(response: 401, description: 'Unauthorized')
        ]
    )]
    public function deleteUserKey(
        Keycloak $auth,
        string $user_sub,
        string $key_type,
        string $public_key
    ): JsonResponse {
        $user = $auth->getDetails();
        if (!$user || $auth->isGuest()) {
            return $this->json(['message' => 'Unauthorized'], 401);
        }

        // URL-decode public_key for accurate matching
        $decodedKey = urldecode($public_key);

        $result = $this->userKey->deleteKey($auth, $user_sub, $decodedKey, $key_type);

        if ($result['status'] === 200) {
            return $this->json($result['content']);
        }

        return $this->json($result['error'], $result['status']);
    }

    #[Route('/roles', name: 'get_roles', methods: ['GET'])]
    #[OA\Get(
        path: '/api/roles',
        summary: 'Get all available roles',
        tags: ['Users'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'List of roles',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(type: 'object'))
            ),
            new OA\Response(response: 401, description: 'Unauthorized')
        ]
    )]
    public function getRoles(Keycloak $auth): JsonResponse
    {
        if ($auth->isGuest()) {
            return $this->json(['message' => 'Unauthorized'], 401);
        }

        $roles = $this->roleRead->listAll();
        return $this->json($roles);
    }
}
