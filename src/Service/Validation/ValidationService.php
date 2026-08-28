<?php

namespace App\Service\Validation;

use App\Service\Validation\CliValidator;
use MeekroDB;
use Exception;
use Psr\Log\LoggerInterface;

/**
 * Validation service that uses the FEGA CLI tool for validation
 */
class ValidationService
{
    private CliValidator $cliValidator;
    private LoggerInterface $logger;
    protected MeekroDB $db;

    public function __construct(
        CliValidator $cliValidator,
        MeekroDB $db,
        LoggerInterface $logger
    ) {
        $this->cliValidator = $cliValidator;
        $this->db = $db;
        $this->logger = $logger;
    }

    /**
     * Validate resource data
     *
     * @param mixed $data The data to validate
     * @param string $resourceType The type of resource
     * @return array Validation result with 'success', 'message', and 'errors' keys
     */
    public function validateResource(mixed $data, string $resourceType, int $userId): array
    {
        try {
            if (!$this->cliValidator->isAvailable()) {
                throw new Exception('CLI validation tool is not available');
            }

            $this->logger->info('Using CLI validator for resource validation', [
                'resource_type' => $resourceType
            ]);
            $result = $this->cliValidator->validateResourceData($data, $resourceType);
            if ($userId && $userId !== 0) {
                $resultXRef = $this->validateXRefs($result,$data, $resourceType, $userId,1);
                if ($resultXRef['status'] !== 'SUCCESS') {
                    return [
                        'success' => false,
                        'message' => $resultXRef['message'],
                        'errors' => $resultXRef['errors'],
                        'exit_code' => 1,
                        'debug' => null
                    ];
                }
            }


            if ($result['success']) {
                $this->logger->info('CLI validation successful', [
                    'resource_type' => $resourceType
                ]);
            } else {
                $this->logger->warning('CLI validation failed', [
                    'resource_type' => $resourceType,
                    'errors' => $result['errors'] ?? []
                ]);
            }

            return $result;
        } catch (Exception $e) {
            $this->logger->error('Validation error occurred', [
                'resource_type' => $resourceType,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'message' => 'Validation error: ' . $e->getMessage(),
                'errors' => [$e->getMessage()]
            ];
        }
    }

    /**
     * Validate a file
     *
     * @param string $filePath Path to the file to validate
     * @param string $resourceType Type of resource
     * @return array Validation result
     */
    public function validateFile(string $filePath, string $resourceType, int $userId, string $studyId): array
    {
        try {
            if (!$this->cliValidator->isAvailable()) {
                throw new Exception('CLI validation tool is not available');
            }

            $this->logger->info('Using CLI validator for file validation', [
                'file_path' => $filePath,
                'resource_type' => $resourceType
            ]);
            $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

            $data = $this->cliValidator->validateFile($filePath, $resourceType, 'json');
			
            // we don't need to validate json files has they have been already validated in ResourceEditService::editResource or if studyId == new

            if ($userId && $userId !== 0 && $data['success'] && $extension !== 'json' ) {
            // if ($userId && $userId !== 0 && $data['success'] && $extension !== 'json' && $studyId !== 'new') {
                if ($resourceType == 'SubmissionBundle') {
                    foreach ($data['result']['output'] as $resourceType => $resourceData) {
                        foreach ($resourceData['data'] as $d) {
                            $resultXRef = $this->validateXRefs($data['result']['output'], $d['data'], $resourceType, $userId, $studyId);
                            if ($resultXRef['status'] !== 'SUCCESS') {
								foreach($data['result']['output'] as $rt=>$rd){
									if($rt == $resourceType){
										$data['result']['output'][$rt]['status']='FAIL';
										$data['result']['output'][$rt]['message']=$resultXRef['message'].".  " . implode(", ",$resultXRef['errors']);
									}
								}
                                $data['result']['status'] = 'FAIL';
                                $data['result']['success'] = false;
                                $data['result']['message'] = $resultXRef['message'];
	                            return $data['result'];
							}
                        }
                    }
					return $data;
                } else {
                    foreach ($data['result']['data'] as $d) {
                        $resultXRef = $this->validateXRefs($data['result']['data'],$d['data'], $resourceType, $userId, $studyId);
                        if ($resultXRef['status'] !== 'SUCCESS') {
                            $data['success'] = false;
                            $data['result']['status'] = 'FAIL';
                            $data['message'] = $resultXRef['message'];
                            $data['result']['message'] = $resultXRef['message'];
                            $data['errors'] = $resultXRef['errors'];
                        }
                    }
					return $data;
				}
            }
            return $data;

        } catch (Exception $e) {
            $this->logger->error('File validation error occurred', [
                'file_path' => $filePath,
                'resource_type' => $resourceType,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'File validation error: ' . $e->getMessage(),
                'errors' => [$e->getMessage()]
            ];
        }
    }

    /**
     * Check if CLI validation is available
     *
     * @return bool True if CLI validation can be used
     */
    public function isCliValidationAvailable(): bool
    {
        return $this->cliValidator->isAvailable();
    }

    /**
     * Get validation status information
     *
     * @return array Status information
     */
    public function getValidationStatus(): array
    {
        return [
            'cli_tool_available' => $this->cliValidator->isAvailable(),
            'cli_path' => $this->cliValidator->getCliPath()
        ];
    }

    public function validateXRefs(mixed $output, mixed $data, string $resourceType, int $userId, string $studyId): array
    {
        $return = array(
            "status" => "SUCCESS",
			"success"=>true,
            "message" => "",
            "errors" => array()
        );

        $data = is_string($data) ? json_decode($data, true) : $data;
		

        $xResourceSchemaJson = $this->db->queryFirstField(
            "
            SELECT resource_type.properties -> 'data_schema' -> 'x-resource' ->> 'schema'
            FROM resource_type
            WHERE \"name\" = %s;",
            $resourceType
        );
        $xResourceSchema = json_decode($xResourceSchemaJson, true) ?: [];

        // check if files are in the list (doesn't have a foreign key in the schema)
        $xrefsFields = array();
        if (isset($xResourceSchema['fields'])) {
            foreach ($xResourceSchema['fields'] as $xResourceField) {
                if (isset($xResourceField['name']) && str_starts_with($xResourceField['name'], 'file') && strpos($xResourceField['name'], '_') === false && isset($data[$xResourceField['name']])) {
                    $xrefsFields[] = array(
                        "title" => $xResourceField['name'],
                        "aliasOf" => $xResourceField['aliasOf'],
                        "db_view" => "sdafile_view",
                        "resource" => "File",
                        "reference" => "title"
                    );
                }
                if (isset($xResourceField['aliasOf']) && str_starts_with($xResourceField['aliasOf'], 'sdafile_public_id') && isset($data[$xResourceField['aliasOf']])) {
                    $xrefsFields[] = array(
                        "title" => $xResourceField['name'],
                        "aliasOf" => $xResourceField['aliasOf'],
                        "db_view" => "sdafile_view",
                        "resource" => "File",
                        "reference" => "title"
                    );
                }
            }
        }
        if (isset($xResourceSchema['foreignKeys'])) {
            foreach ($xResourceSchema['foreignKeys'] as $foreignKey) {
                $xrefsFields[] = array(
                    "title" => $foreignKey['fields'][0],
                    "aliasOf" => strtolower($foreignKey['reference']['resource'])."_public_id",
                    "db_view" => strtolower($foreignKey['reference']['resource'])."_view",
                    "resource" => $foreignKey['reference']['resource'],
                    "reference" => $foreignKey['reference']['fields'][0] ?? 'title'
                );
            }
        }

        $dbTables = $this->db->tableList();

        foreach ($xrefsFields as $xrefsField) {

            if (!in_array($xrefsField['db_view'], $dbTables)) {
                continue;
            }
            $field = "";
            $sqlField = "";
            $values2check = array();
            if (isset($data[$xrefsField['title']])) {
                $field = $xrefsField['title'];
                $sqlField = $xrefsField['reference'];
            } elseif (isset($data[$xrefsField['aliasOf']])) {
                $field = $xrefsField['aliasOf'];
                $sqlField = "public_id";
            }
            if ($field && $data[$field]) {
                if (is_array($data[$field])) {
                    $values2check = $data[$field];
                } else {
                    $values2check[] = $data[$field];
                }
                foreach ($values2check as $value) {
					$xref_id = null;
					preg_match('/CHFEG[A-Z]{2}\d{11}/', $value, $outputregex);
					if(!count($outputregex) && $sqlField =='public_id') $sqlField = 'title';

					if($studyId == 'new' && $xrefsField['db_view'] != 'sdafile_view'){
						foreach($output[$xrefsField['resource']]['data'] as $d){
							if($d['data'][$sqlField]==$value){
								$xref_id = true;
							}
						}
					}
					else{
	                    $xref_id = $this->db->queryFirstField(
	                        "SELECT
	                        id
	                    FROM
	                    ".$xrefsField['db_view']."
	                    inner join resource_acl on ".$xrefsField['db_view'].".id = resource_acl.resource_id and resource_acl.role_id IN ('OWN', 'WRI')
	                    WHERE
	                    ".$sqlField." = %s_value
	                    and resource_acl.user_id = %i_userId;
	                    ",
	                        array("value" => $value, "userId" => $userId)
	                    );
					}

                    if (!$xref_id) {
                        $return['status'] = "FAIL";
                        $return['message'] = "At least one referenced resource couldn't be linked";
                        $return['errors'][] = $xrefsField['resource'].": ".$value." doesn't exist, or cannot be linked to this resource: ".$userId." : ".$value;
                    }
                }
            }
        }

        return $return;
    }

}
