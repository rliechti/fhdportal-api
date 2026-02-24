<?php

namespace App\Service\Resource;

use App\Service\Auth\Keycloak;
use App\Service\File\FileImportHelper;
use App\Service\JsonSchema\Validator;
use App\Service\Resource\ResourceImportService;
use App\Service\Resource\ResourceRelationshipService;
use App\Service\Resource\ResourceReadService;
use App\Service\Utility\GeneralHelperService;
use App\Service\Validation\ValidationService;
use Exception;
use MeekroDB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Service responsible for editing resource statuses.
 */
final class ResourceEditService
{
    private MeekroDB $db;
    private Keycloak $auth;
    private GeneralHelperService $helper;
    private ResourceReadService $resource;
    private Validator $validator;
    private SerializerInterface $serializer;
    private ValidationService $validation;
    private FileImportHelper $fileHelper;
    private ResourceRelationshipService $relationshipService;
    private ResourceImportService $importService;

    public function __construct(
        MeekroDB $db,
        Keycloak $auth,
        GeneralHelperService $helper,
        ResourceReadService $resource,
        Validator $validator,
        SerializerInterface $serializer,
        ValidationService $validation,
        FileImportHelper $fileHelper,
        ResourceRelationshipService $relationshipService,
        ResourceImportService $importService
    ) {
        $this->db = $db;
        $this->auth = $auth;
        $this->helper = $helper;
        $this->resource = $resource;
        $this->validator = $validator;
        $this->validation = $validation;
        $this->serializer = $serializer;
        $this->fileHelper = $fileHelper;
        $this->relationshipService = $relationshipService;
        $this->importService = $importService;

        // resolve circular dependency
        $this->importService->setResourceEditService($this);
    }











    private function getResourceTypePrimaryKeys($resourceTypeId): array
    {
        $primaryKeys = array();
        $jsonPrimaryKeys = $this->db->queryFirstField(
            "SELECT resource_type.properties -> 'data_schema' -> 'x-resource' -> 'schema' ->> 'primaryKey'
                 FROM resource_type
                 WHERE id = %i;",
            $resourceTypeId
        );

        if ($jsonPrimaryKeys) {
            $primaryKeys = json_decode($jsonPrimaryKeys, true);
        }
        return $primaryKeys;
    }

