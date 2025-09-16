<?php


/*

UNAME="robin.liechti@sib.swiss"
PWD="W8vjJDJ^T3XvKCj2TXJ7"
IMPERSONATE="nora.toussaint.test-ega"
HDTOKEN=`curl -s "https://idp.test.ega-archive.org/realms/EGA/protocol/openid-connect/token" -d "grant_type=password" -d "client_id=sp-api-switzerland" -d "username=$UNAME" --data-urlencode "password=$PWD" | jq -r .access_token`

Then, to impersonate another user, do:
curl "https://idp.test.ega-archive.org/realms/EGA/protocol/openid-connect/token" \
--data-urlencode "grant_type=urn:ietf:params:oauth:grant-type:token-exchange" \
--data-urlencode "client_id=sp-api-switzerland" \
--data-urlencode "requested_subject=$IMPERSONATE" \
--data-urlencode "subject_token=$HDTOKEN" \
--data-urlencode "requested_token_type=urn:ietf:params:oauth:token-type:refresh_token"

*/


require 'include.php';
if (!file_exists(__DIR__."/cega_rabbitmq.inc.php")){
	fwrite(STDERR,"Error: missing rabbimq class.".PHP_EOL);
	exit(1);
}
require_once 'cega_rabbitmq.inc.php';
if (!defined('CEGA_USERNAME')){
	define("CEGA_USERNAME","robin.liechti@sib.swiss");
	// define("CEGA_USERNAME","clara.roujeau@sib.swiss");
}
if (!defined('CEGA_PASSWORD')){
	define("CEGA_PASSWORD","W8vjJDJ^T3XvKCj2TXJ7");
	// define("CEGA_PASSWORD","PassWord4EGA@");
}
if (!defined('CEGA_URL')){
    define("CEGA_URL","https://api-test.switzerland.ega-archive.org");
}

if (!defined('CEGA_DAC_URL')){
    define("CEGA_DAC_URL","https://dac.test.ega-archive.org/api");
}

if (!defined('CEGA_SP_CLIENT_ID')){
	define("CEGA_SP_CLIENT_ID","sp-api-switzerland");
}

if (!defined('CEGA_DAC_CLIENT_ID')){
	define("CEGA_DAC_CLIENT_ID","dac-api");
}

function checkUuid($str)
{
    return (is_string($str) && preg_match("/^[0-9A-F]{8}-[0-9A-F]{4}-4[0-9A-F]{3}-[89AB][0-9A-F]{3}-[0-9A-F]{12}$/i", $str));
}

function getAccessToken($client){
	// TOKEN=`curl -s "https://idp.test.ega-archive.org/realms/EGA/protocol/openid-connect/token" \
	// -d "grant_type=password" \
	// -d "client_id=sp-api" \
	// -d "username=clara.roujeau@sib.swiss" \
	// --data-urlencode "password=PassWord4EGA@" | jq -r .access_token`
	switch ($client) {
		case 'sp':
		$client_id = CEGA_SP_CLIENT_ID;
		break;
		case 'dac':
		$client_id = CEGA_DAC_CLIENT_ID;
		break;
		default:
		$client_id = CEGA_SP_CLIENT_ID;
		break;
	}
	$data = array(
        'grant_type' => 'password',
        'client_id' => $client_id,
        'username' => CEGA_USERNAME,
		'password' => CEGA_PASSWORD
    );
    $fields_string = http_build_query($data);
    // Prepare new cURL resource
    $url = "https://idp.test.ega-archive.org/realms/EGA/protocol/openid-connect/token";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLINFO_HEADER_OUT, true);
    curl_setopt($ch, CURLOPT_POST, true);
    // curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/x-www-form-urlencoded'));
    curl_setopt($ch, CURLOPT_POSTFIELDS, $fields_string);
 

    // Submit the POST request
    $result = curl_exec($ch);
    // Close cURL session handle
    if(!$result) {
        throw new Exception("ERROR: unable to connect to idp.test.ega-archive.org: ".curl_error($ch), 404);
    }
    curl_close($ch);
    $res = json_decode($result,true);
    
    if(isset($res->error)) {
        $mes = (isset($res->error_message)) ? $res->error_message : $res->error;
        throw new Exception("IDP error: ".$mes, 404);
    }
	if (!isset($res['access_token'])){
		throw new Exception("No access_token present in the curl response", 1);
		
	}
	
	
	
	return $res['access_token'];
}

