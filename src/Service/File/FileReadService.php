<?php

namespace App\Service\File;

use App\Service\Auth\Keycloak;
use App\Service\Resource\ResourceReadService;
use App\Service\Utility\GeneralHelperService;
use Exception;
use MeekroDB;

final class FileReadService
{
    private const SORTABLE_COLUMNS = [
        'title'          => "resource.properties ->> 'title'",
        'status'         => 'status_type.name',
        'filesize'       => "CAST(NULLIF(resource.properties ->> 'filesize', '') AS BIGINT)",
        'public_id'      => "resource.properties ->> 'public_id'",
        'creation_date'  => "MAX(resource_log.action_time) FILTER (WHERE resource_log.action_type_id = 'CRE')",
        'verif_date'     => "MAX(resource_log.action_time) FILTER (WHERE resource_log.action_type_id = 'VER')",
        'published_date' => "MAX(resource_log.action_time) FILTER (WHERE resource_log.action_type_id = 'PUB')",
    ];

    private const DATE_SORT_COLUMNS = ['creation_date', 'verif_date', 'published_date'];

    private MeekroDB $db;
    private ResourceReadService $readResource;
    private GeneralHelperService $helper;

    public function __construct(MeekroDB $db, ResourceReadService $readResource, GeneralHelperService $helper)
    {
        $this->db = $db;
        $this->readResource = $readResource;
        $this->helper = $helper;
    }

