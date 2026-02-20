<?php

namespace App\Service\File;

use App\Service\Auth\Keycloak;
use App\Service\Resource\ResourceReadService;
use App\Service\Utility\GeneralHelperService;
use Exception;
use MeekroDB;

final class FileReadService
{
    private MeekroDB $db;
    private ResourceReadService $readResource;
    private GeneralHelperService $helper;

    public function __construct(MeekroDB $db, ResourceReadService $readResource, GeneralHelperService $helper)
    {
        $this->db = $db;
        $this->readResource = $readResource;
        $this->helper = $helper;
    }

    public function getAllFiles(Keycloak $auth, array $page, string $search = '', string $status = ''): array
    {

        if ($auth->isGuest()) {
            throw new \Exception("Unauthorized", 401);
        }
        $user = $auth->getDetails();
        $tmp_files = array();
        $files = array();
        $this->db->param_char = ":";

        $where = '';
        if ($search) {
            $where = " and resource.properties->>'title' like :ss_search ";
        }
        if ($status) {
            $where .= " and status = :s_status ";
        }
        $sql_params = array("user_id" => $user['id'], "search" => $search, "status" => $status);

        $init_request = "FROM resource_user_view as files inner join resource on resource.id = files.resource_id WHERE files.resource_type_name = 'SdaFile' AND user_id = :i_user_id ";
        // $init_request = "FROM resource_user_view as files inner join resource on resource.id = files.resource_id WHERE files.resource_type_name = 'SdaFile' AND user_id is not null  ";

        $status_list = $this->db->queryFirstColumn("SELECT distinct files.status from resource_user_view as files inner join resource on resource.id = files.resource_id WHERE files.resource_type_name = 'SdaFile' group by files.status; ");

        $nb_total = $this->db->queryFirstField("SELECT count(distinct resource_id) $init_request ", $sql_params);
        $nb_filtered = $this->db->queryFirstField("SELECT count(distinct resource_id) $init_request $where", $sql_params);
        $params = array('total' => $nb_total, 'filtered' => $nb_filtered, 'status_list' => $status_list);

        $limit = "LIMIT " . $page['by'] . " OFFSET " . ($page['current'] - 1) * $page['by'];
        $file_ids = $this->db->queryFirstColumn("SELECT distinct resource_id $init_request $where $limit", $sql_params);
        $this->db->param_char = "%";

        if (count($file_ids) > 0) {
            $tmp_files = $this->db->query(
                "SELECT
	                resource.id as resource_id,
	                resource.status_type_id as status,
	                resource.properties ->> 'title' AS name,
	                resource.properties ->> 'title' AS title,
	                resource.properties ->> 'filesize' AS filesize,
	                resource.properties ->> 'public_id' AS public_id,
                    COALESCE(STRING_AGG(resource_log.\"comment\", ', '), '') as \"comment\"
	            FROM
	                resource
	                inner join resource_log on resource.id = resource_log.resource_id
	            WHERE
	                resource.id in %ls
                GROUP BY resource.id",
                $file_ids
            );
            foreach ($tmp_files as $f) {
                $f['datasets'] = $this->db->query("SELECT dataset_view.study_public_id, dataset_view.study_title, dataset_view.public_id, dataset_view.status_type_id, dataset_view.title, dataset_view.status, dataset_view.creation_date, dataset_view.last_update, dataset_view.creator_name,
	          dataset_view.creator_email, dataset_view.released_date from sdafile_study_dataset_view inner join dataset_view on dataset_view.id = sdafile_study_dataset_view.dataset_id where sdafile_id = %s", $f['resource_id']);
                $f['studies'] = array_map(function ($a) {
                    return $a['study_title'];
                }, $f['datasets']);

                $f['history'] = $this->db->query("SELECT action_type_id, action_time from resource_log where resource_id = %s order by action_time ;", $f['resource_id']);
                foreach ($f['history'] as $h) {
                    if ($h['action_type_id'] == 'CRE') {
                        $f['creation_date'] = substr($h['action_time'], 0, 19);
                    }
                    if ($h['action_type_id'] == 'VER') {
                        $f['verif_date'] = substr($h['action_time'], 0, 19);
                    }
                    if ($h['action_type_id'] == 'PUB') {
                        $f['published_date'] = substr($h['action_time'], 0, 19);
                    }
                }
                $files[] = $f;
            }
        }

        return array('data' => $files, 'params' => $params);
    }


    public function getRawFiles(string $study_id, Keycloak $auth): array
    {
        if ($auth->isGuest()) {
            throw new Exception("Unauthorized", 401);
        }
        $user = $auth->getDetails();
        $files = array();
        $field = ($this->helper->checkUuid($study_id) ? "range_resource_id" : "range_public_id");
        $this->db->param_char = ':';
        $file_ids = $this->db->queryFirstColumn(
            "SELECT
		distinct resource_id
	FROM
		resource_user_view
		left join relationship on resource_user_view.resource_id = relationship.domain_resource_id
	WHERE
		resource_type_name = 'SdaFile'
		AND user_id = :i_user_id
		AND resource_user_view.permissions LIKE '%edit%'
		AND resource_user_view.status_type_id in ('VER','PUB')
		and relationship.id is null
		union 
			SELECT
			distinct resource_id
		FROM
			resource_user_view
			inner join relationship_view on resource_user_view.resource_id = relationship_view.domain_resource_id
		WHERE
			resource_type_name = 'SdaFile'
			AND user_id = :i_user_id
			AND resource_user_view.permissions like '%edit%'
			AND resource_user_view.status_type_id in ('VER','PUB')
			AND relationship_view." . $field . " = :s_study_id;
	    ",
            array("user_id" => $user['id'], "study_id" => $study_id),
        );
        $this->db->param_char = "%";
        if (count($file_ids) > 0) {
            $files = $this->db->query(
                "SELECT
	                properties ->> 'title' AS name,
	                properties ->> 'title' AS title,
	                properties ->> 'filesize' AS filesize,
	                properties ->> 'public_id' AS public_id
	            FROM
	                resource
	            WHERE
	                resource.id in %ls",
                $file_ids
            );
        }
        return $files;
    }

    public function getAnalysisFiles(string $study_id, Keycloak $auth): array
    {
        $files = array();
        $samples = $this->readResource->listResources($auth, 'Sample', $study_id, 'edit');
        foreach ($samples as $sample) {
            $name = "";
            if (strpos($sample['alias'], 'FIXT') !== false) {
                $patient_id = implode("-", array_slice(explode("-", $sample['alias']), 0, 3));
                foreach ($samples as $s2) {
                    if (strpos($s2['alias'], $patient_id) !== false && strpos($s2['alias'], 'PXD') !== false) {
                        $name = $sample['alias'] . "_vs_" . $s2['alias'];
                        break;
                    }
                }
            }
            if ($name !== '' && $name !== '0') {
                $files[] = array("name" => $name . "_" . (md5($name)) . ".vcf", "filesize" => rand(1000, 10000000), "mime-type" => "text/plain");
            }
        }
        return $files;
    }
}