function registerSubmission($study_id){
	$token = getAccessToken('sp');
	$cega_submission_provisional_id = DB::queryFirstField("SELECT cega_submission_provisional_id from resource where id = %s",$study_id);
	if ($cega_submission_provisional_id){
		return array("access_token" => $token, "cega_submission_provisional_id" => $cega_submission_provisional_id);
	}
	else{
		$submission =DB::queryFirstRow("SELECT
			resource.properties ->> 'title' AS title,
			resource.properties ->> 'description' AS description
		FROM
			resource
		WHERE
			id = %s",$study_id
		);

		if (!$submission){
			throw new Exception("Error: no study found with ID = ".$study_id, 1);
		}
		$submission['collaborators'] = array();
		$fields_string = json_encode($submission);
		// Prepare new cURL resource
		$url = CEGA_URL."/submissions";
		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLINFO_HEADER_OUT, true);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
	    // Set HTTP Header for POST request 
	    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
	        'Content-Type: application/json',
	            'Authorization: Bearer '.$token
	    ));
		
		curl_setopt($ch, CURLOPT_POSTFIELDS, $fields_string);
    	
    	
		// Submit the POST request
		fwrite(STDOUT,$url.PHP_EOL);
		$result = curl_exec($ch);
		// Close cURL session handle
		if(!$result) {
		    throw new Exception("ERROR: unable to post submission: ".curl_error($ch), 404);
		}
		curl_close($ch);
		$res = json_decode($result,true);
    	
		if(isset($res->error)) {
		    $mes = (isset($res->error_message)) ? $res->error_message : $res->error;
		    throw new Exception("IDP error: ".$mes, 404);
		}
		
		if (!isset($res['provisional_id'])){
			print_r($res);
			throw new Exception("Error: something wrong append. No submission provisional id available", 1);
			
		}
		DB::update("resource",array("cega_submission_provisional_id" => $res['provisional_id']),"id = %s",$study_id);
		return array("access_token" => $token, "cega_submission_provisional_id" => $res['provisional_id']); 
	}
	
}