    /**
     * Insert a resource into the database from properties
     *
     * @param string $studyId     Study ID or 'new'
     * @param array  $resourceType Resource type data with 'id' and 'name' keys
     * @param object $properties  Resource properties object
     * @param int    $userId      User ID performing the action
     * @param string $roleId      ACL role ID for creator
     * @return array ['action_type_id' => 'CRE'|'MOD', 'public_id' => string]
     */
    private function insertResourceFromProperties(string $studyId, array $resourceType, object $properties, int $userId, string $roleId): array
    {

        $data = [
            'id' => null,
            'properties' => null,
            'resource_type_id' => $resourceType['id'],
            'status_type_id' => $this->db->queryFirstField("SELECT id FROM status_type WHERE name = 'draft'")
        ];

        $propertiesArray = (array)$properties;
        $actionTypeId = 'CRE';
        // Check if resource already exists by ID
        if (!empty($propertiesArray['id'])) {
            $actionTypeId = 'MOD';
            $data['id'] = $propertiesArray['id'];
        }
        // Check if resource exists by public_id
        elseif (!empty($propertiesArray['public_id'])) {
            $existingId = $this->db->queryFirstField(
                "SELECT id FROM resource WHERE resource.properties ->> 'public_id' = %s",
                $propertiesArray['public_id']
            );

            if ($existingId) {
                $actionTypeId = 'MOD';
                $data['id'] = $existingId;
                $propertiesArray['id'] = $existingId;
            }
        }

        // For new resources, check for duplicates based on required schema fields
        if ($actionTypeId === 'CRE') {
            $requiredJson = $this->db->queryFirstField(
                "SELECT resource_type.properties -> 'data_schema' ->> 'required'
	             FROM resource_type
	             WHERE id = %i",
                $resourceType['id']
            );

            $requiredFields = json_decode($requiredJson, true) ?: [];


            $xResourceFieldsJson = $this->db->queryFirstField(
                "SELECT resource_type.properties -> 'data_schema' -> 'x-resource' -> 'schema' ->>'fields'
                 FROM resource_type
                 WHERE id = %i;",
                $resourceType['id']
            );

            $xResourceFields = json_decode($xResourceFieldsJson, true) ?: [];
            foreach ($xResourceFields as $xResourceField) {
                if (isset($xResourceField['constraints']) && isset($xResourceField['constraints']['required']) && $xResourceField['constraints']['required']) {
                    if (!in_array($xResourceField['name'], $requiredFields)) {
                        $requiredFields[] = $xResourceField['name'];
                    }
                }
            }
            // ignore foreign keys from required fields, as title are used instead of public id.
            $foreignKeys = array("files","sdafile_public_ids");
            $jsonForeignKeys = $this->db->queryFirstField(
                "SELECT resource_type.properties -> 'data_schema' -> 'x-resource' -> 'schema' ->> 'foreignKeys'
	             FROM resource_type
	             WHERE id = %s",
                $resourceType['id']
            );

            if ($jsonForeignKeys) {
                $dbForeignKeys = json_decode($jsonForeignKeys, true);
                foreach ($dbForeignKeys as $dbForeignKey) {
                    foreach ($dbForeignKey['fields'] as $fkey) {
                        $foreignKeys[] = $fkey;
                    }
                }
            }


            $whereConditions = [];
            $queryParams = [
                'userId' => $userId,
                'resourceTypeName' => $resourceType['name']
            ];

            if ($studyId && $studyId !== 'new') {
                $queryParams['studyId'] = $studyId;
            }

            foreach ($requiredFields as $requiredField) {
                if (in_array($requiredField, $foreignKeys)) {
                    continue;
                }
                if (isset($propertiesArray[$requiredField])) {
                    if (is_array($propertiesArray[$requiredField])) {
                        $arrayValues = implode(',', $propertiesArray[$requiredField]);
                        $whereConditions[] = sprintf(
                            "to_jsonb(string_to_array('%s',',')) <@ (resource.properties -> '%s')::jsonb " .
                            "AND to_jsonb(string_to_array('%s',',')) @> (resource.properties -> '%s')::jsonb",
                            $arrayValues,
                            $requiredField,
                            $arrayValues,
                            $requiredField
                        );
                    } else {
                        $whereConditions[] = sprintf("resource.properties ->> '%s' = %%s_%s", $requiredField, $requiredField);
                        $queryParams[$requiredField] = $propertiesArray[$requiredField];
                    }
                }
            }

            if ($studyId && $studyId !== 'new') {
                $whereConditions[] = "relationship.range_resource_id = %s_studyId";
            }

            $query = "SELECT resource.id, resource.properties ->> 'public_id' as public_id
	            FROM resource
	            INNER JOIN resource_type ON resource.resource_type_id = resource_type.id
	            INNER JOIN resource_acl ON resource.id = resource_acl.resource_id
	                AND resource_acl.role_id IN ('OWN', 'WRI')
	            LEFT JOIN relationship ON resource.id = relationship.domain_resource_id
	            LEFT JOIN predicate ON relationship.predicate_id = predicate.id
	                AND predicate.name = 'isPartOf'
	            WHERE resource_acl.user_id = %i_userId
	                AND resource_type.name = %s_resourceTypeName" .
                ($whereConditions ? ' AND ' . implode(' AND ', $whereConditions) : '');
            $existingResource = $this->db->queryFirstRow($query, $queryParams);

            if ($existingResource) {
                $data['id'] = $existingResource['id'];
                $propertiesArray['public_id'] = $existingResource['public_id'];
                $actionTypeId = 'MOD';
            }
        }

        // when importing from tsv files, file-in extra_attributes
        $dataProperties = array();
        $schemaJsonProperties = $this->db->queryFirstField("SELECT resource_type.properties->'data_schema'->>'properties' from resource_type where id = %i;", $resourceType['id']);
        $schemaProperties = json_decode($schemaJsonProperties, true);


        $schemaXres = $this->db->queryFirstField("SELECT resource_type.properties->'data_schema'->>'x-resource' from resource_type where id = %i;", $resourceType['id']);
        $xref = array();
        if ($schemaXres) {

            $schemaJsonXres = json_decode($schemaXres, true);
            foreach ($schemaJsonXres['schema']['fields'] as $f) {
                if (isset($f['type'])) {
                    $type = $f['type'];
                } else {
                    $type = 'string';
                }
                if (isset($f['aliasOf'])) {
                    $xref[$f['name']] = array('field' => $f['name'], 'alias' => $f['aliasOf'], 'type' => $type, 'prop_name' => null, 'id' => null, 'ids' => array());
                    $xref[$f['aliasOf']] = array('field' => $f['name'], 'alias' => $f['aliasOf'], 'type' => $type, 'prop_name' => null, 'id' => null, 'ids' => array());
                }
                if (isset($schemaJsonXres['schema']['foreignKeys'])) {
                    $schemaForeignKeys = $schemaJsonXres['schema']['foreignKeys'];
                    foreach ($schemaForeignKeys as $fk) {
                        if ($f['name'] == $fk['fields'][0]) {
                            if (!isset($xref[$f['name']])) {
                                $plural = ($type == 'list') ? 's' : '';
                                $xref[$f['name']] = array('field' => $f['name'], 'alias' => strtolower($fk['reference']['resource']) . "_public_id" . $plural, 'type' => $type, 'prop_name' => null, 'id' => null, 'ids' => array());
                                $xref[strtolower($fk['reference']['resource']) . "_public_id" . $plural] = array('field' => $f['name'], 'alias' => strtolower($fk['reference']['resource']) . "_public_id" . $plural, 'type' => $type, 'prop_name' => null, 'id' => null, 'ids' => array());
                            }
                            $xref[$f['name']]['prop_name'] = $fk['reference']['resource'];
                        }
                    }
                }
            }
        }
        
        foreach ($propertiesArray as $k => $v) {
            if (isset($schemaProperties[$k]) && $k !== 'files' && $k !== 'sdafile_public_ids') {
                if (isset($schemaProperties[$k]['type']) && $schemaProperties[$k]['type'] == 'array' && gettype($v) == 'string') {
                    $dataProperties[$k] = [$v];
                } else {
                    $dataProperties[$k] = $v;
                }
            } 
            elseif (isset($xref[$k]) && isset($xref[$k]['alias'])) {
                $alias = $xref[$k]['alias'];
                if (isset($schemaProperties[$alias])) {
                    if (isset($schemaProperties[$alias]['enum'])) {
                        if (in_array($v, $schemaProperties[$alias]['enum'])) {
                            if ($xref[$k]['type'] == 'string') {
                                $dataProperties[$alias] = $v;
                            } elseif ($xref[$k]['type'] == 'list') {
                                $dataProperties[$alias][] = $v;
                            }
                        }
                    } else {
                        if ($xref[$k]['prop_name']) {
                            $tableName = strtolower($xref[$k]['prop_name']) . "_view";
                        }
                        if ($k == 'files' || $k == 'sdafile_public_ids') {
                            $tableName = 'file_view';
                        }

                        if ($xref[$k]['type'] == 'string') {
                            $where_params = array('study_id' => $studyId, 'name' => $v);
                            $where = "study_id = %s_study_id and (title = %s_name or public_id=%s_name)";
                            $fk_resource = $this->db->queryFirstRow("SELECT public_id, id from $tableName where $where;", $where_params);
                            if ($fk_resource) {
                                $dataProperties[$alias] = $fk_resource['public_id'];
                                $xref[$k]['id'] = $fk_resource['id'];
                            }
                        } elseif ($xref[$k]['type'] == 'list') {
                            $public_ids = array();
                            $values = $v;
                            if (gettype($v) == 'string') {
                                $values = array($v);
                            }
                            foreach ($values as $vv) {
                                $where_params = array('study_id' => $studyId, 'name' => $vv);
                                if ($k == 'files' || $k == 'sdafile_public_ids') {
                                    $xref[$k]['prop_name'] = 'SdaFile';
                                    $where = "title = %s_name or public_id=%s_name";
                                } else {
                                    $where = "study_id = %s_study_id and (title = %s_name or public_id=%s_name)";
                                }
                                $fk_resource = $this->db->queryFirstRow("SELECT public_id, id from $tableName where $where;", $where_params);
                                if ($fk_resource) {
                                    $public_ids[] = $fk_resource['public_id'];
                                    $xref[$k]['ids'][] = $fk_resource['id'];
                                }
                            }
                            $dataProperties[$alias] = $public_ids;
                        }
                    }
                }
            } elseif ($k == 'id' && $this->helper->checkUuid($v)) {
                //do nothing
            } elseif (isset($schemaProperties['extra_attributes'])) {
                $tag = null;
                $unit = "";
                $value = $v;
                if (preg_match("/(.*?)\s*\[(.*?)\]/", trim($k), $regs)) {
                    $tag = $regs[1];
                    $unit = $regs[2];
                } else {
                    $tag = $k;
                }
                if ($tag !== null && $value !== null) {
                    $dataProperties['extra_attributes'][] = array(
                        "tag" => $tag,
                        "unit" => $unit,
                        "value" => $value
                    );
                }
            }
        }
        $data['properties'] = json_encode($dataProperties);

        // Insert new resource or update existing
        if (empty($data['id'])) {
            $resourceUuid = Uuid::uuid4();
            $data['id'] = $resourceUuid->toString();
            $this->db->insert('resource', $data);

            // Register creator ACL
            if ($roleId) {
                $this->db->insert('resource_acl', [
                    'resource_id' => $data['id'],
                    'user_id' => $userId,
                    'role_id' => $roleId
                ]);
            }
        } else {
            $this->db->update('resource', $data, 'id = %s', $data['id']);
        }
        // Create study relationship
        if ($studyId && $studyId !== 'new') {
            $this->relationshipService->createRelationship($resourceType['name'], 'Study', $data['id'], $studyId, false);
        }

        // Create dependency relationships (public_id references)
        foreach ($xref as $k => $x) {
            if ($x['prop_name']) {
                if ($x['id']) {
                    $this->relationshipService->createRelationship($x['prop_name'], $resourceType['name'], $x['id'], $data['id'], false);
                } elseif (count($x['ids'])) {
                    foreach ($x['ids'] as $xid) {
                        $this->relationshipService->createRelationship($x['prop_name'], $resourceType['name'], $xid, $data['id'], false);
                    }
                }
            }
        }

        // Log the action
        $logUuid = Uuid::uuid4()->toString();
        $this->db->insert('resource_log', [
            'id' => $logUuid,
            'resource_id' => $data['id'],
            'user_id' => $userId,
            'action_type_id' => $actionTypeId,
            'properties' => $data['properties']
        ]);

        $primaryKeys = $this->getResourceTypePrimaryKeys($resourceType['id']);
        $jsonPrimaryKeys = $this->db->queryFirstField(
            "SELECT resource_type.properties -> 'data_schema' -> 'x-resource' -> 'schema' ->> 'primaryKey'
                 FROM resource_type
                 WHERE id = %i;",
            $resourceType['id']
        );

        if ($jsonPrimaryKeys) {
            $primaryKeys = json_decode($jsonPrimaryKeys, true);
        }

        $publicId = $this->db->queryFirstField(
            "SELECT resource.properties ->> 'public_id' FROM resource WHERE id = %s",
            $data['id']
        );

        $return = [
            'action_type_id' => $actionTypeId,
            'public_id' => $publicId,
            'id' => $data['id']
        ];
        foreach ($primaryKeys as $primaryKey) {
            $return[$primaryKey] = $dataProperties[$primaryKey] ?? "";
        }
        return $return;
    }







