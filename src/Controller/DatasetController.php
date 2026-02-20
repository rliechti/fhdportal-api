<?php

namespace App\Controller;

use App\Service\Auth\Keycloak;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

#[Route('/api')]
class DatasetController extends ResourceController
{
    // Define the resource type handled by this controller
    protected function getResourceType(): string
    {
        return 'Dataset';
    }

    // Define route prefix for URL paths for this resource
    protected function getRoutePrefix(): string
    {
        return 'datasets';
    }

    public function listResources(Keycloak $auth, ?string $studyId = null): JsonResponse
    {
        $parentResponse = parent::listResources($auth, $studyId);
        $datasets = json_decode($parentResponse->getContent(), true);

        foreach ($datasets as $idx => $dataset) {
            $policy = $this->dac->getDatasetPolicy($auth, $dataset['id']);
            foreach ($policy as $key => $value) {
                $datasets[$idx]['policy_' . $key] = $value;
            }

            if ((!isset($datasets[$idx]['policy_id']) || !$datasets[$idx]['policy_id']) 
                && isset($datasets[$idx]['properties']['policy_id']) 
                && $datasets[$idx]['properties']['policy_id']
            ) {
                $datasets[$idx]['policy_id'] = $datasets[$idx]['properties']['policy_id'];
                $datasets[$idx]['policy_status'] = 'draft';
            }
        }

        $parentResponse->setData($datasets);
        return $parentResponse;
    }

    public function putResource(Request $request, Keycloak $auth, string $study_id, string $dataset_id): JsonResponse
    {
        $parentResponse = parent::putResource($request, $auth, $study_id, $dataset_id);
        $dataset = json_decode($parentResponse->getContent(), true);

        $policyRequest = $this->dac->getDatasetPolicy($auth, $dataset_id);
        if ($policyRequest['status'] == 'success') {
            foreach ($policyRequest['policy'] as $key => $value) {
                $dataset['policy_' . $key] = $value;
            }
        } else {
            foreach ($dataset as $k => $v) {
                if (strpos($k, 'policy') !== false) {
                    $dataset[$k] = null;
                }
            }
        }

        if ((!isset($dataset['policy_id']) || !$dataset['policy_id']) 
            && isset($dataset['properties']->policy_id) 
            && $dataset['properties']->policy_id
        ) {
            $dataset['policy_id'] = $dataset['properties']->policy_id;
            $dataset['policy_status'] = 'draft';
        }

        $parentResponse->setData($dataset);
        return $parentResponse;
    }