function transferResource($study_id, $resource_type, $cega_files, $verbose){
    if ($verbose){
        fwrite(STDOUT,"Transfering ".$resource_type['name']."...".PHP_EOL);
    }
	$submission_params = registerSubmission($study_id);
	$cega_enums = getCegaEnums($submission_params);
	$resource_ids = array();
	$final_cega_resources = array();
	if ($resource_type['name'] === 'Study'){
		$resource_ids[] = $study_id;
	}
	else {
		$resource_ids = DB::queryFirstColumn("SELECT
			domain_resource_id
		FROM
			relationship
			INNER JOIN resource ON relationship.domain_resource_id = resource.id
			inner join resource_type on resource.resource_type_id = resource_type.id and resource_type.\"name\" = %s
		WHERE
			range_resource_id = %s
			AND is_active IS TRUE
			AND resource.status_type_id in ('DRA','SUB')
			"
			,$resource_type['name'],$study_id
		);
	}
	foreach($resource_ids as $resource_id){
		$cega_provisional_id = DB::queryFirstField("SELECT cega_provisional_id from resource where id = %s",$resource_id);
		if ($cega_provisional_id){
			if ($verbose){
				fwrite(STDOUT,$resource_id." has cega_provisional_id: ".$cega_provisional_id.PHP_EOL);
			}			
			foreach($resource_type['foreign_keys'] as $fk){
				$cega_endpoint = $fk['reference']['resource'];
				if (!isset($cega_resources[$cega_endpoint])){
					$final_cega_resources[$cega_endpoint] = array();
				}
				$final_cega_resources[$cega_endpoint][] = getCegaResource($submission_params,$cega_endpoint,$cega_provisional_id);
			}			
		}
		else{
			$properties_json = DB::queryFirstField("SELECT properties from resource where id = %s",$resource_id);
			if (!$properties_json){
				throw new Exception("Error: unable to get ".$resource_type['name'], 1);
			}
			$resource = json_decode($properties_json,true);
			$cega_resources = array();
			foreach($resource_type['foreign_keys'] as $fk){
				$cega_endpoint = $fk['reference']['resource'];
				if (!isset($cega_resources[$cega_endpoint])){
					$cega_resources[$cega_endpoint] = array();
				}
				$field = $fk['fields'][0];
				$cega_field = $fk['reference']['fields'][0];
				if ($cega_field == 'files'){
					foreach($resource[$field] as $sdafile_public_id){
						$filepath = DB::queryFirstField("SELECT resource.properties->>'filepath' as filepath from resource where resource.properties->>'public_id' = %s",$sdafile_public_id);
						if ($filepath){
							foreach($cega_files as $cega_file){
								if ($cega_file['relative_path'] == $filepath){
									if ($verbose){
										fwrite(STDOUT,$filepath." has provisional id: ".$cega_file['provisional_id'].PHP_EOL);
									}
									$cega_resources[$cega_endpoint][$cega_field][] = +$cega_file['provisional_id'];
									break;
								}
							}
						}
					}
				}
				else if ($cega_field == 'chromosomes'){
					continue;
				}
				else if (strpos($cega_field,'provisional_id') !== FALSE){
					if ($field == 'study_provisional_id'){
						$cega_resources[$cega_endpoint][$cega_field] = intval(DB::queryFirstField("SELECT cega_provisional_id from resource where id = %s",$study_id));
					}	
					else {
						if (strpos($cega_field,'provisional_ids') !== FALSE){
							$public_ids = $resource[$field] ?? array();	
							foreach($public_ids as $public_id){
								$cega_resources[$cega_endpoint][$cega_field][] = intval(DB::queryFirstField("SELECT cega_provisional_id from resource where resource.properties->>'public_id' = %s",$public_id));
							}
						}
						else {
							$public_id = $resource[$field] ?? "";
							if ($public_id){
								$cega_resources[$cega_endpoint][$cega_field] = intval(DB::queryFirstField("SELECT cega_provisional_id from resource where resource.properties->>'public_id' = %s",$public_id));
							}	
						}
					}
				}
				else if (isset($resource[$field])){
					$cega_field_enums = array();
					$enum_field = pluralize($field);
					if (in_array($field,$cega_enums)){	
						$cega_field_enums = getCegaEnums($submission_params,$field);
						// exit;
					}
					else if (in_array($enum_field,$cega_enums)){	
						$cega_field_enums = getCegaEnums($submission_params,$enum_field);
					}
					if (count($cega_field_enums)){
						
						// $cega_field_enums[0][$field] valid for run_file_types[] = {"run_file_type": "value","display_name": "label"}
						
						if (!in_array($resource[$field], $cega_field_enums) && !isset($cega_field_enums[0][$field])){
							if($field == 'instrument_model_id'){
								foreach($cega_field_enums as $instrument_model){
									if ($instrument_model['platform'].": ".$instrument_model['model'] == $resource[$field]){
										$resource[$field] = +$instrument_model['id'];
										break;
									}
								}
							}
							else if ($field == 'genome_id'){
								foreach($cega_field_enums as $genome){
									if ($genome['name'].": ".$genome['accession'] == $resource[$field]){
										$resource[$field] = +$genome['id'];
										$resource['chromosomes'] = array();
										$cega_chromosomes = getCegaEnums($submission_params,'chromosomes');
										foreach($cega_chromosomes as $cega_chromosome){
											if (+$cega_chromosome['genome_group_id'] == +$genome['group_id']){
												$cega_resources[$cega_endpoint]['chromosomes'][] = array("id" => $cega_chromosome['id'],"label" => $cega_chromosome['name']);
												$resource['chromosomes'][] = array("id" => $cega_chromosome['id'],"label" => $cega_chromosome['name']);
											}
										}
										
									}
								}
							}
						}						
					}
					
					$cega_resources[$cega_endpoint][$cega_field] = $resource[$field];
				}
			}
			foreach($cega_resources as $cega_endpoint => $cega_resource){
                fwrite(STDOUT,"Post resource...".PHP_EOL);
				$results = postCegaResource($submission_params,$cega_endpoint,$cega_resource,$verbose);
				foreach($results as $result){
					$fhd_updates = array();
					if (isset($result['provisional_id'])){
						$fhd_updates['cega_provisional_id'] = $result['provisional_id'];
					}
					if (isset($result['accession_id'])){
						$fhd_updates['cega_accession_id'] = $result['accession_id'];
					}
					if (count($fhd_updates)){
						DB::update("resource",$fhd_updates,"id = %s",$resource_id);
					}
					$final_cega_resources[$cega_endpoint][] = $result;					
				}
			}
		}
	}
	return $final_cega_resources;
}

function pluralize($field){
	$field = rtrim($field,'_id');
	if (substr($field,-1,1)=='y'){
		return substr($field,0,-1)."ies";
	}
	if (substr($field,-1,1)=='x'){
		return $field;
	}
	return $field."s";
}

