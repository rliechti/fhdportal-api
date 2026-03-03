<?php

namespace App\Controller;

use App\Service\Auth\Keycloak;
use App\Service\Auth\KeycloakService;
use App\Service\Dac\PolicyService;
use App\Service\File\FileReadService;
use App\Service\JsonSchema\Validator;
use App\Service\PublicationService;
// use App\Service\RabbitMq\RabbitMqInterface;
use App\Service\Resource\ResourceEditService;
use App\Service\Resource\ResourceExportService;
use App\Service\Resource\ResourceReadService;
use App\Service\Resource\ResourceTemplateService;
use App\Service\SubmissionService;
use App\Service\Utility\GeneralHelperService;
use Exception;
use MeekroDB;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\SerializerInterface;
use ZipArchive;

#[Route('/api')]
class SubmissionController extends AbstractController
{
    public function __construct(
        private ResourceReadService $resourceRead,
        private ResourceEditService $resourceEdit,
        private ResourceExportService $resourceExport,
        private ResourceTemplateService $resourceTemplate,
        private PublicationService $publication,
        private SerializerInterface $serializer,
        private FileReadService $fileRead,
        private GeneralHelperService $helper,
        private MeekroDB $db,
        // private RabbitMqInterface $rabbitMq,
        private PolicyService $policy,
        private KeycloakService $keycloak,
        private SubmissionService $submissionService
    ) {
    }