    public function insertResourceData(array $resourceData, string $resourceType, string $studyId): array
    {

        $insertResults = array();
        $insertedResources = array();
        $success = true;
        $actions = array("inserted" => 0, "updated" => 0);
        foreach ($resourceData as $singleResourceData) {
            $properties = (object)$singleResourceData;
            $user = $this->auth->getDetails();

            // Get resource type info
            $resourceTypeInfo = $this->db->queryFirstRow(
                "SELECT id, name FROM resource_type WHERE name = %s",
                $resourceType
            );
            if (!$resourceTypeInfo) {
                throw new Exception("Unknown resource type: {$resourceType}");
            }
            $primaryKeys = $this->getResourceTypePrimaryKeys($resourceTypeInfo['id']);
            // Insert the resource into database
            $insertResult = $this->insertResourceFromProperties(
                $studyId,
                $resourceTypeInfo,
                $properties,
                $user['id'],
                'OWN'
            );
            $insertResults[] = $insertResult;
            if (!isset($insertResult['public_id'])) {
                $success = false;
            }
            $insertedResource = [
                'success' => isset($insertResult['public_id'])
            ];
            foreach ($primaryKeys as $primaryKey) {
                $insertedResource[$primaryKey] = $insertResult[$primaryKey] ?? "";
            }
            $insertedResource['public_id'] = $insertResult['public_id'];
            $insertedResource['id'] = $insertResult['id'];
            $insertedResource['action_type_id'] = $insertResult['action_type_id'];
            $insertedResource['message'] = 'Resource imported successfully';
            $insertedResources[] = $insertedResource;

            if ($insertResult['action_type_id'] == 'CRE') {
                $actions['inserted']++;
            } elseif ($insertResult['action_type_id'] == 'MOD') {
                $actions['updated']++;
            }
        }
        $messages = [];
        foreach ($actions as $action => $nb) {
            if ($nb) {
                $messages[] = $nb . " " . $resourceType . " " . $action . " successfully";
            }
        }

        return [
            'success' => $success,
            'message' => implode(". ", $messages),
            'resource_count' => count($insertResults),
            'resources' => $insertedResources
        ];

    }