function getCegaResource($submission_params,$cega_endpoint,$cega_provisional_id) {
	if (!isset($submission_params['access_token']) || !$submission_params['access_token']){
		$submission_params['access_token'] = getAccessToken('sp');
	}
	$url = CEGA_URL."/".$cega_endpoint."/".$cega_provisional_id;
	$ch = curl_init($url);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLINFO_HEADER_OUT, true);
	curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
    // Set HTTP Header for POST request 
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Content-Type: application/json',
            'Authorization: Bearer '.$submission_params['access_token']
    ));	
	
	
	// Submit the GET request
	$result = curl_exec($ch);
	$curl_info = curl_getinfo($ch);
	$http_code = $curl_info['http_code'];
	if (+$http_code > 299){
		fwrite(STDERR,$submission_params['access_token'].PHP_EOL);
		fwrite(STDERR,$url.PHP_EOL);
		fwrite(STDERR,$http_code.PHP_EOL);
		throw new Exception($result, 1);
	}
	
	// Close cURL session handle
	if(!$result) {
	    throw new Exception("ERROR: unable to post submission: ".curl_error($ch), 404);
	}
	curl_close($ch);
	$resource = json_decode($result,true);
	return $resource;
	
}

function getCegaEnums($submission_params,$field=null){
	if (!isset($submission_params['access_token']) || !$submission_params['access_token']){
		$submission_params['access_token'] = getAccessToken('sp');
	}	
	
	if ($field == 'instrument_model_id'){
		$field = 'platform_models';
	}
	
	$url = CEGA_URL."/enums";
	if ($field) $url .= "/".$field;
	$ch = curl_init($url);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLINFO_HEADER_OUT, true);
	curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
    // Set HTTP Header for POST request 
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Content-Type: application/json',
            'Authorization: Bearer '.$submission_params['access_token']
    ));	
	
	
	// Submit the GET request
	$result = curl_exec($ch);
	$curl_info = curl_getinfo($ch);
	$http_code = $curl_info['http_code'];
	if (+$http_code > 299){
		fwrite(STDERR,$submission_params['access_token'].PHP_EOL);
		fwrite(STDERR,$url.PHP_EOL);
		fwrite(STDERR,$http_code.PHP_EOL);
		throw new Exception($result, 1);
	}
	
	// Close cURL session handle
	if(!$result) {
	    throw new Exception("ERROR: unable to post submission: ".curl_error($ch), 404);
	}
	curl_close($ch);
	$enums = json_decode($result,true);
	if (in_array("platform_models",$enums)){
		$enums[]  = "instrument_model_id";
	}
	return $enums;
}

function registerCegaFiles($study_id,$verbose){

	$cega_files = getCegaFiles(array());
	$cega_paths = array_map(function($f){return $f['relative_path'];},$cega_files);
	$files = DB::query("SELECT * from sdafile_study_dataset_view where study_id = %s and cega_provisional_id is null",$study_id);

	foreach($files as $file){
		$post_variables = json_decode($file['properties'],true);
		if (in_array($post_variables['filepath'],$cega_paths)){
			foreach($cega_files as $cega_file){
				if ($cega_file['relative_path'] == $post_variables['filepath']){
					if ($cega_file['provisional_id']){
						DB::update("resource",array("cega_provisional_id" => $cega_file['provisional_id']),"id = %s",$file['sdafile_id']);
					}
				}
			}
			if($verbose) fwrite(STDOUT,$post_variables['filepath']." already registered".PHP_EOL);
			continue;
		}
		if (isset($post_variables['public_id'])) {
			unset($post_variables['public_id']);
		}
		if (isset($post_variables['title'])) {
			unset($post_variables['title']);
		}
		$post_variables['user'] = CEGA_USERNAME;
		$post_variables['operation'] = "upload";
		$rabbitmq = new RabbitMq();
		$correlation_id = $rabbitmq->sendMessage($post_variables,'files.inbox');
	}
	return getCegaFiles(array());
}

function getCegaFiles($submission_params){
	if (!isset($submission_params['access_token']) || !$submission_params['access_token']){
		$submission_params['access_token'] = getAccessToken('sp');
	}	
	$url = CEGA_URL."/files";
	$ch = curl_init($url);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLINFO_HEADER_OUT, true);
	curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
    // Set HTTP Header for POST request 
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Content-Type: application/json',
            'Authorization: Bearer '.$submission_params['access_token']
    ));	
	
	
	// Submit the GET request
	$result = curl_exec($ch);
	$curl_info = curl_getinfo($ch);
	$http_code = $curl_info['http_code'];
	if (+$http_code > 299){
		fwrite(STDERR,$submission_params['access_token'].PHP_EOL);
		fwrite(STDERR,$url.PHP_EOL);
		fwrite(STDERR,$http_code.PHP_EOL);
		throw new Exception($result, 1);
	}
	
	// Close cURL session handle
	if(!$result) {
	    throw new Exception("ERROR: unable to post submission: ".curl_error($ch), 404);
	}
	curl_close($ch);
	$files = json_decode($result,true);
	return $files;
	
}

