<?php

use App\Service\Auth\Keycloak;
use Symfony\Component\HttpFoundation\JsonResponse;

require __DIR__.'/Resource.php';

if (!function_exists("checkUuid")){
	function checkUuid($str)
	{
	    return (is_string($str) && preg_match("/^[0-9A-F]{8}-[0-9A-F]{4}-4[0-9A-F]{3}-[89AB][0-9A-F]{3}-[0-9A-F]{12}$/i", $str));
	}	
}


function getRawFiles($study_id, Keycloak $auth)
{
    if ($auth->isGuest()) {
        throw new Exception("Unauthorized", 401);
    }
    $user = $auth->getDetails();
    $files = array();
	$field = (checkUuid($study_id)?"range_resource_id":"range_public_id");
	DB::$param_char="#";
    $file_ids = DB::queryFirstColumn(
        "SELECT
	distinct resource_id
FROM
	resource_user_view
	left join relationship on resource_user_view.resource_id = relationship.domain_resource_id
WHERE
	resource_type_name = 'SdaFile'
	AND user_id = #i_user_id
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
		AND user_id = #i_user_id
		AND resource_user_view.permissions like '%edit%'
		AND resource_user_view.status_type_id in ('VER','PUB')
		AND relationship_view.".$field." = #s_study_id;
    ",
	array("user_id" => $user['id'], "study_id" => $study_id),
    );
	DB::$param_char="%";
    if (count($file_ids) > 0) {
        $files = DB::query(
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
    // $samples = listResources($auth,'Sample',$study_id,'edit');
    // foreach($samples as $sample){
    //  $files[] = array("name" => $sample['alias']."_".(md5($sample['alias']."1")).".1.fastq.gz", "filesize" => rand(1000,10000000),"mime-type" => "application/gzip");
    //  $files[] = array("name" => $sample['alias']."_".(md5($sample['alias']."2")).".2.fastq.gz", "filesize" => rand(1000,10000000),"mime-type" => "application/gzip");
    // }
    return $files;
}

function getAnalysisFiles($study_id, Keycloak $auth)
{
    $files = array();
    $samples = listResources($auth, 'Sample', $study_id, 'edit');
    foreach ($samples as $sample) {
        $name = "";
        if (strpos($sample['alias'], 'FIXT') !== false) {
            $patient_id = implode("-", array_slice(explode("-", $sample['alias']), 0, 3));
            foreach ($samples as $s2) {
                if (strpos($s2['alias'], $patient_id) !== false && strpos($s2['alias'], 'PXD') !== false) {
                    $name = $sample['alias']."_vs_".$s2['alias'];
                    break;
                }
            }
        }
        if ($name !== '' && $name !== '0') {
            $files[] = array("name" => $name."_".(md5($name)).".vcf", "filesize" => rand(1000, 10000000),"mime-type" => "text/plain");
        }
    }
    return $files;
}
