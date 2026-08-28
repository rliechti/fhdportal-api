<?php

namespace App\Controller;

use App\Service\Auth\Keycloak;
use App\Service\Auth\KeycloakService;
use App\Service\Dac\PolicyService;
use App\Service\File\FileReadService;
use App\Service\JsonSchema\Validator;
use App\Service\PublicationService;
use App\Service\Resource\ResourceEditService;
use App\Service\Resource\ResourceExportService;
use App\Service\Resource\ResourceReadService;
use App\Service\Resource\ResourceTemplateService;
use App\Service\SubmissionService;
use App\Service\Utility\GeneralHelperService;
use App\Service\Validation\SubmissionHealthCheckService;
use Exception;
use MeekroDB;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\SerializerInterface;
use Psr\Log\LoggerInterface;
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
        private PolicyService $policy,
        private KeycloakService $keycloak,
        private SubmissionService $submissionService,
        private SubmissionHealthCheckService $submissionHealthCheck
    ) {}

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
        $status = $request->query->get('status') ?? 'draft,revised,submitted,re-submitted';
        $submissions = $readResource->listResources($auth, 'Study', null, 'review', $status);

        if ($status === 'published') {
            $submissions = array_map(fn($s) => [
                'id'           => $s['id'],
                'public_id'    => $s['public_id'],
                'title'        => $s['title'],
                'study_type'   => $s['properties']['study_type'] ?? null,
                'released_date' => $s['released_date'],
                'nb_datasets'  => (int)($s['nb_public_datasets'] ?? 0)
            ], $submissions);
        }

        return new JsonResponse($submissions);
    }

    /**
     * Exceptions deliberately raised with an HTTP-range code (either a real
     * HttpExceptionInterface, or this codebase's common `new Exception($msg, $httpCode)`
     * convention) propagate with their original status and message - that message was
     * authored to be shown. Anything else (a DB error, a null-dereference, ...) is logged
     * in full and replaced with a generic message before it reaches the client
     * (security audit H-8: these catch blocks used to echo $e->getMessage() verbatim
     * under a hardcoded 500, regardless of what the exception actually carried).
     */
    private function rethrowSafely(\Throwable $e, LoggerInterface $logger, string $genericMessage): never
    {
        if ($e instanceof HttpExceptionInterface) {
            throw $e;
        }
        $code = $e->getCode();
        if ($code >= 400 && $code < 600) {
            throw new HttpException($code, $e->getMessage(), $e);
        }
        $logger->error($genericMessage, ['exception' => $e]);
        throw new HttpException(500, $genericMessage);
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

        $binaries = array("fega-linux","fega-macos-arm","fega-macos-x64","fega-windows.exe");
        if (!in_array($binary,$binaries,true)){
            throw new NotFoundHttpException('CLI binary not valid: ' . $binary);
        }
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

    #[Route('/submissions/{study_id}/check', name: 'check_submission', methods: ['GET'])]
    #[OA\Get(
        path: '/api/submissions/{study_id}/check',
        summary: 'Run a data integrity/consistency health check on a submission',
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
                description: 'Health check result',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean'),
                        new OA\Property(property: 'message', type: 'string'),
                        new OA\Property(property: 'errors', type: 'array', items: new OA\Items(type: 'string')),
                        new OA\Property(property: 'warnings', type: 'array', items: new OA\Items(type: 'string')),
                        new OA\Property(property: 'issues', type: 'array', items: new OA\Items(type: 'object'))
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 404, description: 'Submission not found')
        ]
    )]
    public function checkSubmission(Keycloak $auth, string $study_id): JsonResponse
    {
        if ($auth->isGuest()) {
            return $this->json(['message' => 'Unauthorized'], 401);
        }

        $study = $this->resourceRead->getResource($auth, 'Study', $study_id, 'read');
        if (isset($study['error'])) {
            return $this->json($study['error'], $study['error']['status'] ?? 404);
        }

        $result = $this->submissionHealthCheck->check($study['id'], $study['properties']['public_id'] ?? null);
        return new JsonResponse($result);
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
    public function getPubmeds(string $pmid, Keycloak $auth): JsonResponse
    {
        if ($auth->isGuest()) {
            return new JsonResponse(['message' => 'Unauthorized'], 401);
        }
        $pubmeds = $this->publication->fetchPubmeds($pmid);
        return new JsonResponse($pubmeds);
    }

    #[Route('/submissions/upload-study', name: 'upload_study', methods: ['POST'])]
    #[OA\Post(
        path: '/api/submissions/upload-study',
        summary: 'Upload new study from file',
        description: 'Response is newline-delimited JSON (one JSON object per line): zero or more '
            . '{"type":"progress","phase":"validating|importing","current":n,"total":n,"resource_type":"..."} '
            . 'lines while processing, followed by a final '
            . '{"type":"result","data":{...}} or {"type":"error","message":"..."} line.',
        tags: ['Submissions'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(type: 'object')
        ),
        responses: [
            new OA\Response(response: 200, description: 'Newline-delimited JSON progress/result stream'),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 400, description: 'Invalid input')
        ]
    )]
    public function uploadStudy(Request $request, Keycloak $auth, LoggerInterface $logger): Response
    {
        if ($auth->isGuest()) {
            $response = new StreamedResponse(function () {
                echo json_encode(['type' => 'error', 'message' => 'Unauthorized']) . "\n";
            }, 401);
            $response->headers->set('Content-Type', 'application/x-ndjson');
            return $response;
        }

        $content = $request->request->all();
        $project_dir = $this->getParameter('kernel.project_dir');

        $response = new StreamedResponse(function () use ($request, $auth, $project_dir, $content, $logger) {
            $emit = function (array $event) {
                echo json_encode($event) . "\n";
                if (ob_get_level() > 0) {
                    @ob_flush();
                }
                flush();
            };

            try {
                $onProgress = function (string $phase, int $current, int $total, ?string $resourceType) use ($emit) {
                    $emit([
                        'type' => 'progress',
                        'phase' => $phase,
                        'current' => $current,
                        'total' => $total,
                        'resource_type' => $resourceType,
                    ]);
                };

                $uploadResponse = $this->resourceEdit->uploadResources($auth, 'new', $request, $project_dir, $content, $onProgress);
                $emit(['type' => 'result', 'data' => $this->normalizeUploadResult($uploadResponse)]);
            } catch (Exception $e) {
                // Same "only a deliberately client-facing message is shown" rule as
                // rethrowSafely() - can't actually throw here, headers are already sent.
                $code = $e->getCode();
                if ($e instanceof HttpExceptionInterface) {
                    $message = $e->getMessage();
                } elseif ($code >= 400 && $code < 600) {
                    $message = $e->getMessage();
                } else {
                    $logger->error('Study upload failed', ['exception' => $e]);
                    $message = 'Unable to process upload';
                }
                $emit(['type' => 'error', 'message' => $message]);
            }
        });

        // Progress is only useful if it reaches the browser incrementally: no gzip/proxy buffering.
        $response->headers->set('Content-Type', 'application/x-ndjson');
        $response->headers->set('X-Accel-Buffering', 'no');
        $response->headers->set('Cache-Control', 'no-cache');
        return $response;
    }

    /**
     * uploadResources() returns a JsonResponse, a JSON string, or a raw array depending
     * on which branch it took. Normalize all three into a plain array for the "result" event.
     */
    private function normalizeUploadResult(mixed $uploadResponse): array
    {
        if ($uploadResponse instanceof JsonResponse) {
            $decoded = json_decode($uploadResponse->getContent(), true);
            return [
                'success' => false,
                'message' => is_array($decoded) ? ($decoded['message'] ?? $uploadResponse->getContent()) : $uploadResponse->getContent(),
            ];
        }
        if (is_string($uploadResponse)) {
            $decoded = json_decode($uploadResponse, true);
            return is_array($decoded) ? $decoded : ['success' => true, 'message' => $uploadResponse];
        }
        if (is_array($uploadResponse)) {
            return $uploadResponse;
        }
        return ['success' => false, 'message' => 'Unexpected upload response'];
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
    public function postSubmission(Request $request, Keycloak $auth, LoggerInterface $logger): JsonResponse
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
            $this->rethrowSafely($e, $logger, 'Unable to create submission');
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
    public function putSubmission(Request $request, Keycloak $auth, string $study_id, LoggerInterface $logger): JsonResponse
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
            $this->rethrowSafely($e, $logger, 'Unable to update submission');
        }
    }

    #[Route('/submissions/{study_id}/version', name: 'create_submission_version', methods: ['PUT'])]
    #[OA\Put(
        path: '/api/submissions/{study_id}/version',
        summary: 'Register a new version of a submission',
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
            new OA\Response(response: 200, description: 'Study version created successfully'),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 400, description: 'Invalid input')
        ]
    )]
    public function createSubmissionVersion(Request $request, Keycloak $auth, string $study_id, LoggerInterface $logger): JsonResponse
    {
        if ($auth->isGuest()) {
            return new JsonResponse(['message' => 'Unauthorized'], 401);
        }

        try {
            $resourceId = $this->resourceEdit->setResourceStatus($auth, $study_id, 'REV');

            if ($resourceId) {
                $resources = $this->resourceRead->listResources(
                    $auth,
                    'Study',
                    null,
                    'review',
                    null,
                    $resourceId
                );
                return new JsonResponse($resources[0]);
            }

            return new JsonResponse(null, 204);
        } catch (Exception $e) {
            $this->rethrowSafely($e, $logger, 'Unable to create submission version');
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
        string $study_id,
        LoggerInterface $logger
    ): JsonResponse {
        if ($auth->isGuest()) {
            return $this->json(['message' => 'Unauthorized'], 401);
        }

        $user = $auth->getDetails();
        $patch = json_decode($request->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $field = $this->helper->checkUuid($study_id) ? 'study_id' : 'study_public_id';

        try {
            $status = $patch['status_type_id'] ?? null;

            $oldStatus = null;
            if ($status === 'SUB' || $status === 'RES') {
                $resolved = $this->resourceRead->getResource($auth, 'Study', $study_id, 'edit');
                if (isset($resolved['error'])) {
                    return $this->json($resolved['error'], $resolved['error']['status'] ?? 403);
                }
                $health = $this->submissionHealthCheck->check($resolved['id'], $resolved['properties']['public_id'] ?? null);
                if (!$health['success']) {
                    return $this->json($health, 400);
                }

                $dbField = $this->helper->checkUuid($study_id) ? 'id' : "properties->>'public_id'";
                $oldStatus = $this->db->queryFirstField(
                    "SELECT status_type_id FROM resource WHERE $dbField = %s",
                    $study_id
                );
            }

            $this->resourceEdit->patchResource($study_id, $patch, $auth);
            if (!isset($patch['status_type_id'])) {
                return $this->json(null, 204);
            }

            if ($status === 'SUB' || $status === 'RES') {
                $result = $this->submissionService->handleSubmission($auth, $study_id, $field, $user);
                if (!$result['success']) {
                    if ($oldStatus) {
                        $this->resourceEdit->setResourceStatus($auth, $study_id, $oldStatus);
                    }
                    return $this->json($result['error'], $result['status']);
                }
            } elseif (!in_array($status, ['PUB', 'VER'])) {
                $this->submissionService->handleUnsubmission($auth, $study_id, $field, $user);
            }

            return $this->json(null, 204);
        } catch (\Throwable $e) {
            if (!empty($oldStatus)) {
                $this->resourceEdit->setResourceStatus($auth, $study_id, $oldStatus);
            }
            $this->rethrowSafely($e, $logger, 'Unable to update submission status');
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
    public function postSubmissionUser(string $study_id, Request $request, Keycloak $auth, LoggerInterface $logger): JsonResponse
    {
        if ($auth->isGuest()) {
            return new JsonResponse(['message' => 'Unauthorized'], 401);
        }

        $user = json_decode($request->getContent(), true);

        try {
            $study_user_view = $this->resourceEdit->editResourceUser($study_id, $user, $auth);
            return new JsonResponse($study_user_view);
        } catch (Exception $e) {
            $this->rethrowSafely($e, $logger, 'Unable to add user to submission');
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
    public function deleteSubmissionUser(string $study_id, string $user_id, Keycloak $auth, LoggerInterface $logger): JsonResponse
    {
        try {
             if ($auth->isGuest()) return new JsonResponse(['message'=>'Unauthorized'], 401);
            $study_user_view = $this->resourceEdit->deleteResourceUser($study_id, $user_id, $auth);
            return new JsonResponse($study_user_view);
        } catch (Exception $e) {
            $this->rethrowSafely($e, $logger, 'Unable to remove user from submission');
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
    public function getRawFiles(string $study_id, Request $request, Keycloak $auth, LoggerInterface $logger): JsonResponse
    {
        if ($auth->isGuest()) {
            return new JsonResponse(['message' => 'Unauthorized'], 401);
        }

        try {
            $files = $this->fileRead->getRawFiles($study_id, $auth);
            return new JsonResponse($files);
        } catch (Exception $e) {
            $this->rethrowSafely($e, $logger, 'Unable to retrieve raw files');
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
    public function getAnalysisFiles(string $study_id, Request $request, Keycloak $auth, LoggerInterface $logger): JsonResponse
    {
        if ($auth->isGuest()) {
            return new JsonResponse(['message' => 'Unauthorized'], 401);
        }

        try {
            $files = $this->fileRead->getAnalysisFiles($study_id, $auth);
            return new JsonResponse($files);
        } catch (Exception $e) {
            $this->rethrowSafely($e, $logger, 'Unable to retrieve analysis files');
        }
    }
}
