<?php

namespace App\Controller;

use App\Service\Auth\Keycloak;
use App\Service\Auth\KeycloakService;
use App\Service\Validation\ValidationService;
use OpenApi\Attributes as OA;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api')]
class ValidationController extends AbstractController
{
    private ValidationService $validationService;
    protected KeycloakService $keycloak;
    public function __construct(
        ValidationService $validationService
    ) {
        $this->validationService = $validationService;
    }

    /**
     * See SubmissionController::rethrowSafely() for the rationale (security audit H-8).
     * This controller returns arrays/JsonResponse rather than always throwing, so this
     * variant hands back a sanitized message string instead.
     */
    private function safeErrorMessage(\Throwable $e, LoggerInterface $logger, string $genericMessage): string
    {
        if ($e instanceof HttpExceptionInterface) {
            return $e->getMessage();
        }
        $code = $e->getCode();
        if ($code >= 400 && $code < 600) {
            return $e->getMessage();
        }
        $logger->error($genericMessage, ['exception' => $e]);
        return $genericMessage;
    }

    #[Route('/validate', name: 'validate_data', methods: ['POST'])]
    #[OA\Post(
        path: "/api/validate",
        summary: "Validate resource data",
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                type: "object",
                required: ["resource_type", "data"],
                properties: [
                    new OA\Property(property: "resource_type", type: "string", description: "Type of resource"),
                    new OA\Property(property: "data", type: "string", description: "JSON or delimited data to validate")
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: "Validation result",
                content: new OA\JsonContent(
                    type: "object",
                    properties: [
                        new OA\Property(property: "success", type: "boolean"),
                        new OA\Property(property: "message", type: "string"),
                        new OA\Property(property: "errors", type: "array", items: new OA\Items(type: "string")),
                        new OA\Property(property: "warnings", type: "array", items: new OA\Items(type: "string"))
                    ]
                )
            ),
            new OA\Response(
                response: 400,
                description: "Bad request"
            )
        ]
    )]
    public function validate(Request $request, Keycloak $auth, LoggerInterface $logger): JsonResponse
    {
        try {
            if ($auth->isGuest()) {
                return $this->json(['message' => 'Unauthorized'], 401);
            }            
            
            $content = json_decode($request->getContent(), true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Invalid JSON in request body',
                    'errors' => ['Invalid JSON: ' . json_last_error_msg()]
                ], 400);
            }

            if (!isset($content['resource_type']) || !isset($content['data'])) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Missing required fields',
                    'errors' => ['Both resource_type and data are required']
                ], 400);
            }

            $resourceType = $content['resource_type'];
            $data = $content['data'];

            $resourceTypeMap = [
                'Dataset' => 'Dataset',
                'MolecularAnalysis' => 'MolecularAnalysis',
                'MolecularExperiment' => 'MolecularExperiment',
                'MolecularRun' => 'MolecularRun',
                'Sample' => 'Sample',
                'Study' => 'Study',
            ];

            if (!isset($resourceTypeMap[$resourceType])) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Invalid resource type',
                    'errors' => ['Resource type must be one of: Dataset, MolecularAnalysis, MolecularExperiment, MolecularRun, Sample, Study']
                ], 400);
            }

            $resourceType = $resourceTypeMap[$resourceType];

            if (!$this->validationService->isCliValidationAvailable()) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Validation service is not available',
                    'errors' => ['CLI validation tool is not configured or available']
                ], 503);
            }

            $parsedData = json_decode($data, true);

            if (json_last_error() === JSON_ERROR_NONE) {
                $result = $this->validationService->validateResource($parsedData, $resourceType, 0);
            } else {
                $extension = (strpos($data, "\t") !== false) ? '.tsv' : '.csv';
                $tempFile = tempnam(sys_get_temp_dir(), 'fega_validation_') . $extension;
                file_put_contents($tempFile, $data);

                try {
                    $result = $this->validationService->validateFile($tempFile, $resourceType, 0, 'new');
                } finally {
                    if (file_exists($tempFile)) {
                        unlink($tempFile);
                    }
                }
            }

            return new JsonResponse($result);
        } catch (\Exception $e) {
            $message = $this->safeErrorMessage($e, $logger, 'Validation error occurred');
            return new JsonResponse([
                'success' => false,
                'message' => $message,
                'errors' => [$message]
            ], 500);
        }
    }

    #[Route('/validate/bundle', name: 'validate_bundle', methods: ['POST'])]
    #[OA\Post(
        path: "/api/validate/bundle",
        summary: "Validate a submission bundle",
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: "multipart/form-data",
                schema: new OA\Schema(
                    type: "object",
                    required: ["bundle"],
                    properties: [
                        new OA\Property(
                            property: "bundle",
                            type: "string",
                            format: "binary",
                            description: "ZIP file containing a submission bundle"
                        )
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: "Validation result",
                content: new OA\JsonContent(
                    type: "object",
                    properties: [
                        new OA\Property(property: "success", type: "boolean"),
                        new OA\Property(property: "message", type: "string"),
                        new OA\Property(property: "errors", type: "array", items: new OA\Items(type: "string"))
                    ]
                )
            ),
            new OA\Response(
                response: 400,
                description: "Bad request"
            )
        ]
    )]
    public function validateBundle(Request $request, Keycloak $auth, LoggerInterface $logger): JsonResponse
    {
        try {
            if ($auth->isGuest()) {
                return $this->json(['message' => 'Unauthorized'], 401);
            }
            if (!$auth->hasRole('submitter')) {
                return $this->json(['message' => 'Forbidden'], 403);
            }

            $uploadedFile = $request->files->get('bundle');

            if (!$uploadedFile || !$uploadedFile->isValid()) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'No file uploaded',
                    'errors' => ['Please upload a ZIP file']
                ], 400);
            }

            // Bound the input explicitly rather than relying on php.ini alone.
            if ($uploadedFile->getSize() > 50 * 1024 * 1024) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Bundle exceeds 50 MB',
                    'errors' => ['Uploaded file is too large']
                ], 413);
            }

            // Verify actual content, not the client-supplied filename/extension.
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime  = finfo_file($finfo, $uploadedFile->getPathname());
            finfo_close($finfo);
            if ($mime !== 'application/zip') {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Invalid file type',
                    'errors' => ['Only ZIP files are supported for submission bundle validation']
                ], 400);
            }

            if (!$this->validationService->isCliValidationAvailable()) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Validation service is not available',
                    'errors' => ['CLI validation tool is not configured or available']
                ], 503);
            }

            $filePath = $uploadedFile->getPathname();

            $result = $this->validationService->validateFile($filePath, 'SubmissionBundle', 0, 'new');

            return new JsonResponse($result);
        } catch (\Exception $e) {
            $message = $this->safeErrorMessage($e, $logger, 'Validation error occurred');
            return new JsonResponse([
                'success' => false,
                'message' => $message,
                'errors' => [$message]
            ], 500);
        }
    }

    /**
     * Validate delimited data
     */
    private function validateDelimitedData(string $data, string $resourceType, LoggerInterface $logger): array
    {
        try {
            // Detect delimiter
            $delimiter = "\t";
            if (strpos($data, "\t") === false && strpos($data, ",") !== false) {
                $delimiter = ",";
            }

            // Parse delimited data
            $lines = explode("\n", trim($data));
            if (count($lines) < 2) {
                return [
                    'success' => false,
                    'message' => 'Invalid delimited data format',
                    'errors' => ['Data must contain at least a header row and one data row']
                ];
            }

            // Parse header
            $headers = str_getcsv($lines[0], $delimiter);

            // Parse data rows
            $records = [];
            for ($i = 1; $i < count($lines); $i++) {
                if (empty(trim($lines[$i]))) {
                    continue;
                }

                $values = str_getcsv($lines[$i], $delimiter);

                if (count($values) !== count($headers)) {
                    return [
                        'success' => false,
                        'message' => 'Column count mismatch',
                        'errors' => ["Row " . ($i + 1) . " has " . count($values) . " columns, expected " . count($headers)]
                    ];
                }

                $record = array_combine($headers, $values);
                $records[] = $record;
            }

            // Validate each record
            $allErrors = [];
            $successCount = 0;

            foreach ($records as $index => $record) {
                $result = $this->validationService->validateResource($record, $resourceType, 0);

                if (!$result['success']) {
                    $rowNum = $index + 2;
                    if (isset($result['errors'])) {
                        foreach ($result['errors'] as $error) {
                            $allErrors[] = "Row $rowNum: $error";
                        }
                    }
                } else {
                    $successCount++;
                }
            }

            if (count($allErrors) > 0) {
                return [
                    'success' => false,
                    'message' => "Validation completed with errors. $successCount of " . count($records) . " records are valid.",
                    'errors' => $allErrors
                ];
            }

            return [
                'success' => true,
                'message' => "All $successCount records validated successfully",
                'errors' => []
            ];
        } catch (\Exception $e) {
            $message = $this->safeErrorMessage($e, $logger, 'Error parsing delimited data');
            return [
                'success' => false,
                'message' => $message,
                'errors' => [$message]
            ];
        }
    }
}
