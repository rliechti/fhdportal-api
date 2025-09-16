<?php

namespace App\Controller;

use App\Service\Auth\Keycloak;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Serializer\SerializerInterface;
use App\Service\JsonSchema\Validator;
use OpenApi\Attributes as OA;

#[Route('/api')]
class DatasetController extends AbstractController
{
    private function checkUuid($str)
    {
        return (is_string($str) && preg_match("/^[0-9A-F]{8}-[0-9A-F]{4}-4[0-9A-F]{3}-[89AB][0-9A-F]{3}-[0-9A-F]{12}$/i", $str));
    }
    
    private function checkRelationship($domain_id,$range_id){
        // check that dataset and study have relations
        $domain_field = $this->checkUuid($domain_id) ? "domain_resource_id" : "domain_public_id";
        $range_field = $this->checkUuid($range_id) ? "range_resource_id" : "range_public_id";
        $relation_id = \DB::queryFirstField("SELECT id from relationship_view where %b = %s and %b = %s and relationship_view.is_active = TRUE",$domain_field,$domain_id,$range_field,$range_id);
        if (!$relation_id){
            return false;
        }
        return $relation_id;
    }

    private function getPolicy($auth, $dataset_id){
        require __DIR__ . "/../Entity/Dac.php";
        return getDatasetPolicy($auth, $dataset_id);
    }
    #[OA\Get(
        path: '/api/submissions/{study_id}/datasets',
        summary: 'Get datasets for a study',
        tags: ['Datasets'],
        parameters: [
            new OA\Parameter(
                name: 'study_id',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string')
            )
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Successful response',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(type: 'object'))
            )
        ]
    )]
    #[Route('/submissions/{study_id}/datasets', name: 'get_study_datasets', methods: ['GET'])]
    public function getStudyDatasets(Keycloak $auth, SerializerInterface $serializer, string $study_id, Validator $validator): JsonResponse
    {
        require __DIR__ . "/../Entity/Resource.php";
        $datasets = listResources($auth, 'Dataset', $study_id, 'read');
        foreach($datasets as $idx => $dataset){
            $policy = $this->getPolicy($auth,$dataset['id']);
            foreach($policy as $key => $value){
                $datasets[$idx]["policy_".$key] = $value;
            }
            if (!$datasets[$idx]["policy_id"] && isset($datasets[$idx]["properties"]->policy_id) && $datasets[$idx]["properties"]->policy_id){
                $datasets[$idx]["policy_id"] = $datasets[$idx]["properties"]->policy_id;
                $datasets[$idx]["policy_status"] = 'draft';
            }
        }

        $content = $serializer->serialize($datasets, 'json');
        return new JsonResponse($content, json: true);
    }

    #[OA\Delete(
        path: '/api/submissions/{study_id}/datasets/{dataset_id}',
        summary: 'Delete specific dataset',
        tags: ['Datasets'],
        parameters: [
            new OA\Parameter(
                name: 'study_id',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string')
            ),
            new OA\Parameter(
                name: 'dataset_id',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string')
            )
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Successful response',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(type: 'string'))
            )
        ]
    )]
    #[Route('/submissions/{study_id}/datasets/{dataset_id}', name: 'delete_dataset', methods: ['DELETE'])]
    public function deleteDataset(Request $request, Keycloak $auth, SerializerInterface $serializer, string $study_id, string $dataset_id): JsonResponse
    {
        require __DIR__ . "/../Entity/Resource.php";
        if (!$this->checkRelationship($dataset_id,$study_id)){
            return new JsonResponse("Error: this dataset is not part of this study", status: 500);
        }
        
        $deleted_id = setResourceStatus($auth, $dataset_id, 'DEL');
        $content = $serializer->serialize($deleted_id, 'json');
        return new JsonResponse($content, json: true);
    }

    #[OA\Get(
        path: '/api/datasets',
        summary: 'Get all public datasets',
        tags: ['Datasets'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Successful response',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(type: 'object'))
            )
        ]
    )]
    #[Route('/datasets', name: 'get_datasets', methods: ['GET'])]
    public function getDatasets(Keycloak $auth, SerializerInterface $serializer): JsonResponse
    {
    	require __DIR__ . "/../Entity/Resource.php";
    	$isDacCli = $auth->isDacCli();
    	$status = $isDacCli ? "" : "published";
    	if ($isDacCli){
    		$datasets = listResources($auth, 'Dataset', null, 'read',$status);            
            foreach($datasets as $idx => $d){
                $datasets[$idx] = json_decode(json_encode($d),true);
            }
    	}
    	else{
    		$datasets = \DB::query("SELECT * from dataset_view where status_type_id = 'PUB' and released_date::date <= CURRENT_DATE");
    		$datasets = array_map(function($d){
    			$d['properties'] = json_decode($d['properties'],true);
    			return $d;
    		},$datasets);
    	}

    	$datasets = array_map(function($d) use ($isDacCli){
    		$return = array(
    			"public_id" => $d['properties']['public_id'],
    			"title" => $d['properties']['title'],
    			"description" => $d['properties']['description'],
    			"types" => $d['properties']['dataset_types'],
    			"nb_samples" => count($d['properties']['run_public_ids'])
    				// "policy_id" => $d['properties']['policy_id']
    		);
    		$return['policy_id'] = (isset($d['properties']['policy_id'])) ? $d['properties']['policy_id'] : null;
    		if ($isDacCli){
    			$return['id'] = $d['id'];
    			$return['status'] = $d['status'];
    			$return['submitter'] = array();
    			foreach($d as $k => $v){
    				if (strpos($k,'creator_') !== FALSE){
    					$sub_key = str_replace("creator_","",$k);
                        if ($sub_key != 'id'){
                            $return['submitter'][$sub_key] = $v;	    
                        }
			
    				}
    			}
    		}
    		return $return;
    	},$datasets);
    	$content = $serializer->serialize($datasets, 'json');
    	return new JsonResponse($content, json: true);
    }


    #[OA\Get(
        path: '/api/datasets/{dataset_id}',
        summary: 'Get a public dataset',
        tags: ['Datasets'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Successful response',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(type: 'object'))
            )
        ]
    )]
    #[Route('/datasets/{dataset_id}', name: 'get_dataset', methods: ['GET'])]
    public function getDataset(Keycloak $auth, SerializerInterface $serializer,string $dataset_id): JsonResponse
    {
        $isDacCli = $auth->isDacCli();

        try {
            require __DIR__ . "/../Entity/Resource.php";
            if ($isDacCli){
                $resource = getResource($auth, 'Dataset', $dataset_id, 'read','submitted');
            }
            else{
                $field =    $this->checkUuid($dataset_id) ? "id" : "public_id";
                $resource = \DB::queryFirstRow("SELECT * from dataset_view where status_type_id = 'PUB' and released_date::date <= CURRENT_DATE and $field = %s",$dataset_id);
                if (!$resource){
                    $resource = array("error" => array("message" => "unknown dataset"));
                }
                $resource['properties'] = json_decode($resource['properties']);
            }
            
            
            if (isset($resource['error'])){
                return new JsonResponse(['message' => $resource['error']['message']], status: 401);
            }
        } catch (Throwable $e) {
            return new JsonResponse(['message' => 'Unauthorized'], status: 401);
        }

        $dataset = (array) $resource['properties'];
        $dataset['study_public_id'] = $resource['study_public_id'];
        
        // TODO: replace DEL2 with PUB
        $status = $isDacCli ? "DRA" : "PUB";
        $dataset['files'] = \DB::queryFirstColumn("SELECT
	sda_files.properties
FROM
	dataset_view
	inner join relationship_view on dataset_view.id = relationship_view.domain_resource_id
	inner join resource on relationship_view.range_resource_id = resource.id and resource.status_type_id = '".$status."'
	inner join relationship_view as file_relationship on resource.id = file_relationship.range_resource_id
	inner join resource as sda_files on file_relationship.domain_resource_id = sda_files.id and sda_files.status_type_id = '".$status."'
	inner join resource_type as sda_file_type on sda_files.resource_type_id = sda_file_type.id and sda_file_type.name = 'SdaFile'
WHERE
	dataset_view.public_id = %s",$dataset_id);
       $dataset['files'] = array_map("json_decode",$dataset['files']);
       
       // format dataset for DAC //
       
       if($isDacCli){
           $study = getResource($auth,'Study',$dataset['study_public_id'],'read');
           $dataset['id'] = $resource['id'];
           $dataset['status'] = $resource['status'];
           $dataset['submitter'] = $resource['owner'];
           $dataset["study"] = array(
               "id" => $study['id'],
               "public_id" => $study['public_id'],
               "title" => $study['title'],
               "description" => $study['properties']->study_type
           );
           unset($dataset['study_public_id']);
           $dataset['nb_files'] = count($dataset['files']);
           unset($dataset['files']);
           $dataset['nb_runs'] = count($dataset['run_public_ids']);
           unset($dataset['run_public_ids']);
           $dataset['nb_analyses'] = count($dataset['analysis_public_ids']);
           unset($dataset['analysis_public_ids']);
       }
       if (isset($dataset['policy_id'])){
           require __DIR__ . "/../Entity/Dac.php";
           $dataset['policy'] = getPolicy($auth,$dataset['policy_id']);
       }
       $content = $serializer->serialize($dataset, 'json');
       return new JsonResponse($content, json: true);
    }

    #[OA\Post(
        path: '/api/submissions/{study_id}/datasets',
        summary: 'Create a dataset',
        tags: ['Datasets'],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'properties', type: 'object'),
                    new OA\Property(property: 'dataset_type', type: 'string')
                ]
            )
        ),
        parameters: [
            new OA\Parameter(
                name: 'study_id',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string')
            )
        ],
        responses: [
            new OA\Response(response: 200, description: 'Dataset created or updated', content: new OA\JsonContent()),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 500, description: 'Internal Server Error')
        ]
    )]
    #[Route('/submissions/{study_id}/datasets', name: 'post_dataset', methods: ['POST'])]
    public function postDataset(Request $request, Keycloak $auth, SerializerInterface $serializer, Validator $validator, string $study_id): JsonResponse
    {
        if ($auth->isGuest()) {
            return new JsonResponse(['message' => 'Unauthorized'], status: 401);
        }
        require __DIR__ . "/../Entity/Resource.php";
        $project_dir = $this->getParameter('kernel.project_dir');
        $content = $request->getContent();
        $content = json_decode($content, true);
        $dataset = $content['properties'];
        $datasetType = $content['dataset_type'];
        try {
            $project_dir = $this->getParameter('kernel.project_dir');
            $dataset = editResource($dataset, $datasetType, $study_id, $auth, $validator, $project_dir);
            $policy = $this->getPolicy($auth,$dataset['id']);
            foreach($policy as $key => $value){
                $dataset["policy_".$key] = $value;
            }
            if (!$dataset["policy_id"] && isset($dataset["properties"]->policy_id) && $dataset["properties"]->policy_id){
                $dataset["policy_id"] = $dataset["properties"]->policy_id;
                $dataset["policy_status"] = 'draft';
            }
            
            $content = $serializer->serialize($dataset, 'json');
            return new JsonResponse($content, json: true);
        } catch (\Exception $e) {
            return new JsonResponse($e->getMessage(), status: 500);
        }
    }

    #[OA\Put(
        path: '/api/submissions/{study_id}/datasets/{dataset_id}',
        summary: 'Update a dataset',
        tags: ['Datasets'],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'properties', type: 'object'),
                    new OA\Property(property: 'dataset_type', type: 'string')
                ]
            )
        ),
        parameters: [
            new OA\Parameter(
                name: 'study_id',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string')
            ),
            new OA\Parameter(
                name: 'dataset_id',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string')
            )
        ],
        responses: [
            new OA\Response(response: 200, description: 'Dataset created or updated', content: new OA\JsonContent()),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 500, description: 'Internal Server Error')
        ]
    )]
    #[Route('/submissions/{study_id}/datasets/{dataset_id}', name: 'put_dataset', methods: ['PUT'])]
    public function putDataset(Request $request, Keycloak $auth, SerializerInterface $serializer, Validator $validator, string $study_id, string $dataset_id): JsonResponse
    {
        if ($auth->isGuest()) {
            return new JsonResponse(['message' => 'Unauthorized'], status: 401);
        }
        // check that dataset and study have relations
        if (!$this->checkRelationship($dataset_id,$study_id)){
            return new JsonResponse("Error: this dataset is not part of this study", status: 500);
        }
        
        require __DIR__ . "/../Entity/Resource.php";
        $project_dir = $this->getParameter('kernel.project_dir');
        $content = $request->getContent();
        $content = json_decode($content, true);
        $dataset = $content['properties'];
        $datasetType = $content['dataset_type'];
        try {
            $project_dir = $this->getParameter('kernel.project_dir');
            $dataset = editResource($dataset, $datasetType, $study_id, $auth, $validator, $project_dir);
            $policy = $this->getPolicy($auth,$dataset['id']);
            foreach($policy as $key => $value){
                $dataset["policy_".$key] = $value;
            }
            if (!$dataset["policy_id"] && isset($dataset["properties"]->policy_id) && $dataset["properties"]->policy_id){
                $dataset["policy_id"] = $dataset["properties"]->policy_id;
                $dataset["policy_status"] = 'draft';
            }
            
            $content = $serializer->serialize($dataset, 'json');
            return new JsonResponse($content, json: true);
        } catch (\Exception $e) {
            return new JsonResponse($e->getMessage(), status: 500);
        }
    }

    #[OA\Patch(
        path: '/api/submissions/{study_id}/datasets/{dataset_id}',
        summary: 'Patch a dataset. Used by DAC to validate/reject policy assignment',
        tags: ['Datasets'],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'patch', type: 'object')
                ]
            )
        ),
        parameters: [
            new OA\Parameter(
                name: 'study_id',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string')
            ),
            new OA\Parameter(
                name: 'dataset_id',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string')
            )
        ],
        responses: [
            new OA\Response(response: 204, description: 'Dataset patched'),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 500, description: 'Internal Server Error')
        ]
    )]
    #[Route('/submissions/{study_id}/datasets/{dataset_id}', name: 'patch_dataset', methods: ['PATCH'])]
    public function patchDataset(Request $request, Keycloak $auth, SerializerInterface $serializer, Validator $validator, string $study_id, string $dataset_id): JsonResponse
    {
        if ($auth->isGuest()) {
            return new JsonResponse(['message' => 'Unauthorized'], status: 401);
        }
        
        if (!$this->checkRelationship($dataset_id,$study_id)){
            return new JsonResponse("Error: this dataset is not part of this study", status: 500);
        }
        // $auth->checkDacMember($dataset_id);
        
        require __DIR__ . "/../Entity/Resource.php";
        $content = $request->getContent();
        $patch = json_decode($content,true);
        try {
            patchResource($dataset_id, $patch, $auth);
            return new JsonResponse("", status: 204);
        } catch (\Exception $e) {
            return new JsonResponse($e->getMessage(), status: 500);
        }
    }

    #[OA\Post(
        path: '/api/submissions/{study_id}/upload-datasets',
        summary: 'Upload multiple datasets',
        tags: ['Datasets'],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'file', type: 'string', format: 'binary')
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Datasets uploaded', content: new OA\JsonContent()),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 500, description: 'Internal Server Error')
        ]
    )]
    #[Route('/submissions/{study_id}/upload-datasets', name: 'upload_dataset', methods: ['POST'])]
    public function uploadDatasets(Request $request, Keycloak $auth, SerializerInterface $serializer, Validator $validator, string $study_id): JsonResponse
    {
        if ($auth->isGuest()) {
            return new JsonResponse(['message' => 'Unauthorized'], status: 401);
        }
        require __DIR__ . "/../Entity/Resource.php";
        $content = $request->request->all();
        $project_dir = $this->getParameter('kernel.project_dir');
        $uploadResponse = uploadResources($auth, $study_id, $request, $project_dir, $content, $validator, $serializer);
        return new JsonResponse($uploadResponse, json: true);
    }
}