    /**
     * Upload resources
     */
    public function uploadResources(Keycloak $auth, string $study_id, mixed $request, string $project_dir, array $content): mixed
    {
        $destination = $project_dir . '/data/studies/';
        $user = $auth->getDetails();
        if ($study_id === 'new') {
            $study_public_id = 'new_' . date('Y_m_d_H_i');
        } else {
            $resource_type_id = $content['resource_type_id'];
            $resource_type = $this->db->queryFirstRow("SELECT id, name from resource_type where id = %i", $resource_type_id);
            if (!$resource_type) {
                throw new Exception("Error: resource type " . $content['resource_type_id'] . " is unknown", 500);
            }
            $study = $this->db->queryFirstRow("SELECT id, resource.properties->>'public_id' as public_id from resource where resource.properties->>'public_id' = %s", $study_id);
            $study_public_id = $study_id;
            $study_id = $study['id'];
        }
        $this->helper->createDirectory($destination, true);
        $destination .= $study_public_id . "/";
        $this->helper->createDirectory($destination, true);

        // Determine number of files
        $nb_files = $content['nb_files'] ?? 1; // Default to 1 for single file uploads
        if (!isset($content['nb_files'])) {
            // Count actual files in the request
            $fileCount = 0;
            // Check for files named "file1", "file2", etc.
            for ($j = 1; $j <= 10; $j++) {
                if ($request->files->get("file" . $j) !== null) {
                    $fileCount = $j;
                }
            }
            // Also check for a file named just "file"
            if ($fileCount == 0 && $request->files->get("file") !== null) {
                $fileCount = 1;
            }
            $nb_files = max($fileCount, 1);
        }
        for ($i = 1; $i <= $nb_files; $i++) {
            // Try both "file1", "file2", etc. and just "file" for single uploads
            $file = $request->files->get("file" . $i);
            if (!$file && $i == 1) {
                $file = $request->files->get("file"); // Fallback to "file" for single uploads
            }
            if ($file) {
                if (!$file->isValid()) {
                    $errorMessages = [
                        UPLOAD_ERR_INI_SIZE => 'The uploaded file exceeds the maximum allowed size.',
                        UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the maximum allowed size specified in the form.',
                        UPLOAD_ERR_PARTIAL => 'The file was only partially uploaded.',
                        UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
                        UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder.',
                        UPLOAD_ERR_CANT_WRITE => 'Failed to write the file to disk.',
                        UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload.',
                    ];
                    $errorCode = $file->getError();
                    $errorMessage = isset($errorMessages[$errorCode]) ? $errorMessages[$errorCode] : 'Unknown error during file upload.';
                    return new JsonResponse($errorMessage, 400);
                }
                $filename = $file->getClientOriginalName();
                $original_name = $filename;
                $numbers = array();
                $pathinfo = pathinfo($filename);
                $basename = $pathinfo['filename'];
                $ext = $pathinfo['extension'];
                $filepath = $destination . $filename;
                $fileProperties = array(
                    "name" => $filepath,
                    "original_name" => $filename,
                    "filesize" => "",
                    "mime_type" => "",
                    "md5" => ""
                );
                // Handle file duplicates
                if (file_exists($filepath)) {
                    $found = false;
                    $nmd5 = md5_file($file);
                    $pattern = "$basename*$ext";
                    $test = glob($destination . $pattern);
                    $nb_match = count($test);
                    if ($nb_match) {
                        foreach (glob($destination . $pattern) as $efile) {
                            if (!$found) {
                                $ebasename = pathinfo($efile, PATHINFO_BASENAME);
                                $emd5 = md5_file($efile);
                                if ($emd5 === $nmd5) {
                                    $filename = $ebasename;
                                    $found = true;
                                } elseif ($nb_match == 1) {
                                    $filename = "$basename.1.$ext";
                                    $found = true;
                                } else {
                                    preg_match("/$basename\.(\d+)\.$ext/", $ebasename, $m);
                                    if ($m !== []) {
                                        $numbers[] = (int)$m[1];
                                    }
                                }
                            }
                        }
                        if (!$found && count($numbers)) {
                            $max = max($numbers);
                            $nb = $max + 1;
                            $filename = "$basename.$nb.$ext";
                        }
                    }
                }
                $filepath = $destination . $filename;

                $fileProperties['name'] = str_replace($project_dir, "", $filepath);
                $file->move($destination, $filename);
                if (!file_exists($destination . "/" . $filename)) {
                    $errorMessage = $destination . "/" . $filename . " not copied to final directory";
                    return new JsonResponse($errorMessage, 400);
                }
                $fileProperties['md5'] = md5_file($filepath);
                $fileProperties['mime_type'] = mime_content_type($filepath);
                $fileProperties['filesize'] = filesize($filepath);
                if ($study_id == 'new') {
                    // Handle a new study import
                    try {
                        $result = $this->importService->importResource($study_id, "$destination/$filename", $user['email'], 'Study', +$user['id']);

                        if (!$result['success']) {
                            return json_encode($result);
                            // throw new Exception("Study import failed: " . ($result['message'] ?? 'Unknown error'));
                        }
                        return json_encode($result);
                    } catch (Exception $e) {
                        error_log("CLI validation error: " . $e->getMessage());
                        throw new Exception("Error validating study: " . $e->getMessage(), 500);
                    }
                } else {
                    // Handle a resource import for an existing study
                    try {
                        $result = $this->importService->importResource($study_id, "$destination/$filename", $user['email'], $resource_type['name'], +$user['id']);
                        if (is_array($result) && isset($result['resources'])) {
                            if (isset($result['success'])) {
                                foreach ($result['resources'] as $idx => $r) {
                                    $result['resources'][$idx]['resource'] = ($r['success']) ? $this->resource->getResource($auth, $resource_type['name'], $r['public_id'], 'read') : [];
                                }
                                return json_encode($result);
                            } else {
                                throw new Exception($result['message'], $result['status'] ?? 500);
                            }
                        } else {
                            if (isset($result['debug'])) {
                                unset($result['debug']);
                            }
                            return json_encode($result);
                        }
                    } catch (Exception $e) {
                        throw new Exception("Error importing resource: " . $e->getMessage(), 500);
                    }
                }
            }
        }
        // Return a default response
        return json_encode([
            'success' => false,
            'message' => 'File processing completed but no specific result was generated',
            'debug' => 'Function reached end without explicit return'
        ]);
    }