    #[Route('/submissions', name: 'get_submissions', methods: ['GET'])]
    #[OA\Get(
        path: '/api/submissions',
        summary: 'Get all submissions',
        tags: ['Submissions'],
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
                description: 'List of submissions',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(type: 'object'))
            )
        ]
    )]
    public function getSubmissions(Request $request, Keycloak $auth, ResourceReadService $readResource): JsonResponse
    {
        $status = $request->query->get('status') ?? 'draft,submitted';
        $submissions = $readResource->listResources($auth, 'Study', null, 'review', $status);

        if ($status === 'published') {
            $submissions = array_map(fn($s) => [
                'id'           => $s['id'],
                'public_id'    => $s['public_id'],
                'title'        => $s['title'],
                'study_type'   => $s['properties']['study_type'] ?? null,
                'released_date'=> $s['released_date'],
                'nb_datasets'  => (int)($s['nb_public_datasets'] ?? 0)
            ], $submissions);
        }

        return new JsonResponse($submissions);
    }

    #[Route('/{resource_type}/template', name: 'download_template', methods: ['GET'])]
    #[OA\Get(
        path: '/api/{resource_type}/template',
        summary: 'Download resource template',
        tags: ['Resource Types'],
        parameters: [
            new OA\Parameter(
                name: 'resource_type',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string')
            )
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Template downloaded successfully',
                content: new OA\MediaType(
                    mediaType: 'application/zip',
                    schema: new OA\Schema(type: 'string', format: 'binary')
                )
            ),
            new OA\Response(response: 404, description: 'Template not found')
        ]
    )]
    public function downloadResourceTemplate(
        Request $request,
        Keycloak $auth,
        ResourceTemplateService $template,
        MeekroDB $db,
        string $resource_type
    ): BinaryFileResponse {
        $project_dir = $this->getParameter('kernel.project_dir');

        if (strtoupper($resource_type) === 'DTPA') {
            if ($auth->isGuest()) {
                throw new \RuntimeException('Unauthorized', 401);
            }
            $dtpaTemplatePath = $project_dir . '/legal_templates/SwissFEGA_DTPA.docx';
            if (!file_exists($dtpaTemplatePath)) {
                throw new NotFoundHttpException('DTPA template not found');
            }
            $response = new BinaryFileResponse($dtpaTemplatePath);
            $response->headers->set(
                'Content-Type',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
            );
            $response->setContentDisposition(
                \Symfony\Component\HttpFoundation\ResponseHeaderBag::DISPOSITION_ATTACHMENT,
                'SwissFEGA_DTPA.docx'
            );
            return $response;
        }

        if (strtolower($resource_type) === 'submission') {
            $resource_types = $db->queryFirstColumn(
                "SELECT name FROM resource_type 
                 WHERE resource_type.properties->'data_schema'->'properties'->'extra_attributes' IS NOT NULL"
            );

            $zip = new ZipArchive();
            $filepath = tempnam(sys_get_temp_dir(), 'submission-templates-');

            if ($zip->open($filepath, ZipArchive::CREATE) !== true) {
                throw new \RuntimeException('Cannot create ZIP archive');
            }

            foreach ($resource_types as $res_type) {
                $csvFile = $template->download($auth, $res_type, $project_dir, 'csv');
                if (file_exists($csvFile)) {
                    $zip->addFile($csvFile, $res_type . '.csv');
                }
            }

            $zip->close();

            $response = new BinaryFileResponse($filepath);
            $response->headers->set('Content-Type', 'application/zip');
            $response->headers->set('Content-Disposition', 'attachment; filename="submission-templates.zip"');
            return $response;
        }

        $filepath = $template->download($auth, $resource_type, $project_dir, 'xlsx');
        return new BinaryFileResponse($filepath);
    }

    #[Route('/cli/{binary}', name: 'download_cli', methods: ['GET'])]
    #[OA\Get(
        path: '/api/cli/{binary}',
        summary: 'Download CLI package',
        tags: ['CLI'],
        parameters: [
            new OA\Parameter(
                name: 'binary',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string')
            )
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'CLI downloaded successfully',
                content: new OA\MediaType(
                    mediaType: 'application/zip',
                    schema: new OA\Schema(type: 'string', format: 'binary')
                )
            )
        ]
    )]
    public function downloadCli(string $binary): BinaryFileResponse
    {
        $filepath = dirname(dirname(__DIR__)) . '/tools/fega-cli/' . $binary;

        if (!file_exists($filepath)) {
            throw new NotFoundHttpException('CLI binary not found: ' . $binary);
        }

        $response = new BinaryFileResponse($filepath);
        $response->headers->set('Content-Type', 'application/zip');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $binary . '"');
        return $response;
    }

    #[Route('/submissions/{study_id}/download', name: 'download_submissions', methods: ['GET'])]
    #[OA\Get(
        path: '/api/submissions/{study_id}/download',
        summary: 'Download submission by ID',
        tags: ['Submissions'],
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
                description: 'Submission package downloaded',
                content: new OA\MediaType(
                    mediaType: 'application/zip',
                    schema: new OA\Schema(type: 'string', format: 'binary')
                )
            ),
            new OA\Response(response: 404, description: 'Submission not found')
        ]
    )]
    public function downloadSubmissions(Request $request, Keycloak $auth, string $study_id): BinaryFileResponse
    {
        $project_dir = $this->getParameter('kernel.project_dir');
        $filepath = $this->resourceExport->downloadSubmission($auth, $study_id, $project_dir);
        return new BinaryFileResponse($filepath);
    }

    #[Route('/submissions/{study_id}', name: 'get_submission', methods: ['GET'])]
    #[OA\Get(
        path: '/api/submissions/{study_id}',
        summary: 'Get submission by ID',
        tags: ['Submissions'],
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
                description: 'Submission details with categorized types',
                content: new OA\JsonContent(type: 'object')
            ),
            new OA\Response(response: 404, description: 'Submission not found')
        ]
    )]
    public function getSubmission(Keycloak $auth, ResourceReadService $readResource, string $study_id): JsonResponse
    {
        $submission = $readResource->getResource($auth, 'Study', $study_id, 'read');

        // Categorize relation types efficiently
        $typeCategories = [
            'sampleTypes'     => fn($label) => stripos($label, 'sample') !== false,
            'experimentTypes' => fn($label) => stripos($label, 'experiment') !== false,
            'datasetTypes'    => fn($label) => stripos($label, 'dataset') !== false,
            'analysisTypes'   => fn($label) => stripos($label, 'analysis') !== false,
            'runTypes'        => fn($label) => stripos($label, 'run') !== false,
        ];

        foreach ($typeCategories as $key => $filter) {
            $submission[$key] = [];
        }

        if (isset($submission['relationTypes']) && is_array($submission['relationTypes'])) {
            foreach ($submission['relationTypes'] as $rel) {
                $label = $rel['label'] ?? '';
                foreach ($typeCategories as $catKey => $catFilter) {
                    if ($catFilter($label)) {
                        $submission[$catKey][] = $rel;
                        break;
                    }
                }
            }
            unset($submission['relationTypes']);
        }

        return new JsonResponse($submission);
    }

    #[Route('/pubmeds/{pmid}', name: 'get_pubmeds', methods: ['GET'])]
    #[OA\Get(
        path: '/api/pubmeds/{pmid}',
        summary: 'Get PubMed documents by PMID',
        tags: ['PubMed'],
        parameters: [
            new OA\Parameter(
                name: 'pmid',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string')
            )
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'PubMed record(s)',
                content: new OA\JsonContent(type: 'object')
            ),
            new OA\Response(response: 404, description: 'PubMed record not found')
        ]
    )]
    public function getPubmeds(string $pmid): JsonResponse
    {
        $pubmeds = $this->publication->fetchPubmeds($pmid);
        return new JsonResponse($pubmeds);
    }

    #[Route('/submissions/upload-study', name: 'upload_study', methods: ['POST'])]
    #[OA\Post(
        path: '/api/submissions/upload-study',
        summary: 'Upload new study from file',
        tags: ['Submissions'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(type: 'object')
        ),
        responses: [
            new OA\Response(response: 200, description: 'Study uploaded successfully'),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 400, description: 'Invalid input')
        ]
    )]
    public function uploadStudy(Request $request, Keycloak $auth): JsonResponse
    {
        if ($auth->isGuest()) {
            return new JsonResponse(['message' => 'Unauthorized'], 401);
        }

        $content = $request->request->all();
        $project_dir = $this->getParameter('kernel.project_dir');
        $uploadResponse = $this->resourceEdit->uploadResources($auth, 'new', $request, $project_dir, $content);
        return new JsonResponse($uploadResponse,200,[],true);
    }

    #[Route('/submissions', name: 'post_submission', methods: ['POST'])]
    #[OA\Post(
        path: '/api/submissions',
        summary: 'Create new study submission',
        tags: ['Submissions'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(type: 'object')
        ),
        responses: [
            new OA\Response(response: 200, description: 'Study created successfully'),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 400, description: 'Invalid input')
        ]
    )]
    public function postSubmission(Request $request, Keycloak $auth): JsonResponse
    {
        if ($auth->isGuest()) {
            return new JsonResponse(['message' => 'Unauthorized'], 401);
        }

        $study = json_decode($request->getContent());

        try {
            $publications = [];
            if (isset($study->pubmed_ids) && is_array($study->pubmed_ids) && count($study->pubmed_ids)) {
                $publications = $this->publication->fetchPubmeds($study->pubmed_ids);
                $study->pubmed_ids = array_keys($publications);
            }

            $study_id = $study->id ?? 'new';
            $project_dir = $this->getParameter('kernel.project_dir');
            $result = $this->resourceEdit->editResource($study, 'Study', $study_id, $auth, $project_dir);

            if ($result['success']) {
                $resources = $this->resourceRead->listResources(
                    $auth,
                    'Study',
                    null,
                    'read',
                    null,
                    $result['resources'][0]['public_id']
                );
                $this->publication->processPublications($publications);
                return new JsonResponse($resources[0]);
            }

            return new JsonResponse($result);
        } catch (Exception $e) {
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    #[Route('/submissions/{study_id}', name: 'put_submission', methods: ['PUT'])]
    #[OA\Put(
        path: '/api/submissions/{study_id}',
        summary: 'Update study submission',
        tags: ['Submissions'],
        parameters: [
            new OA\Parameter(
                name: 'study_id',
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
            new OA\Response(response: 200, description: 'Study updated successfully'),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 400, description: 'Invalid input')
        ]
    )]
    public function putSubmission(Request $request, Keycloak $auth, string $study_id): JsonResponse
    {
        if ($auth->isGuest()) {
            return new JsonResponse(['message' => 'Unauthorized'], 401);
        }

        $study = json_decode($request->getContent());

        try {
            $publications = [];
            if (isset($study->pubmed_ids) && is_array($study->pubmed_ids) && count($study->pubmed_ids)) {
                $publications = $this->publication->fetchPubmeds($study->pubmed_ids);
                $study->pubmed_ids = array_keys($publications);
            }

            $project_dir = $this->getParameter('kernel.project_dir');
            $result = $this->resourceEdit->editResource($study, 'Study', $study_id, $auth, $project_dir);

            if ($result['success']) {
                $this->publication->processPublications($publications);
                $resources = $this->resourceRead->listResources(
                    $auth,
                    'Study',
                    null,
                    'review',
                    null,
                    $result['resources'][0]['public_id']
                );
                return new JsonResponse($resources[0]);
            }

            return new JsonResponse($result);
        } catch (Exception $e) {
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    #[Route('/submissions/{study_id}', name: 'patch_submission', methods: ['PATCH'])]
    #[OA\Patch(
        path: '/api/submissions/{study_id}',
        summary: 'Patch submission status',
        tags: ['Submissions'],
        parameters: [
            new OA\Parameter(
                name: 'study_id',
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
            new OA\Response(response: 204, description: 'Status updated successfully'),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 400, description: 'Invalid input')
        ]
    )]
    public function patchSubmission(
        Request $request,
        Keycloak $auth,
        Validator $validator,
        string $study_id
    ): JsonResponse {
        if ($auth->isGuest()) {
            return $this->json(['message' => 'Unauthorized'], 401);
        }

        $user = $auth->getDetails();
        $patch = json_decode($request->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $field = $this->helper->checkUuid($study_id) ? 'study_id' : 'study_public_id';

        try {
            $this->resourceEdit->patchResource($study_id, $patch, $auth);

            if (!isset($patch['status_type_id'])) {
                return $this->json(null, 204);
            }

            $status = $patch['status_type_id'];
            if ($status === 'SUB') {
                $result = $this->submissionService->handleSubmission($auth, $study_id, $field, $user);
                if (!$result['success']) {
                    return $this->json($result['error'], $result['status']);
                }
            } elseif (!in_array($status, ['PUB', 'VER'])) {
                $this->submissionService->handleUnsubmission($auth, $study_id, $field, $user);
            }

            return $this->json(null, 204);
        } catch (\Throwable $e) {
            return $this->json(['error' => $e->getMessage()], 500);
        }
    }

    #[Route('/submissions/{study_id}', name: 'delete_submission', methods: ['DELETE'])]
    #[OA\Delete(
        path: '/api/submissions/{study_id}',
        summary: 'Delete submission',
        tags: ['Submissions'],
        parameters: [
            new OA\Parameter(
                name: 'study_id',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string')
            )
        ],
        responses: [
            new OA\Response(response: 200, description: 'Submission deleted successfully')
        ]
    )]
    public function deleteSubmission(string $study_id, Keycloak $auth, ResourceEditService $editResource): JsonResponse
    {
        $deleted_id = $editResource->setResourceStatus($auth, $study_id, 'DEL');
        return new JsonResponse($deleted_id);
    }

    #[Route('/submissions/{study_id}/users', name: 'post_submission_user', methods: ['POST'])]
    #[OA\Post(
        path: '/api/submissions/{study_id}/users',
        summary: 'Add user to submission',
        tags: ['Submissions'],
        parameters: [
            new OA\Parameter(
                name: 'study_id',
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
            new OA\Response(response: 200, description: 'User added successfully')
        ]
    )]
    public function postSubmissionUser(string $study_id, Request $request, Keycloak $auth): JsonResponse
    {
        if ($auth->isGuest()) {
            return new JsonResponse(['message' => 'Unauthorized'], 401);
        }

        $user = json_decode($request->getContent(), true);

        try {
            $study_user_view = $this->resourceEdit->editResourceUser($study_id, $user, $auth);
            return new JsonResponse($study_user_view);
        } catch (Exception $e) {
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    #[Route('/submissions/{study_id}/users/{user_id}', name: 'delete_submission_user', methods: ['DELETE'])]
    #[OA\Delete(
        path: '/api/submissions/{study_id}/users/{user_id}',
        summary: 'Remove user from submission',
        tags: ['Submissions'],
        parameters: [
            new OA\Parameter(
                name: 'study_id',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string')
            ),
            new OA\Parameter(
                name: 'user_id',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string')
            )
        ],
        responses: [
            new OA\Response(response: 200, description: 'User removed successfully')
        ]
    )]
    public function deleteSubmissionUser(string $study_id, string $user_id): JsonResponse
    {
        try {
            $study_user_view = $this->resourceEdit->deleteResourceUser($study_id, $user_id);
            return new JsonResponse($study_user_view);
        } catch (Exception $e) {
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    #[Route('/submissions/{study_id}/raw-files', name: 'get_raw_files', methods: ['GET'])]
    #[OA\Get(
        path: '/api/submissions/{study_id}/raw-files',
        summary: 'Get raw files for submission',
        tags: ['Submissions'],
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
                description: 'Raw files list',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(type: 'object'))
            ),
            new OA\Response(response: 401, description: 'Unauthorized')
        ]
    )]
    public function getRawFiles(string $study_id, Request $request, Keycloak $auth): JsonResponse
    {
        if ($auth->isGuest()) {
            return new JsonResponse(['message' => 'Unauthorized'], 401);
        }

        try {
            $files = $this->fileRead->getRawFiles($study_id, $auth);
            return new JsonResponse($files);
        } catch (Exception $e) {
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    #[Route('/submissions/{study_id}/analysis-files', name: 'get_analysis_files', methods: ['GET'])]
    #[OA\Get(
        path: '/api/submissions/{study_id}/analysis-files',
        summary: 'Get analysis files for submission',
        tags: ['Submissions'],
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
                description: 'Analysis files list',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(type: 'object'))
            ),
            new OA\Response(response: 401, description: 'Unauthorized')
        ]
    )]
    public function getAnalysisFiles(string $study_id, Request $request, Keycloak $auth): JsonResponse
    {
        if ($auth->isGuest()) {
            return new JsonResponse(['message' => 'Unauthorized'], 401);
        }

        try {
            $files = $this->fileRead->getAnalysisFiles($study_id, $auth);
            return new JsonResponse($files);
        } catch (Exception $e) {
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }
    }
}
