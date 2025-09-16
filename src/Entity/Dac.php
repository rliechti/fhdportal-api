<?php
use Symfony\Component\HttpClient\HttpClient;
use App\Service\JsonSchema\Validator;
use Symfony\Component\Dotenv\Dotenv;
use Ramsey\Uuid\Uuid;

if (!defined("DAC_API")){
    define("DAC_API",$_SERVER['DAC_API']);
}

if (!function_exists("checkUuid")){
    function checkUuid($str)
    {
        return (is_string($str) && preg_match("/^[0-9A-F]{8}-[0-9A-F]{4}-4[0-9A-F]{3}-[89AB][0-9A-F]{3}-[0-9A-F]{12}$/i", $str));
    }
}
if (!function_exists("getDatasetPolicy")){
    function getDatasetPolicy($auth, $dataset_id){
        $http = HttpClient::create([
 		   'verify_peer' => false,
		   'verify_host' => false,
		   ]
		);
        if(checkUuid($dataset_id) === FALSE){
            $dataset_id = DB::queryFirstField("SELECT id from resource where resource.properties ->> 'public_id' = %s", $dataset_id);
        }
        if (!$dataset_id){
            throw new Exception("Error: unknwown dataset: ".$dataset_id, 500);
        }
        $policy = array(
            "id" => '',
            "submission_id" => '',
            "status" => ''
        );
        $token = $auth->getBearerToken();
        // TODO: uncomment when implemented in DAC API
        // $response = $http->request(
        //     'GET',
        //     DAC_API.'/submissions',
        //     array(
        //         'headers' => [
        //             'Content-Type' => 'application/json',
        //             "Authorization" => 'Bearer '.$token
        //         ],
        //         'query' => [
        //             "datasetID" => $dataset_id
        //         ]
        //     )
        // );
        $response = $http->request(
            'GET',
            DAC_API.'/submissions',
            array(
                'headers' => [
                    'Content-Type' => 'application/json',
                    "Authorization" => 'Bearer '.$token
                ]
            )
        );
        
        $statusCode = $response->getStatusCode();
        if ($statusCode === 200){
            $content = $response->toArray();

            // condition for json-server based fake DAC
            $data = (isset($content['data'])) ? $content['data'] : $content;
            foreach($data as $d){
                if (!isset($d['datasetID'])){
                    $d['datasetID'] = (isset($d['dataset']) && isset($d['dataset']['id'])) ? $d['dataset']['id'] : "";
                }
                
                if ($d['datasetID'] == $dataset_id){
                   $policy['id']  = $d['policyID'];
                   $policy['status'] = $d['status'];
                   $policy['submission_id'] = $d['id'];
                }
            }
        }
        else{
            error_log("Error: get DAC dataset ($dataset_id) returns a ".$statusCode." error");
        }

        return $policy;
    }
    function fetchPolicies($http, $auth,$offset, $limit,$dacs){
        $token = $auth->getBearerToken();
        $response = $http->request(
            'GET',
            DAC_API.'/policies',
            array(
                'headers' => [
                    'Content-Type' => 'application/json',
                    "Authorization" => 'Bearer '.$token
                ],
                'query' => [
                    "limit" => $limit,
                    "offset" => $offset
                ]
            )
        );
        $statusCode = $response->getStatusCode();
        if ($statusCode === 200){
            $content = $response->toArray();
            // condition for json-server based fake DAC
            $policies = (isset($content['data'])) ? $content['data'] : $content;
            foreach($policies as $p){
                if (!isset($dacs[$p['dacID']])){
                    $responseDac = $http->request(
                        'GET',
                        DAC_API.'/dacs/'.$p['dacID'],
                        array(
                            'headers' => [
                                'Content-Type' => 'application/json',
                                "Authorization" => 'Bearer '.$token
                            ]
                        )
                    );
                    $dac = $responseDac->toArray();
                    $dacs[$p['dacID']] = array("name" => $dac['name'], "description" => $dac['description'],"policies" => array());
                }
                $dacs[$p['dacID']]['policies'][] = $p;
            }
            // condition for json-server based fake DAC
            if (isset($content['pagination']) && $content['pagination']['totalCount'] > $content['pagination']['offset'] + $content['pagination']['limit'] && count($content['data'])){
                $offset += $limit+1;
                $dacs = fetchPolicies($http, $auth,$offset, $limit,$dacs);
            }

        }
        else{
            error_log("ERROR: ".$statusCode." when fetchPolicies");
        }
        return $dacs;
    }

    function getPolicy($auth,$policy_id,$include_members = false){
        $http = HttpClient::create(
			[
			    'verify_peer' => false,
			    'verify_host' => false,
			]
		);
        $token = $auth->getBearerToken();
        $policy = array();
        $response = $http->request(
            'GET',
            DAC_API.'/policies/'.$policy_id,
            array(
                'headers' => [
                    'Content-Type' => 'application/json',
                    "Authorization" => 'Bearer '.$token
                ]
            )
        );
        $statusCode = $response->getStatusCode();
        if ($statusCode === 200){
            $content = $response->toArray();
            $policy = array(
                "title" => $content["title"],
                "url" => $content['url'],
                "dac_id" => $content['dacID'],
                "description" => $content['description']
            );
            $policy['dac'] = getDac($auth,$content['dacID'],$include_members);
        }
        else{
            error_log($statusCode);
            $content = $response->getContent();
            error_log($content);
        }
        return $policy;
    }

    function getDac($auth,$dac_id,$include_members = false){
        $http = HttpClient::create(
		[
		    'verify_peer' => false,
		    'verify_host' => false,
		]
		);
        $token = $auth->getBearerToken();
        $dac = array();
        $response = $http->request(
            'GET',
            DAC_API.'/dacs/'.$dac_id,
            array(
                'headers' => [
                    'Content-Type' => 'application/json',
                    "Authorization" => 'Bearer '.$token
                ]
            )
        );
        $statusCode = $response->getStatusCode();
        if ($statusCode === 200){
            $content = $response->toArray();
            $dac = array(
                "name" => $content["name"],
                "description" => $content['description']
            );
            if ($include_members){
                $dac['members'] = $content['members'];
            }
        }
        else{
            error_log($statusCode);
            $content = $response->getContent();
            ob_start();
            print_r($content);
            error_log(ob_get_clean());
        }
        return $dac;
    }

    function registerDatasetPolicy($auth,$dataset_id,$policy_id){
        $http = HttpClient::create(
		[
		    'verify_peer' => false,
		    'verify_host' => false,
		]
		);
        $policy_public_id = DB::queryFirstField("SELECT resource.properties->>'public_id' as policy_public_id from resource inner join resource_type on resource.resource_type_id = resource_type.id where resource_type.name = 'Policy' and resource.id = %s",$policy_id);
        if (!$policy_public_id){
            $dac_policy = getPolicy($auth,$policy_id);
            if (isset($dac_policy['id'])){
                unset($dac_policy['id']);
            }
            if (isset($dac_policy['dac'])){
                unset($dac_policy['dac']);
            }
            if (isset($dac_policy['title'])){
                $policy = array(
                    "id" => $policy_id,
                    "properties" => json_encode($dac_policy)
                );
                $validator = new Validator;
                // Retrieve the JSON schema from the resource type
                $json_schemas = DB::queryFirstField("SELECT resource_type.properties from resource_type where resource_type.\"name\" = 'Policy'");

                // Validate the data against the schema
                $schemas = json_decode($json_schemas);

                // $validationErrors = $validator->validate((object) $dac_policy, $schemas->data_schema);
                // error_log('HERE');
                // if (!empty($validationErrors)) {
                //     ob_start();
                //     print_r("VALIDATION ERROR: ");
                //     print_r($validationErrors);
                //     error_log(ob_get_clean());
                //     return new JsonResponse([
                //         'message' => 'Validation failed',
                //         'errors' => $validationErrors
                //     ], status: 400);
                // }
                $resource = array(
                    "id" => $policy_id,
                    "properties" => json_encode($dac_policy),
                    "resource_type_id" => DB::queryFirstField("SELECT id from resource_type where \"name\" = 'Policy'"),
                    "status_type_id" => "PUB"
                );
                DB::insert("resource",$resource);
                $policy_public_id = DB::queryFirstField("SELECT resource.properties->>'public_id' as policy_public_id from resource inner join resource_type on resource.resource_type_id = resource_type.id where resource_type.name = 'Policy' and resource.id = %s",$policy_id);
            }
            else{
                throw new Exception("Error: unable to get policy", 500);
            }
        }
        $relationship_id = DB::queryFirstField("SELECT id from relationship where domain_resource_id = %s and range_resource_id = %s",$dataset_id,$policy_id);
        if (!$relationship_id){
            $uuid = Uuid::uuid4();
            $relationship_id = $uuid->toString();
            $relationship_rule_id = DB::queryFirstField("SELECT id from relationship_rule_view where domain_type_name = 'Dataset' and range_type_name = 'Policy'");
            if ($relationship_rule_id){
                $relationship = array(
                  "id" => $relationship_id,
                  "relationship_rule_id" => $relationship_rule_id,
                  "domain_resource_id" => $dataset_id,
                  "predicate_id" => 1,
                  "range_resource_id" => $policy_id,
                  "sequence_number" => 1,
                  "is_active" => TRUE
                );
                DB::insert("relationship",$relationship);
                $relationship_id = DB::queryFirstField("SELECT id from relationship where domain_resource_id = %s and range_resource_id = %s",$dataset_id,$policy_id);
            }
            else{
                throw new Exception("Error: unable to register the link between Dataset and Policy", 500);
            }
        }
        $token = $auth->getBearerToken();
        $dac_submission = getDatasetPolicy($auth,$dataset_id);
        if (!$dac_submission || !isset($dac_submission['id']) || !$dac_submission['id']){
            $submission_body = [
                "datasetID" => $dataset_id,
                "id" => $relationship_id,
                "policyID" => $policy_id
            ];
            $response = $http->request(
                'POST',
                DAC_API.'/submissions',
                array(
                    'headers' => [
                        'Content-Type' => 'application/json',
                        "Authorization" => 'Bearer '.$token
                    ],
                    'body' => json_encode($submission_body)
                )
            );
            $statusCode = $response->getStatusCode();
            if ($statusCode !== 200){
                throw new Exception(json_encode($response->toArray()), $statusCode);
            }

        }

        return array("dataset_id" => $dataset_id, "relationship_id" => $relationship_id,"policy_id" => $policy_id);
    }

    function  getPolicyForm($http,$auth,$dataset_id,$policy_id, $form) {
        $http = HttpClient::create(
		[
		    'verify_peer' => false,
		    'verify_host' => false,
		]
		);
        $token = $auth->getBearerToken();
        $response = $http->request(
            'GET',
            DAC_API.'/policies/'.$policy_id."/".$form,
            array(
                'headers' => [
                    'Content-Type' => 'application/json',
                    "Authorization" => 'Bearer '.$token
                ]
            )
        );

        $statusCode = $response->getStatusCode();
        if ($statusCode === 200){
            $content = $response->toArray();
            return $content;
        }

        return array();

    }

    function getDataRequestFormSchemas($http,$auth,$dataset_id){
        $field = checkUuid($dataset_id) ? "domain_resource_id" : "domain_public_id";
        $policy_id = DB::queryFirstField("SELECT range_resource_id from relationship_view where range_type = 'Policy' and domain_type = 'Dataset' and $field = %s and is_active = TRUE",$dataset_id);
        if (!$policy_id){
            throw new Exception("No policy attached to this dataset", 500);
        }
        return getPolicyForm($http,$auth,$dataset_id,$policy_id,'daa-form');
    }
}

?>