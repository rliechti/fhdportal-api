<?php

namespace App\Service\Resource;

use App\Service\Auth\Keycloak;
use App\Service\Utility\GeneralHelperService;
use Exception;
use MeekroDB;
use XLSXWriter;

/**
 * Service responsible for exporting study submissions as XLSX files.
 */
final class ResourceExportService
{
    private MeekroDB $db;
    private GeneralHelperService $helper;

    public function __construct(MeekroDB $db, GeneralHelperService $helper)
    {
        $this->db = $db;
        $this->helper = $helper;
    }

    /**
     * Generates and prepares a downloadable XLSX file for a study submission.
     *
     * @param Keycloak $auth The authentication service to verify user permissions.
     * @param string $study_id The public ID of the study to download.
     * @param string $project_dir The project root directory to save temporary files.
     *
     * @return string The full file path of the generated XLSX file.
     *
     * @throws Exception If unauthorized or study not found.
     */
    public function downloadDataset(Keycloak $auth, string $dataset_id, string $project_dir): string
    {
        if ($auth->isGuest() || !$auth->hasRole('submitter')) {
            throw new Exception('Unauthorized', 401);
        }
        $user = $auth->getDetails();
        
        
        $study_id = $this->db->queryFirstField("SELECT study_public_id from dataset_view where public_id = %s", $dataset_id);
        return $this->downloadSubmission($auth, $study_id, $project_dir);
    }



    /**
     * Generates and prepares a downloadable XLSX file for a study submission.
     *
     * @param Keycloak $auth The authentication service to verify user permissions.
     * @param string $study_id The public ID of the study to download.
     * @param string $project_dir The project root directory to save temporary files.
     *
     * @return string The full file path of the generated XLSX file.
     *
     * @throws Exception If unauthorized or study not found.
     */

    public function getRelations(string $study_id, ?string $dataset_id = null): array
    {
        $relations = array();
        if (!$dataset_id) {
            $relations = $this->db->query(
                "SELECT relationship.id, relationship.relationship_rule_id, relationship.domain_resource_id, resource_type.name
             FROM relationship 
             INNER JOIN relationship_rule ON relationship_rule.id = relationship.relationship_rule_id 
             INNER JOIN resource_type ON resource_type.id = relationship_rule.domain_type_id 
             WHERE range_resource_id = %s",
                $study_id
            );
        } else {
            $relations = $this->getAllRelationsRecursive($dataset_id);
			usort($relations,function($a, $b) { return strcmp($a['name'], $b['name']); });	
        }

        return $relations;
    }


    public function getAllRelationsRecursive(string $id, &$results = [])
    {
        $relations = $this->db->query(
            "SELECT relationship.id, relationship.relationship_rule_id, relationship.domain_resource_id, resource_type.name
             FROM relationship 
             INNER JOIN relationship_rule ON relationship_rule.id = relationship.relationship_rule_id
             INNER JOIN resource_type ON resource_type.id = relationship_rule.domain_type_id
             WHERE range_resource_id = %s",
            $id
        );

        if (!$relations) {
            return $results;
        }


        foreach ($relations as $rel) {

            // Éviter les doublons (et les boucles infinies)
            if (!isset($results[$rel['domain_resource_id']])) {
                $results[$rel['domain_resource_id']] = $rel;

                // Appel récursif : on cherche les relations liées à cette ressource
                $this->getAllRelationsRecursive($rel['domain_resource_id'], $results);
            }
        }
        return array_values($results); // réindexe le tableau proprement
    }


    public function downloadSubmission(Keycloak $auth, string $study_id, string $project_dir, ?string $dataset_id = null): string
    {

        if ($auth->isGuest() || !$auth->hasRole('submitter')) {
            throw new Exception('Unauthorized', 401);
        }
        $user = $auth->getDetails();

        // Fetch study by public_id
        $study = $this->db->queryFirstRow("SELECT * FROM study_view WHERE public_id = %s", $study_id);
        if (!$study) {
            throw new Exception("Unknown study: $study_id", 404);
        }
        // Verify user permission on study resource
        
        $permission = $this->db->queryFirstField(
            "SELECT resource_id FROM resource_user_view WHERE resource_id = %s AND user_id = %i  AND permissions LIKE %ss",
            $study['id'],
            $user['id'],
            'edit'
        );
        if (!$permission) {
            throw new Exception('Unauthorized', 401);
        }

        // Include XLSXWriter library (consider autoload or better integration)
        // require_once dirname(__DIR__) . '/../vendor/mk-j/php_xlsxwriter/xlsxwriter.class.php';

        $filename = "study_$study_id.xlsx";

        // Prepare headers for file download, ideally done in controller
        header('Content-disposition: attachment; filename="' . XLSXWriter::sanitize_filename($filename) . '"');
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Transfer-Encoding: binary');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');

        // Setup directories with ensure creation
        $study_dir = $project_dir . '/data/studies/' . $study_id . '/';
        $this->helper->createDirectory($study_dir, true);
        $download_dir = $study_dir . 'download/';
        $this->helper->createDirectory($download_dir, true);
        $filepath = $study_dir . $filename;

        // Prepare study sheet header and data
        $study_header = [];
        $study_data = [];
        $tmp_study_header = ['public_id', 'status', 'creation_date', 'last_update', 'creator_name'];
        // $tmp_study_header = ['public_id', 'status', 'creation_date', 'last_update', 'released_date', 'creator_name'];

        foreach ($tmp_study_header as $field) {
            $study_header[$field] = 'string';
            $study_data[] = $study[$field];
        }

        $props = json_decode($study['properties'], true);
        foreach ($props as $key => $value) {
            if ($key === 'public_id') {
                continue;
            }
            $study_header[$key] = 'string';
            $study_data[] = $value;
        }

        $writer = new XLSXWriter();
        $writer->writeSheetHeader('Study', $study_header);
        $writer->writeSheetRow('Study', $study_data);

        // Fetch relationships (e.g., related resources)
        $relations = $this->getRelations($study['id'], $dataset_id);

        $relation_types = [];
        foreach ($relations as $relation) {
            $res_data = [];
            $resource = $this->db->queryFirstRow(
                "SELECT * FROM resource WHERE id = %s AND status_type_id != 'DEL'",
                $relation['domain_resource_id']
            );
            if (!$resource) {
                continue;
            }
            $props = json_decode($resource['properties'], true);
            if (!$props) {
                continue;
            }

            // Write sheet header for new relation types
            if (!in_array($relation['name'], $relation_types)) {
                $relation_types[] = $relation['name'];
                $rel_type_header = [];
                foreach ($props as $key => $_) {
                    $rel_type_header[$key] = 'string';
                }
                $writer->writeSheetHeader($relation['name'], $rel_type_header);
            }

            // Flatten property arrays and strings into row data
            foreach ($props as $prop) {
                if (is_string($prop)) {
                    $res_data[] = $prop;
                } elseif (is_array($prop)) {
                    $concat_data = [];
                    foreach ($prop as $p) {
                        if (is_string($p)) {
                            $concat_data[] = $p;
                        } elseif (is_array($p)) {
                            foreach ($p as $k => $pp) {
                                $concat_data[] = "$k:$pp";
                            }
                        }
                    }
                    $res_data[] = implode(';', $concat_data);
                }
            }
            $writer->writeSheetRow($relation['name'], $res_data);
        }

        // Write to file and create a timestamped copy for downloads
        $writer->writeToFile($filepath);
        $dated_filename = "study_{$study_id}_" . date('Ymd_His') . ".xlsx";
        copy($filepath, $download_dir . $dated_filename);

        return $filepath;
    }
}