    #[Route('/datasets', name: 'get_datasets', methods: ['GET'])]
    #[OA\Get(
        path: '/api/datasets',
        summary: 'Get all public datasets',
        tags: ['Datasets'],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Successful response',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(type: 'object'))
            ),
        ]
    )]
    public function getDatasets(Request $request, Keycloak $auth): JsonResponse
    {
        $datasets = $this->resourceRead->listResources($auth, 'Dataset', null, 'read', 'published');
        $datasets = array_map(function ($d) {
            return [
                'public_id' => $d['properties']['public_id'],
                'title' => $d['properties']['title'],
                'description' => $d['properties']['description'],
                'types' => $d['properties']['dataset_types'],
                'nb_samples' => count($d['properties']['run_public_ids']),
                'request' => isset($d['request']) ? $d['request'] : null,
            ];
        }, (array) $datasets);

        $content = json_encode($datasets);
        return new JsonResponse($content, json: true);
    }

    #[Route('/submissions/{study_id}/datasets', name: 'get_study_datasets', methods: ['GET'])]
    #[OA\Get(
        path: '/api/submissions/{study_id}/datasets',
        summary: 'Get datasets for a study',
        tags: ['Datasets'],
        parameters: [
            new OA\Parameter(name: 'study_id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Returns the datasets for the specified study',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(type: 'object'))
            ),
        ]
    )]
    public function getStudyDatasets(Keycloak $auth, string $study_id): JsonResponse
    {
        return $this->listResources($auth, $study_id);
    }

    #[Route('/datasets/{dataset_id}', name: 'get_dataset', methods: ['GET'])]
    #[OA\Get(
        path: '/api/datasets/{dataset_id}',
        summary: 'Get a specific dataset',
        tags: ['Datasets'],
        parameters: [
            new OA\Parameter(name: 'dataset_id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Returns the dataset',
                content: new OA\JsonContent(type: 'object')
            ),
        ]
    )]
    public function getDataset(Keycloak $auth, string $dataset_id): JsonResponse
    {
        $isDacCli = $auth->isDacCli();
        $user = $auth->getDetails();

        if ($isDacCli) {
            $resource = $this->resourceRead->getResource($auth, 'Dataset', $dataset_id, 'read', 'submitted');
        } else {
            $resource = $this->resourceRead->getResource($auth, 'Dataset', $dataset_id, 'read', 'published');
        }

        if (isset($resource['error'])) {
            return new JsonResponse($resource['error']['message'], status: $resource['error']['status']);
        }

        $dataset = $resource['properties'];
        $dataset['study_public_id'] = $resource['study_public_id'];
        $status = $isDacCli ? 'DRA' : 'PUB';
        $dataset['files'] = $this->getDatasetFiles($dataset_id, $status);
        $dataset['request'] = $this->datasetRequest->getDatasetRequests($resource['id'], $user['id']);

        if ($isDacCli) {
            $study = $this->resourceRead->getResource($auth, 'Study', $dataset['study_public_id'], 'read');
            $dataset['id'] = $resource['id'];
            $dataset['status'] = $resource['status'];
            $dataset['submitter'] = $resource['owner'];
            $dataset['study'] = [
                'id' => $study['id'],
                'public_id' => $study['public_id'],
                'title' => $study['title'],
                'description' => $study['properties']->study_type,
            ];
            unset($dataset['study_public_id']);
            $dataset['nb_files'] = count($dataset['files']);
            unset($dataset['files']);
            $dataset['nb_runs'] = count($dataset['run_public_ids']);
            unset($dataset['run_public_ids']);
            $dataset['nb_analyses'] = count($dataset['analysis_public_ids']);
            unset($dataset['analysis_public_ids']);
        }

        if (isset($dataset['policy_id'])) {
            // require __DIR__ . "/../Entity/Dac.php";
            // $dataset['policy'] = getPolicy($auth,$dataset['policy_id']);
        }

        $content = json_encode($dataset);
        return new JsonResponse($content, json: true);
    }

    #[Route('/datasets/{dataset_id}/download', name: 'download_dataset', methods: ['GET'])]
    #[OA\Get(
        path: '/api/datasets/{dataset_id}/download',
        summary: 'Download dataset by ID',
        tags: ['Datasets'],
        parameters: [
            new OA\Parameter(name: 'dataset_id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Dataset downloaded successfully',
                content: new OA\MediaType(
                    mediaType: 'application/octet-stream',
                    schema: new OA\Schema(type: 'string', format: 'binary')
                )
            ),
            new OA\Response(response: 404, description: 'Dataset not found'),
        ]
    )]
    public function downloadDataset(Request $request, Keycloak $auth, string $dataset_id): BinaryFileResponse
    {
        $project_dir = $this->getParameter('kernel.project_dir');
        $data = $this->db->queryFirstRow('SELECT study_public_id, id from dataset_view where public_id = %s', $dataset_id);
        $study_id = $data['study_public_id'];
        $raw_dataset_id = $data['id'];

        $filepath = $this->resourceExport->downloadSubmission($auth, $study_id, $project_dir, $raw_dataset_id);
        return new BinaryFileResponse($filepath);
    }

    #[Route('/submissions/{study_id}/datasets', name: 'post_dataset', methods: ['POST'])]
    #[OA\Post(
        path: '/api/submissions/{study_id}/datasets',
        summary: 'Create a new dataset',
        tags: ['Datasets'],
        parameters: [
            new OA\Parameter(name: 'study_id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(type: 'object')
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Dataset created successfully',
                content: new OA\JsonContent(type: 'object')
            ),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 400, description: 'Invalid input')
        ]
    )]
    public function postDataset(Request $request, Keycloak $auth, string $study_id): JsonResponse
    {
        return $this->postResource($request, $auth, $study_id);
    }

    #[Route('/submissions/{study_id}/datasets/{dataset_id}', name: 'put_dataset', methods: ['PUT'])]
    #[OA\Put(
        path: '/api/submissions/{study_id}/datasets/{dataset_id}',
        summary: 'Update a dataset',
        tags: ['Datasets'],
        parameters: [
            new OA\Parameter(name: 'study_id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'dataset_id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(type: 'object')
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Dataset updated successfully',
                content: new OA\JsonContent(type: 'object')
            ),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 400, description: 'Invalid input')
        ]
    )]
    public function putDataset(Request $request, Keycloak $auth, string $study_id, string $dataset_id): JsonResponse
    {
        return $this->putResource($request, $auth, $study_id, $dataset_id);
    }

    #[Route('/submissions/{study_id}/datasets/{dataset_id}', name: 'delete_dataset', methods: ['DELETE'])]
    #[OA\Delete(
        path: '/api/submissions/{study_id}/datasets/{dataset_id}',
        summary: 'Delete a dataset',
        tags: ['Datasets'],
        parameters: [
            new OA\Parameter(name: 'study_id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'dataset_id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Dataset deleted successfully'),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 404, description: 'Dataset not found')
        ]
    )]
    public function deleteDataset(Keycloak $auth, string $study_id, string $dataset_id): JsonResponse
    {
        return $this->deleteResource($auth, $study_id, $dataset_id);
    }

    #[Route('/submissions/{study_id}/upload-datasets', name: 'upload_datasets', methods: ['POST'])]
    #[OA\Post(
        path: '/api/submissions/{study_id}/upload-datasets',
        summary: 'Upload datasets for a study',
        tags: ['Datasets'],
        parameters: [
            new OA\Parameter(name: 'study_id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(type: 'object')
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Datasets uploaded successfully',
                content: new OA\JsonContent(type: 'object')
            ),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 400, description: 'Invalid input')
        ]
    )]
    public function uploadDatasets(Request $request, Keycloak $auth, string $study_id): JsonResponse
    {
        return $this->uploadResources($request, $auth, $study_id);
    }

    /**
     * Fetches and decodes properties JSON from SDA files linked to the given dataset.
     * Joins dataset → relationships → SdaFile resources matching status/public_id.
     *
     * @param string $datasetPublicId Dataset public ID
     * @param string $status Status type ID filter
     * @return array Decoded file properties arrays
     */
    private function getDatasetFiles(string $datasetPublicId, string $status): array
    {
        $files = $this->db->queryFirstColumn(
            "SELECT sda_files.properties FROM dataset_view 
             inner join relationship on dataset_view.id = relationship.range_resource_id 
             inner join relationship as sda_relationship on relationship.domain_resource_id = sda_relationship.range_resource_id 
             inner join resource as sda_files on sda_files.id = sda_relationship.domain_resource_id and sda_files.status_type_id = %s_status 
             inner join resource_type as sda_file_type on sda_files.resource_type_id = sda_file_type.id and sda_file_type.name = 'SdaFile' 
             WHERE dataset_view.status_type_id = %s_status and dataset_view.public_id = %s_id;",
            ['id' => $datasetPublicId, 'status' => $status]
        );

        if (!is_array($files)) {
            return [];
        }

        return array_map(function ($f) {
            return json_decode($f, true);
        }, $files);
    }
}