    public function editResource(
        $resource,
        string $resourceType,
        string $studyId,
        Keycloak $auth,
        string $projectDir
    ): array {
        $user = $auth->getDetails();

        $resourceTypeId = intval($this->db->queryFirstField("SELECT id FROM resource_type WHERE name = %s", $resourceType));
        if ($resourceTypeId === 0) {
            throw new Exception("Unknown resource type: " . $resourceType, 500);
        }

        // Determine field for study lookup based on study ID prefix
        $studyPrefix = $this->db->queryFirstField("SELECT public_id_prefix FROM resource_type WHERE name = 'Study'");
        $field = "id";
        if ($studyPrefix) {
            $prefixLength = strlen($studyPrefix);
            if (strtolower(substr($studyId, 0, $prefixLength)) === strtolower($studyPrefix)) {
                $field = "public_id";
            }
        }

        $validation_result = $this->validation->validateResource($resource, $resourceType, $user['id']);
        if ($validation_result['success'] === false) {
            return $validation_result;
        }
        // Prepare destination directory and study ID
        if ($studyId === 'new') {
            $timestamp = date('Y_m_d_H_i_s');
            $destination = $projectDir . "/data/studies/new_{$timestamp}";
        } else {
            $study = $this->db->queryFirstRow("SELECT * FROM study_view WHERE {$field} = %s", $studyId);
            if (!$study) {
                throw new Exception("Study not found: " . $studyId, 404);
            }
            $studyId = $study['id'];
            $destination = $projectDir . "/data/studies/" . $study['public_id'] . "/";
        }

        $this->helper->createDirectory($destination, true);

        $filePath = $destination . "upload_" . date('YmdHis') . ".json";

        // Append JSON encoded resource to file
        file_put_contents($filePath, json_encode($resource), FILE_APPEND);

        // Import the resource
        $result = $this->importService->importResource($studyId, $filePath, $user['email'], $resourceType, +$user['id']);

        // Clean up if new study
        if ($studyId === 'new') {
            // Remove JSON file
            if (file_exists($filePath)) {
                unlink($filePath);
            }
            // Remove generated TSV file if it exists
            $tsvFilepath = str_replace('.json', '.tsv', $filePath);
            if (file_exists($tsvFilepath)) {
                unlink($tsvFilepath);
            }
            // Remove directory if empty
            if (file_exists($destination) && is_dir($destination)) {
                $files = scandir($destination);
                if (count($files) <= 2) { // Only . and .. remain
                    rmdir($destination);
                }
            }
        }

        if (is_array($result) && isset($result['resources'])) {
            if (isset($result['success']) && $result['success'] == true) {
                return $result;
            } else {
                throw new Exception($result['message'], $result['status'] ?? 500);
            }
        }
        if (!$result['success']) {
            return $result;
            // throw new Exception($result['message'], $result['status'] ?? 500);
        }

        return [];
    }



