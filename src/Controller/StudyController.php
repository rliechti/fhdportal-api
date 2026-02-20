<?php

namespace App\Controller;

use MeekroDB;
use App\Service\Auth\Keycloak;
use App\Service\Resource\ResourceReadService;
use App\Service\Dac\DatasetRequestService;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\SerializerInterface;

#[Route('/api')]
class StudyController extends AbstractController
{
    public function __construct(
        private ResourceReadService $resourceRead,
        private DatasetRequestService $datasetRequest,
        private SerializerInterface $serializer,
        private MeekroDB $db
    ) {
    }

    #[Route('/studies', name: 'get_studies', methods: ['GET'])]
    #[OA\Get(
        path: '/api/studies',
        summary: 'Get all public studies',
        tags: ['Studies'],
        parameters: [
            new OA\Parameter(
                name: 'status',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string')
            )
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'List of public studies',
                content: new OA\JsonContent(
                    type: 'array',
                    items: new OA\Items(type: 'object')
                )
            )
        ]
    )]
    public function getStudies(Request $request, Keycloak $auth, ResourceReadService $readResource): JsonResponse
    {
        $studies = $readResource->listResources($auth, 'Study', null, 'read', 'published');

        $studies = array_map(function ($s) {
            return [
                'id'           => $s['id'],
                'public_id'    => $s['public_id'],
                'title'        => $s['title'],
                'description'  => $s['properties']['description'] ?? '',
                'study_type'   => $s['properties']['study_type'],
                'released_date'=> $s['release_date'] ?? null,
                'nb_datasets'  => (int)($s['nb_public_datasets'] ?? 0)
            ];
        }, (array) $studies);

        return new JsonResponse($studies);
    }

    #[Route('/studies/{study_id}', name: 'get_study', methods: ['GET'])]
    #[OA\Get(
        path: '/api/studies/{study_id}',
        summary: 'Get a public study with datasets',
        tags: ['Studies'],
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
                description: 'Study details with datasets',
                content: new OA\JsonContent(type: 'object')
            ),
            new OA\Response(
                response: 404,
                description: 'Study not found'
            )
        ]
    )]
    public function getStudy(Keycloak $auth, ResourceReadService $readResource, string $study_id): JsonResponse
    {
        $study = $readResource->getResource($auth, 'Study', $study_id, 'read', 'published');

        if (isset($study['error'])) {
            return new JsonResponse($study['error']['message'], status: $study['error']['status']);
        }

        $user = $auth->getDetails();
        $study = (array) $study['properties'];

        $study['datasets'] = $readResource->listResources($auth, 'Dataset', $study_id, 'read');
        $study['datasets'] = array_map(function ($d) {
            return [
                'id'          => $d['id'],
                'public_id'   => $d['properties']['public_id'],
                'title'       => $d['properties']['title'],
                'description' => $d['properties']['description'],
                'types'       => $d['properties']['dataset_types'],
                'nb_samples'  => count($d['properties']['run_public_ids'] ?? [])
            ];
        }, $study['datasets']);

        foreach ($study['datasets'] as $idx => $d) {
            $policyId = $this->db->queryFirstField(
                "SELECT range_resource_id
                 FROM relationship_view
                 WHERE range_type = 'Policy'
                   AND domain_type = 'Dataset'
                   AND domain_public_id = %s
                   AND is_active = TRUE",
                $d['public_id']
            );

            $study['datasets'][$idx]['policy_id'] = $policyId;
            $study['datasets'][$idx]['request'] =
                $this->datasetRequest->getDatasetRequests($d['id'], $user['id']);
        }

        return new JsonResponse($study);
    }
}
