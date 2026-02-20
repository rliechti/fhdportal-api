<?php

namespace App\Controller;

use App\Service\Auth\Keycloak;
use App\Service\File\FileReadService;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\SerializerInterface;

#[Route('/api')]
class FileController extends AbstractController
{
    #[Route('/files', name: 'get_post_files', methods: ['GET', 'POST'])]
    #[OA\Get(
        path: '/api/files',
        summary: 'Get all files of a user (GET)',
        tags: ['Files'],
        parameters: [
            new OA\Parameter(name: 'by', in: 'query', required: false, schema: new OA\Schema(type: 'integer'), description: 'Items per page'),
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer'), description: 'Current page'),
            new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string'), description: 'Search term'),
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string'), description: 'Filter by status')
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Successful response',
                content: new OA\JsonContent(type: 'object')
            )
        ]
    )]
    #[OA\Post(
        path: '/api/files',
        summary: 'Get all files of a user (POST)',
        tags: ['Files'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                type: 'object',
                properties: [
                    new OA\Property(property: 'page', type: 'object', properties: [
                        new OA\Property(property: 'by', type: 'integer'),
                        new OA\Property(property: 'current', type: 'integer')
                    ]),
                    new OA\Property(property: 'search', type: 'string'),
                    new OA\Property(property: 'status', type: 'string')
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Successful response',
                content: new OA\JsonContent(type: 'object')
            ),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 400, description: 'Invalid input')
        ]
    )]
    public function getFiles(Request $request, Keycloak $auth, SerializerInterface $serializer, FileReadService $fileRead): JsonResponse
    {
        if ($request->getMethod() === 'POST') {
            $content = $request->getContent();
            $params = json_decode($content, true);
            $page = $params['page'] ?? ['by' => 10, 'current' => 1];
            $search = $params['search'] ?? '';
            $status = $params['status'] ?? '';
        } else {
            $pageBy = $request->query->get('by', 10);
            $pageCurrent = $request->query->get('page', 1);
            $page = ['by' => (int)$pageBy, 'current' => (int)$pageCurrent];
            $search = $request->query->get('search', '');
            $status = $request->query->get('status', '');
        }

        $files = $fileRead->getAllFiles($auth, $page, $search, $status);
        $content = $serializer->serialize($files, 'json');
        return new JsonResponse($content, json: true);
    }
}