    /**
     * Set the status of a resource, identified by ID or public ID, checking permissions.
     *
     * @param string $id Resource internal ID or public ID.
     * @param string $status New status name or ID.
     *
     * @return string Resource internal ID.
     *
     * @throws Exception on unknown resource, unauthorized, or invalid status.
     */
    public function setResourceStatus(Keycloak $auth, string $id, string $status): string
    {
        $user = $auth->getDetails();

        // Determine if ID is UUID format or public_id to build query field
        $field = $this->helper->checkUuid($id) ? 'id' : "properties->>'public_id'";

        // Fetch the resource record by id or public_id
        $resource = $this->db->queryFirstRow(
            "SELECT id, properties->>'public_id' AS public_id FROM resource WHERE $field = %s_id",
            ['id' => $id]
        );

        if (!$resource) {
            throw new Exception("Error: unknown resource " . $id, 500);
        }

        // Only allow if user has appropriate permission or DAC CLI role
        if (!$auth->isDacCli()) {
            $permission = ($status === 'DEL') ? 'delete' : 'edit';

            // Verify user permission on this resource
            $test = $this->db->queryFirstField(
                "SELECT resource_id FROM resource_user_view WHERE resource_id = %s AND user_id = %i AND permissions LIKE %ss",
                $resource['id'],
                $user['id'],
                $permission
            );

            if (!$test) {
                throw new Exception("Error: permission denied to edit resource: " . $resource['public_id'], 401);
            }
        }

        // Set the status via helper method
        $this->setResourceStatusById($resource['id'], $status);

        return $resource['id'];
    }

