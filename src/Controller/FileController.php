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
    private const DATASET_LINK_VALUES = ['all', 'linked', 'unlinked'];

    #[Route('/files', name: 'get_post_files', methods: ['GET', 'POST'])]
    #[OA\Get(
        path: '/api/files',
        summary: 'Get all files of a user (GET)',
        tags: ['Files'],
        parameters: [
            new OA\Parameter(name: 'by', in: 'query', required: false, schema: new OA\Schema(type: 'integer'), description: 'Items per page'),
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer'), description: 'Current page'),
            new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string'), description: 'Search term'),
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string'), description: 'Filter by status'),
            new OA\Parameter(name: 'size_min', in: 'query', required: false, schema: new OA\Schema(type: 'integer'), description: 'Minimum file size filter, in bytes'),
            new OA\Parameter(name: 'size_max', in: 'query', required: false, schema: new OA\Schema(type: 'integer'), description: 'Maximum file size filter, in bytes'),
            new OA\Parameter(name: 'sort_by', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['title', 'status', 'filesize', 'public_id', 'creation_date', 'verif_date', 'published_date']), description: 'Column to sort by'),
            new OA\Parameter(name: 'sort_order', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['asc', 'desc'], default: 'asc'), description: 'Sort direction'),
            new OA\Parameter(name: 'dataset_link', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['all', 'linked', 'unlinked'], default: 'all'), description: 'Filter by whether the file is linked to at least one dataset')
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
                    new OA\Property(property: 'status', type: 'string'),
                    new OA\Property(property: 'size', type: 'object', properties: [
                        new OA\Property(property: 'min', type: 'integer', nullable: true, description: 'Minimum file size, in bytes'),
                        new OA\Property(property: 'max', type: 'integer', nullable: true, description: 'Maximum file size, in bytes')
                    ]),
                    new OA\Property(property: 'sort', type: 'object', properties: [
                        new OA\Property(property: 'by', type: 'string', nullable: true, enum: ['title', 'status', 'filesize', 'public_id', 'creation_date', 'verif_date', 'published_date']),
                        new OA\Property(property: 'order', type: 'string', enum: ['asc', 'desc'], default: 'asc')
                    ]),
                    new OA\Property(property: 'datasetLink', type: 'string', enum: ['all', 'linked', 'unlinked'], default: 'all', description: 'Filter by whether the file is linked to at least one dataset')
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
            $by      = max(1, min(200, (int)($params['page']['by'] ?? 10)));
            $current = max(1, (int)($params['page']['current'] ?? 1));
            $page = ['by' => $by, 'current' => $current];
            $search = $params['search'] ?? '';
            $status = $params['status'] ?? '';
            $size = [
                'min' => isset($params['size']['min']) ? max(0, (int)$params['size']['min']) : null,
                'max' => isset($params['size']['max']) ? max(0, (int)$params['size']['max']) : null,
            ];
            $sort = [
                'by' => $params['sort']['by'] ?? null,
                'order' => $params['sort']['order'] ?? 'asc',
            ];
            $datasetLink = in_array($params['datasetLink'] ?? 'all', self::DATASET_LINK_VALUES, true)
                ? $params['datasetLink']
                : 'all';
        } else {
            $pageBy = $request->query->get('by', 10);
            $pageCurrent = $request->query->get('page', 1);
            $page = ['by' => max(1, min(200, (int)$pageBy)), 'current' => max(1, (int)$pageCurrent)];
            $search = $request->query->get('search', '');
            $status = $request->query->get('status', '');
            $sizeMinRaw = $request->query->get('size_min');
            $sizeMaxRaw = $request->query->get('size_max');
            $size = [
                'min' => $sizeMinRaw !== null ? max(0, (int)$sizeMinRaw) : null,
                'max' => $sizeMaxRaw !== null ? max(0, (int)$sizeMaxRaw) : null,
            ];
            $sort = [
                'by' => $request->query->get('sort_by'),
                'order' => $request->query->get('sort_order', 'asc'),
            ];
            $datasetLinkRaw = $request->query->get('dataset_link', 'all');
            $datasetLink = in_array($datasetLinkRaw, self::DATASET_LINK_VALUES, true) ? $datasetLinkRaw : 'all';
        }

        $files = $fileRead->getAllFiles($auth, $page, $search, $status, $size, $sort, $datasetLink);
        $content = $serializer->serialize($files, 'json');
        return new JsonResponse($content, json: true);
    }
}
