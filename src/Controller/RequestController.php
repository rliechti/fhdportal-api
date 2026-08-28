<?php

namespace App\Controller;

use App\Service\Auth\Keycloak;
use App\Service\Dac\DatasetRequestService;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\SerializerInterface;

#[Route('/api')]
class RequestController extends AbstractController
{
    private DatasetRequestService $requestService;
    private Keycloak $auth;
    private SerializerInterface $serializer;

    public function __construct(DatasetRequestService $requestService, Keycloak $auth, SerializerInterface $serializer)
    {
        $this->requestService = $requestService;
        $this->auth = $auth;
        $this->serializer = $serializer;
    }

    #[Route('/requests', name: 'get_user_requests', methods: ['GET'])]
    #[OA\Patch(
        path: '/api/requests',
        summary: 'Fetch user download requests',
        tags: ['Requests'],
        parameters: [],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Request updated successfully',
                content: new OA\JsonContent(type: 'object')
            )
        ]
    )]
    public function getUserRequests(Keycloak $auth): JsonResponse
    {
        $result = $this->requestService->getUserRequests($auth);
        if ($result['status'] !== 'success') {
            return new JsonResponse([$result['message']], $result['exit_code']);
        }
        return new JsonResponse($result['content'], $result['exit_code']);
    }

    #[Route('/requests/{request_id}/tokens', name: 'get_request_tokens', methods: ['GET'])]
    #[OA\GET(
        path: '/api/requests/{request_id}/tokens',
        summary: 'Fetch new access tokens',
        tags: ['Requests'],
        parameters: [
            new OA\Parameter(
                name: 'request_id',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string')
            )
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Request updated successfully',
                content: new OA\JsonContent(type: 'object')
            )
        ]
    )]
    public function getRequestTokens(Keycloak $auth, string $request_id): JsonResponse
    {
        $result = $this->requestService->getRequestTokens($auth, $request_id);
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
        if (!$auth->isDacCli()) {
            return $this->json(['message' => 'Unauthorized'], 401);
        }

        $content = $request->getContent();
        $params = json_decode($content, true);
        $result = $dac->patchRequest($auth, $request_id, $params);
        if ($result['status'] !== 'success') {
            return new JsonResponse([$result['message']], $result['exit_code']);
        }
        return new JsonResponse($result['content'], $result['exit_code']);
    }
}