    /**
     * Helper to set status by resource internal ID.
     *
     * @param string $resource_id Internal resource ID.
     * @param string $status Status name or ID to set.
     *
     * @return string The resource ID updated.
     *
     * @throws Exception on unknown resource or invalid status.
     */
    public function setResourceStatusById(string $resource_id, string $status): string
    {
        // Find valid status_type_id from given status (name or id)
        $status_id = $this->db->queryFirstField(
            "SELECT id FROM status_type WHERE id = %s OR name = %s",
            $status,
            $status
        );

        if (!$status_id) {
            throw new Exception("Error: $status is invalid", 500);
        }

        // Fetch resource record
        $resource = $this->db->queryFirstRow(
            "SELECT id, resource_type_id, status_type_id, properties->>'public_id' AS public_id FROM resource WHERE id = %s",
            $resource_id
        );

        if (!$resource) {
            throw new Exception("Error: $resource_id is unknown", 500);
        }

        // Update status if different
        if ($resource['status_type_id'] != $status_id) {
            $this->db->update("resource", ['status_type_id' => $status_id], "id = %s", $resource['id']);
        }

        // Handle children resources status (isPartOf relationships)
        $children_resource_ids = $this->db->queryFirstColumn(
            "SELECT domain_resource_id FROM relationship_view
             WHERE range_resource_id = %s AND predicate_name = 'isPartOf' AND domain_type <> 'SdaFile'",
            $resource['id']
        );

        foreach ($children_resource_ids as $child_resource_id) {
            // When deleting, check if other relationships prevent status change
            if ($status_id == 'DEL') {
                $other_relationships = $this->db->queryFirstColumn(
                    "SELECT range_resource_id FROM relationship_view
                     WHERE domain_resource_id = %s AND range_resource_id <> %s AND predicate_name = 'isPartOf'",
                    $child_resource_id,
                    $resource['id']
                );
            } else {
                $other_relationships = null;
            }

            if (!$other_relationships) {
                // Recursive update status for child if allowed
                $this->setResourceStatusById($child_resource_id, $status_id);
            }
        }

        return $resource['id'];
    }

    public function editResourceUser(string $resourceId, array $user, $auth): array
    {
        // Fetch resource type
        $resourceType = $this->db->queryFirstField(
            "SELECT resource_type.name
               FROM resource
               INNER JOIN resource_type ON resource.resource_type_id = resource_type.id
               WHERE resource.id = %s",
            $resourceId
        );

        if (!$resourceType) {
            throw new HttpException(500, "Resource doesn't exist!");
        }

        // Check existing access
        $access = $this->db->queryFirstRow(
            "SELECT * FROM resource_acl
               WHERE resource_id = %s AND user_id = %i",
            $resourceId,
            $user['id']
        );

        if (!$access) {
            // Insert new access
            $this->db->insert('resource_acl', [
                'user_id' => $user['id'],
                'resource_id' => $resourceId,
                'role_id' => $user['role']['id']
            ]);
        } elseif ($access['role_id'] != $user['role_id']) {
            // Update existing access
            $this->db->update(
                'resource_acl',
                ['role_id' => $user['role_id']],
                'resource_id = %s AND user_id = %i',
                $resourceId,
                $user['id']
            );
        }

        // Return resource users
        return $this->db->query(
            "SELECT username, permissions, user_id, role, role_id
               FROM resource_user_view
               WHERE resource_id = %s",
            $resourceId
        );
    }
    public function deleteResourceUser(string $resourceId, int $userId): array
    {
        // Delete the resource access
        $this->db->delete(
            'resource_acl',
            'resource_id = %s AND user_id = %i',
            $resourceId,
            $userId
        );

        // Return updated resource users list
        return $this->db->query(
            "SELECT username, permissions, user_id, role, role_id
	         FROM resource_user_view
	         WHERE resource_id = %s",
            $resourceId
        );
    }


    public function patchResource(string $resourceId, array $patch, Keycloak $auth): bool
    {
        // Authorization check
        if ($auth->isGuest() || (!$auth->hasRole('submitter') && !$auth->isDacCli())) {
            throw new HttpException(401, 'Unauthorized');
        }

        // Determine field type
        $field = $this->helper->checkUuid($resourceId) ? 'id' : "resource.properties ->> 'public_id'";
        $resource = $this->db->queryFirstRow("SELECT *, resource.properties ->> 'public_id' as public_id from resource where " . $field . " = %s", $resourceId);

        if (!$resource) {
            throw new NotFoundHttpException('Unknown resource');
        }

        $isDacMember = $auth->checkDacMember($resourceId);

        if ($auth->isDacCli() || $isDacMember) {
            $this->handleDacPatch($resource, $patch, $auth);
        } else {
            $this->handleSubmitterPatch($resource, $patch, $auth);
        }

        return true;
    }

