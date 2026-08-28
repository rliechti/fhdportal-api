<?php

namespace App\Service\Resource;

use App\Service\Auth\Keycloak;
use App\Service\File\FileImportHelper;
use App\Service\Validation\ValidationService;
use Exception;
use MeekroDB;
use Symfony\Component\HttpFoundation\JsonResponse;

class ResourceImportService
{
    private MeekroDB $db;
    private ValidationService $validation;
    private FileImportHelper $fileHelper;
    private ResourceEditService $resourceEdit; // Circular dependency to be resolved or managed
    private ResourceReadService $resourceRead;

    public function __construct(
        MeekroDB $db,
        ValidationService $validation,
        FileImportHelper $fileHelper,
        ResourceReadService $resourceRead
        )
    {
        $this->db = $db;
        $this->validation = $validation;
        $this->fileHelper = $fileHelper;
        $this->resourceRead = $resourceRead;
    }

    public function setResourceEditService(ResourceEditService $resourceEdit): void
    {
        $this->resourceEdit = $resourceEdit;
    }

    /**
     * Import resource(s) from file into database.
     *
     * @param string $studyId Study ID or 'new'
     * @param string $filePath Path to import file
     * @param string $email User email
     * @param string $resourceType Resource type name (for non-ZIP)
     * @param callable|null $onProgress Called as ($phase, $current, $total, $resourceType) to report progress
     * @return array ['success' => bool, 'message' => string, 'resource_count' => int|null, 'resources' => array]
     */
    public function importResource(string $studyId, string $filePath, string $email, string $resourceType, int $userId, ?callable $onProgress = null): array
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $mimeType = mime_content_type($filePath);


        // Handle TSV/Excel/JSON uniformly
        if (in_array($extension, ['tsv', 'xls', 'xlsx']) || in_array($mimeType, [
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/json',
        ])) {
            $tsvPath = ($extension !== 'tsv' && $extension !== 'json') ? $this->fileHelper->convertXlsxToTsv($filePath, $resourceType) : $filePath;
            $tempFile = $tsvPath !== $filePath;
            try {
                if ($onProgress) {
                    $onProgress('validating', 0, 0, $resourceType);
                }
                $validationResult = $this->validation->validateFile($tsvPath, $resourceType, $userId, $studyId);
                if (!$validationResult['success']) {
                    return $validationResult;
                }
                $resourceData = $this->fileHelper->parseTsvOrJson($tsvPath, $extension);
                if (empty($resourceData)) {
                    return ['success' => false, 'message' => 'No valid data found in file', 'resource_count' => 0];
                }
                $rowProgress = $onProgress
                    ? function (int $current, int $total, string $rt) use ($onProgress) {
                        $onProgress('importing', $current, $total, $rt);
                    }
                    : null;
				return $this->resourceEdit->insertResourceData($resourceData, $resourceType, $studyId, $rowProgress);
			}
            finally {
                if ($tempFile && file_exists($tsvPath)) {
                    unlink($tsvPath);
                }
            }
        }

        // Handle ZIP bundles
        if ($extension === 'zip' || $mimeType === 'application/zip') {
            return $this->importZipBundle($filePath, $studyId, $userId, $onProgress);
        }

        return ['success' => false, 'message' => 'Unsupported file format', 'resource_count' => 0];
    }

    /**
     * Import ZIP submission bundle with ranked resource types.
     *
     * @param callable|null $onProgress Called as ($phase, $current, $total, $resourceType) to report progress
     */
    public function importZipBundle(string $filePath, string $initialStudyId, int $userId, ?callable $onProgress = null): array
    {
        if ($onProgress) {
            $onProgress('validating', 0, 0, null);
        }
        $validationResult = $this->validation->validateFile($filePath, 'SubmissionBundle', $userId, $initialStudyId) ;

        if (!$validationResult['success'] || !isset($validationResult['result'])) {
            return $validationResult;
        }

        $validatedResources = $validationResult['result']['output'] ?? [];
        $resourceTypeRanks = $this->db->queryFirstColumn(
            'SELECT "name" FROM resource_type WHERE rank IS NOT NULL ORDER BY rank'
        );

        // Pre-compute the total row count across all resource types so progress
        // reflects the whole bundle rather than resetting per resource type.
        $totalRows = 0;
        foreach ($resourceTypeRanks as $rt) {
            if (isset($validatedResources[$rt]['data'])) {
                $totalRows += count($validatedResources[$rt]['data']);
            }
        }

        $outputResources = [];
        $studyId = $initialStudyId;
        $processedRows = 0;
        foreach ($resourceTypeRanks as $rt) {
            if (!isset($validatedResources[$rt])) {
                continue;
            }
            $resourceResult = $validatedResources[$rt];
            if (!isset($resourceResult['status']) || $resourceResult['status'] !== 'SUCCESS') {
                return [
                    'success' => false,
                    'message' => "Error inserting {$rt}: " . ($resourceResult['message'] ?? ''),
                    'resource_count' => $resourceResult['totalRows'] ?? 0,
                ];
            }

            $resourceData = array_map(fn($a) => $a['data'] ?? [], $resourceResult['data'] ?? []);
            $rowsInType = count($resourceData);
            $rowProgress = $onProgress
                ? function (int $current, int $total, string $resourceType) use ($onProgress, $processedRows, $totalRows) {
                    $onProgress('importing', $processedRows + $current, $totalRows, $resourceType);
                }
                : null;
            $inserted = $this->resourceEdit->insertResourceData($resourceData, $rt, $studyId, $rowProgress);
            $processedRows += $rowsInType;
            $outputResources[$rt] = $inserted;

            if ($rt === 'Study') {
                $studyId = $inserted['resources'][0]['id'] ?? $studyId;
            }
        }
		$return = array(
            'success' => true,
            'message' => 'Bundle imported successfully',
            'resource_count' => array_sum(array_column($outputResources, 'resource_count')),
            'resources' => $outputResources,
		);

        return $return;
    }
}