function postCegaResource($submission_params,$cega_endpoint,$cega_resource,$verbose) {
	
	if (!isset($submission_params['access_token']) || !$submission_params['access_token']){
		$submission_params['access_token'] = getAccessToken('sp');
	}
	$fields_string = json_encode($cega_resource);
	$url = CEGA_URL."/submissions/".$submission_params['cega_submission_provisional_id']."/".$cega_endpoint;
	if ($verbose){
		fwrite(STDOUT,$url.PHP_EOL);
		fwrite(STDOUT,$fields_string.PHP_EOL);
	}
	$ch = curl_init($url);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLINFO_HEADER_OUT, true);
	curl_setopt($ch, CURLOPT_POST, true);
	curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
    // Set HTTP Header for POST request 
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Content-Type: application/json',
            'Authorization: Bearer '.$submission_params['access_token']
    ));	
	curl_setopt($ch, CURLOPT_POSTFIELDS, $fields_string);
	
	
	// Submit the GET request
	$result = curl_exec($ch);
	$curl_info = curl_getinfo($ch);
	$http_code = $curl_info['http_code'];
	if (+$http_code > 299){
		fwrite(STDERR,$submission_params['access_token'].PHP_EOL);
		fwrite(STDERR,$url.PHP_EOL);
		fwrite(STDERR,$fields_string.PHP_EOL);
		fwrite(STDERR,$http_code.PHP_EOL);
		throw new Exception($result, 1);
	}
	// Close cURL session handle
	if(!$result) {
	    throw new Exception("ERROR: unable to post ".$cega_endpoint.": ".curl_error($ch), 404);
	}
	curl_close($ch);
	$resource = json_decode($result,true);
	return $resource;
	
}

function test(){
    $out = getAccessToken('dac');
    print_r($out);
    return;
}

function main($study_id, $verbose){
	$out = getAccessToken('sp');
	$field = checkUuid($study_id) ? "id" : "properties->>'public_id'";
	$study = DB::queryFirstRow("SELECT * from resource where $field = %s",$study_id);
	$cega_resources = array();
	if (!$study){
		throw new Exception("Error: study is unknown", 1);
	}
	if ($study['status_type_id'] !== 'DRA' && $study['status_type_id'] !== 'SUB'){
		throw new Exception("Error: study doesn't have a published status: ".$study['status_type_id'], 1);
	}
	if ($verbose){
		fwrite(STDOUT,"Transfer Study ".$study_id." (".$study['id'].")".PHP_EOL);
	}
	$cega_files = registerCegaFiles($study['id'],$verbose);

	
	$resource_types = DB::query("SELECT
	resource_type.\"name\",
	resource_type.properties -> 'data_schema' -> 'x-cega' -> 'schema' ->> 'foreignKeys' AS foreign_keys
FROM
	resource_type
WHERE
	resource_type.properties -> 'data_schema' -> 'x-cega' -> 'schema' ->> 'foreignKeys' IS NOT NULL order by resource_type.rank;"
	);
	foreach($resource_types as $resource_type){
		$resource_type['foreign_keys'] = json_decode($resource_type['foreign_keys'],true);
		$cega_resources[$resource_type['name']] = transferResource($study['id'], $resource_type,$cega_files, $verbose);
	}
	return $cega_resources;
}

function print_usage(){
	fwrite(STDOUT,"usage: php ".basename(__FILE__)." -s <study_id> [-v][-h]".PHP_EOL);
	fwrite(STDOUT,"  -s <study_id> : study_id or study_public_id".PHP_EOL);
	fwrite(STDOUT,"  -v            : verbose".PHP_EOL);
	fwrite(STDOUT,"  -h            : print this help".PHP_EOL);
	return;
}

$args = getopt("s:vh");
$study_id = $args['s'] ?? null;
$verbose = isset($args['v']);
$help = isset($args['h']);

if (!$args || $help){
	print_usage();
	exit($help?0:1);
}

try {
    // test();
    // fwrite(STDOUT,PHP_EOL."TEST finished.".PHP_EOL);
    // exit();
	$out = main($study_id, $verbose);
	print_r($out);
	exit(0);
} catch (Exception $e) {
	fwrite(STDERR,$e->getMessage().PHP_EOL);
	exit(1);
}

?>