    private function handleDacPatch(array $resource, array $patch, Keycloak $auth): void
    {
        $studyId = $this->db->queryFirstField("SELECT range_resource_id as study_id from relationship_view where domain_resource_id = %s and range_type = 'Study'", $resource['id']);
        $policyId = $this->resolvePolicyId($patch);

        if ($policyId && isset($patch['policy_status'])) {
            $isActive = str_starts_with(trim($patch['policy_status']), 'valid');
            $relationshipId = $this->db->queryFirstField("SELECT id from relationship where domain_resource_id = %s and range_resource_id = %s", $resource['id'], $policyId);

            if (!$relationshipId) {
                throw new HttpException(500, 'Error: this policy was not linked to this dataset');
            }

            if ($patch['policy_status'] === 'reject') {
                $this->db->delete("relationship", "id = %s", $relationshipId);
                $this->setResourceStatus($auth, $resource['id'], 'REV');
                if ($studyId) {
                    $this->setResourceStatus($auth, $studyId, 'REV');
                }
            } else {
                $this->db->update("relationship", array("is_active" => $isActive), "id = %s", $relationshipId);
                $this->logAction($resource, 'PUB', $auth);
                // update study status //
                $studyDatasetStatus = $this->db->queryFirstColumn(
                    "SELECT
                          datasets.status_type_id
                      FROM
                      resource AS datasets
                      INNER JOIN relationship_view ON datasets.id = relationship_view.domain_resource_id and relationship_view.domain_type = 'Dataset' and relationship_view.range_type = 'Study'
                      WHERE relationship_view.range_resource_id = %s",
                    $studyId
                );
                $allStudyDatasetsArePub = true;
                foreach ($studyDatasetStatus as $s) {
                    if ($s != 'PUB') {
                        $allStudyDatasetsArePub = false;
                    }
                }

                if ($allStudyDatasetsArePub) {
                    $this->setResourceStatus($auth, $studyId, 'PUB');
                    $studyProperties = $this->db->queryFirstField("SELECT properties from resource where id = %s", $studyId);
                    $this->logAction(['id' => $studyId, 'properties' => $studyProperties], 'PUB', $auth);
                }

                $email = $this->db->queryFirstField("SELECT \"user\".email FROM resource_acl INNER JOIN \"user\" ON resource_acl.user_id= \"user\".id WHERE resource_acl.resource_id=%s AND resource_acl.role_id='OWN';", $resource['id']);
            }
        }
    }

    private function handleSubmitterPatch(array $resource, array $patch, Keycloak $auth): void
    {
        $updates = [];
        $user = $auth->getDetails();

        foreach ($patch as $key => $value) {
            if (array_key_exists($key, $resource)) {
                $updates[$key] = $value;
                $resource[$key] = $value;
            }
        }

        if ($updates !== []) {
            $this->db->update("resource", $updates, "id = %s", $resource['id']);
        }

        if (isset($updates['status_type_id'])) {
            $this->setResourceStatus($auth, $resource['id'], $updates['status_type_id']);
        }
        $actionTypeId = (isset($updates['status_type_id'])) ? $updates['status_type_id'] : "MOD";
        $dbActionType = $this->db->queryFirstField("SELECT id from action_type where id = %s", $actionTypeId);
        if (!$dbActionType) {
            $actionTypeId = "MOD";
        }
        $this->logAction($resource, $actionTypeId, $auth, $user['id']);
    }

    private function logAction(array $resource, string $actionTypeId, Keycloak $auth, ?string $userId = null): void
    {
        $log = [
            'id' => Uuid::uuid4()->toString(),
            'resource_id' => $resource['id'],
            'user_id' => $userId,
            'action_type_id' => $actionTypeId,
            'properties' => $resource['properties']
        ];
        $this->db->insert("resource_log", $log);
    }

    private function resolvePolicyId(array $patch): ?string
    {
        $policy_id = '';
        if (isset($patch['policy_public_id'])) {
            return $this->db->queryFirstField("SELECT id from resource where resource.properties ->> 'public_id'::text = %s", $patch['policy_public_id']);
        } elseif (isset($patch['policy_id']) && $this->helper->checkUuid($patch['policy_id'])) {
            return $this->db->queryFirstField("SELECT id from resource where resource.id = %s", $patch['policy_id']);
        }
        return null;
    }
}