    public function getAllFiles(
        Keycloak $auth,
        array $page,
        string $search = '',
        string $status = '',
        array $size = ['min' => null, 'max' => null],
        array $sort = ['by' => null, 'order' => 'asc'],
        string $datasetLink = 'all'
    ): array {

        if ($auth->isGuest()) {
            throw new \Exception("Unauthorized", 401);
        }
        $user = $auth->getDetails();
        $tmp_files = array();
        $files = array();

        $where = '';
        if ($search) {
            $where = " and resource.properties->>'title' ilike %ss_search ";
        }
        if ($status) {
            $where .= " and status_type.name = %s_status ";
        }
        $sizeMin = isset($size['min']) ? (int)$size['min'] : null;
        $sizeMax = isset($size['max']) ? (int)$size['max'] : null;
        if ($sizeMin !== null) {
            $where .= " and CAST(NULLIF(resource.properties->>'filesize','') AS BIGINT) >= %i_size_min ";
        }
        if ($sizeMax !== null) {
            $where .= " and CAST(NULLIF(resource.properties->>'filesize','') AS BIGINT) <= %i_size_max ";
        }
        if ($datasetLink === 'linked') {
            $where .= " and exists (select 1 from sdafile_dataset_link_view where sdafile_id = resource.id) ";
        } elseif ($datasetLink === 'unlinked') {
            $where .= " and not exists (select 1 from sdafile_dataset_link_view where sdafile_id = resource.id) ";
        }
        $sql_params = array(
            "user_id" => $user['id'],
            "search" => $search,
            "status" => $status,
            "size_min" => $sizeMin ?? 0,
            "size_max" => $sizeMax ?? 0,
        );

        $base_request = "FROM resource
            INNER JOIN resource_type ON resource_type.id = resource.resource_type_id AND resource_type.name = 'SdaFile'
            INNER JOIN resource_acl ON resource_acl.resource_id = resource.id AND resource_acl.user_id = %i_user_id
            INNER JOIN status_type ON status_type.id = resource.status_type_id ";

        $status_list = $this->db->queryFirstColumn(
            "SELECT DISTINCT status_type.name
             FROM resource
             INNER JOIN resource_type ON resource_type.id = resource.resource_type_id AND resource_type.name = 'SdaFile'
             INNER JOIN status_type ON status_type.id = resource.status_type_id"
        );

        $totals = $this->db->queryFirstRow(
            "SELECT
                COUNT(*) AS total,
                MIN(CAST(NULLIF(resource.properties->>'filesize','') AS BIGINT)) AS size_min,
                MAX(CAST(NULLIF(resource.properties->>'filesize','') AS BIGINT)) AS size_max
             $base_request",
            $sql_params
        );
        $nb_filtered = $this->db->queryFirstField("SELECT COUNT(*) $base_request WHERE 1=1 $where", $sql_params);
        $params = array(
            'total' => $totals['total'],
            'filtered' => $nb_filtered,
            'status_list' => $status_list,
            'size_bounds' => array('min' => $totals['size_min'], 'max' => $totals['size_max']),
        );

        $sortColumn = self::SORTABLE_COLUMNS[$sort['by'] ?? ''] ?? null;
        $sortDir = strtoupper($sort['order'] ?? '') === 'DESC' ? 'DESC' : 'ASC';
        $needsLog = in_array($sort['by'] ?? '', self::DATE_SORT_COLUMNS, true);
        $joinLog = $needsLog ? "LEFT JOIN resource_log ON resource_log.resource_id = resource.id" : "";
        $groupBy = $needsLog ? "GROUP BY resource.id" : "";
        if ($sortColumn !== null) {
            $selectExtra = ", $sortColumn AS sort_key";
            $orderBy = "ORDER BY sort_key $sortDir NULLS LAST, resource.id ASC";
        } else {
            $selectExtra = "";
            $orderBy = "ORDER BY resource.id ASC";
        }

        $limit = "LIMIT " . $page['by'] . " OFFSET " . ($page['current'] - 1) * $page['by'];
        $file_ids = $this->db->queryFirstColumn(
            "SELECT resource.id $selectExtra $base_request $joinLog WHERE 1=1 $where $groupBy $orderBy $limit",
            $sql_params
        );

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

            $tmp_files_by_id = array();
            foreach ($tmp_files as $f) {
                $tmp_files_by_id[$f['resource_id']] = $f;
            }
            $tmp_files = array_values(array_filter(array_map(
                fn($id) => $tmp_files_by_id[$id] ?? null,
                $file_ids
            )));

            $datasets_by_file_id = array();
            $dataset_rows = $this->db->query(
                "SELECT DISTINCT
                	sdafile_dataset_link_view.sdafile_id,
                	dataset_view.study_public_id,
                	dataset_view.study_title,
                	dataset_view.public_id,
                	dataset_view.status_type_id,
                	dataset_view.title,
                	dataset_view.status,
                	dataset_view.creation_date,
                	dataset_view.last_update,
                	dataset_view.creator_name,
                	dataset_view.creator_email,
                	dataset_view.released_date
                FROM
                	sdafile_dataset_link_view
                	INNER JOIN dataset_view ON dataset_view.id = sdafile_dataset_link_view.dataset_id
                WHERE
                	sdafile_dataset_link_view.sdafile_id in %ls",
                $file_ids
            );
            foreach ($dataset_rows as $d) {
                $fid = $d['sdafile_id'];
                unset($d['sdafile_id']);
                $datasets_by_file_id[$fid][] = $d;
            }

            $history_by_file_id = array();
            $history_rows = $this->db->query(
                "SELECT resource_id, action_type_id, action_time from resource_log where resource_id in %ls order by resource_id, action_time;",
                $file_ids
            );
            foreach ($history_rows as $h) {
                $history_by_file_id[$h['resource_id']][] = $h;
            }

            foreach ($tmp_files as $f) {
                $f['datasets'] = $datasets_by_file_id[$f['resource_id']] ?? [];
                $f['studies'] = array_map(function ($a) {
                    return $a['study_title'];
                }, $f['datasets']);

                $f['history'] = $history_by_file_id[$f['resource_id']] ?? [];
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
        // This query mixes named ':' placeholders with literal SQL '%edit%' wildcards,
        // so it can't safely move to MeekroDB's default '%'-prefixed placeholder syntax
        // (a literal wildcard could collide with a placeholder type letter). Guarantee
        // the temporary param_char is restored even if the query throws, so a failure
        // here can't leak ':' state into unrelated queries later in the same request
        // (security audit M-7).
        $previousParamChar = $this->db->param_char;
        $this->db->param_char = ':';
        try {
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
        } finally {
            $this->db->param_char = $previousParamChar;
        }
        if (count($file_ids) > 0) {
            foreach (array_chunk($file_ids, 50000) as $file_ids_chunk) {
                $files = array_merge($files, $this->db->query(
                    "SELECT
	                    properties ->> 'title' AS name,
	                    properties ->> 'title' AS title,
	                    properties ->> 'filesize' AS filesize,
	                    properties ->> 'public_id' AS public_id
	                FROM
	                    resource
	                WHERE
	                    resource.id in %ls",
                    $file_ids_chunk
                ));
            }
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
