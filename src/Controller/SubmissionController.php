<?php

namespace App\Controller;

use App\Service\Auth\Keycloak;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Serializer\SerializerInterface;
use App\Service\JsonSchema\Validator;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Ramsey\Uuid\Uuid;
use OpenApi\Attributes as OA;
use App\Service\RabbitMq\RabbitMq;
use Exception;
use ZipArchive;

function getPubmeds($pmids)
{
    if (is_array($pmids)) {
        $pmids = implode(",", $pmids);
    }
    $pubmeds = array();
    $string = file_get_contents("https://eutils.ncbi.nlm.nih.gov/entrez/eutils/efetch.fcgi?db=pubmed&id=" . $pmids . "&retmode=xml");
    if ($string) {
        $xml = simplexml_load_string($string);
        foreach ($xml->PubmedArticle as $pubmedArticle) {
            $title = (string) $pubmedArticle->MedlineCitation->Article->ArticleTitle;
            $pmid = (string) $pubmedArticle->MedlineCitation->PMID;
            $doi = '';
            $date_year = (string) $pubmedArticle->MedlineCitation->Article->Journal->JournalIssue->PubDate->Year;
            $date_month = (string) $pubmedArticle->MedlineCitation->Article->Journal->JournalIssue->PubDate->Month;
            $date_day = (string) $pubmedArticle->MedlineCitation->Article->Journal->JournalIssue->PubDate->Day;
            $date = date('Y-m-d', strtotime($date_year . "-" . $date_month . "-" . $date_day));
            $journal = (string) $pubmedArticle->MedlineCitation->Article->Journal->Title;
            foreach ($pubmedArticle->MedlineCitation->Article->ELocationID as $locid) {
                if ((string)$locid['EIdType'] === 'doi') {
                    $doi = (string) $locid;
                }
            }
            if ($title !== '' && $title !== '0') {
                $pubmeds[$pmid] = array(
                    "id" => $pmid,
                    "doi" => $doi,
                    "title" => $title,
                    "journal" => $journal,
                    "date" => $date
                );
            }
        }
    }
    return $pubmeds;
}

#[Route('/api')]
class SubmissionController extends AbstractController
{

    #[OA\Get(
        path: '/api/submissions',
        summary: 'Get all submissions',
        tags: ['Submissions'],
        parameters: [
            new OA\Parameter(
                name: 'status',
                in: 'query',
                required: false,
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
    #[Route('/submissions', name: 'get_submissions', methods: ['GET'])]
    public function getSubmissions(Request $request, Keycloak $auth, SerializerInterface $serializer): JsonResponse
    {
        require __DIR__ . "/../Entity/Resource.php";
        $status =  $request->query->get('status');
        if (!$status) {
            $status = 'draft,submitted';
        }
        $submissions = listResources($auth, 'Study', null, 'review', $status);
        if ($status === 'published'){
            $submissions = array_map(function($s){
                return array(
                    "id" => $s['id'],
                    "public_id" => $s['public_id'],
                    "title" =>  $s['title'],
                    "study_type" => $s['properties']->study_type,
                    "released_date" => $s['released_date'],
                    "nb_datasets" =>  +$s['nb_public_datasets']
                );
            },(array) $submissions);
        }
        $content = $serializer->serialize($submissions, 'json');
        return new JsonResponse($content, json: true);
    }

    #[OA\Get(
        path: '/api/{resource_type}/template',
        summary: 'Download resource template',
        tags: ['Resource Types'],
        parameters: [
            new OA\Parameter(
                name: 'resource_type',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string')
            )
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Template downloaded successfully',
                content: new OA\JsonContent(type: 'string', format: 'binary')
            )
        ]
    )]
    #[Route('/{resource_type}/template', name: 'download_template', methods: ['GET'])]
    public function downloadResourceTemplate(Request $request, Keycloak $auth, SerializerInterface $serializer, string $resource_type): BinaryFileResponse
    {
        require __DIR__ . "/../Entity/Resource.php";
        $project_dir = $this->getParameter('kernel.project_dir');
        if (strtolower($resource_type) == "submission"){
            $resource_types = \DB::queryFirstColumn("SELECT name from resource_type where resource_type.properties->'data_schema'->'properties'->'extra_attributes' is not null");
            $files = array();
            foreach($resource_types as $resource_type){
                $files[$resource_type] = downloadTemplate($auth, $resource_type, $project_dir,"csv");
            }
            $zip = new ZipArchive();
            $filepath = tempnam(sys_get_temp_dir(), 'zip');

            if ($zip->open($filepath, ZipArchive::CREATE) !== true) {
                throw new \RuntimeException('Cannot open ' . $filepath);
            }

            // Add multiple files
            foreach ($files as $filename => $file) {
                if (file_exists($file)) {
                    $zip->addFile($file, $filename.".csv");
                } else {
                    throw new NotFoundHttpException('File not found: ' . $file);
                }
            }
            $zip->close();
            $response = new BinaryFileResponse($filepath);
            $response->headers->set('Content-Type', 'application/zip');
            return $response;

        }
        $filepath = downloadTemplate($auth, $resource_type, $project_dir,"xlsx");    
        return new BinaryFileResponse($filepath);
    }

    #[OA\Get(
        path: '/api/cli',
        summary: 'Download cli package',
        tags: ['CLI'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'CLI downloaded successfully',
                content: new OA\JsonContent(type: 'string', format: 'binary')
            )
        ]
    )]
    #[Route('/cli', name: 'download_cli', methods: ['GET'])]
    public function downloadCli(Request $request): BinaryFileResponse
    {
        $filepath = dirname(dirname(__DIR__))."/tools/fega-cli.zip";    
        if (!file_exists($filepath)){
            return new JsonResponse(['message' => 'File not found'], 404);
        }
        $response = new BinaryFileResponse($filepath);
        $response->headers->set('Content-Type', 'application/zip');
        return $response;
    }

    #[OA\Get(
        path: '/api/submissions/{study_id}/download',
        summary: 'Download submission by ID',
        tags: ['Submissions'],
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
                description: 'Submission downloaded successfully',
                content: new OA\JsonContent(type: 'string', format: 'binary')
            ),
            new OA\Response(
                response: 404,
                description: 'Submission not found'
            )
        ]
    )]
    #[Route('/submissions/{study_id}/download', name: 'download_submissions', methods: ['GET'])]
    public function downloadSubmissions(Request $request, Keycloak $auth, SerializerInterface $serializer, string $study_id): BinaryFileResponse
    {
        require __DIR__ . "/../Entity/Resource.php";
        $project_dir = $this->getParameter('kernel.project_dir');
        $filepath = downloadSubmission($auth, $study_id, $project_dir);
        return new BinaryFileResponse($filepath);
    }

    #[OA\Get(
        path: '/api/submissions/{study_id}',
        summary: 'Get submission by ID',
        tags: ['Submissions'],
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
                content: new OA\JsonContent(type: 'object')
            ),
            new OA\Response(
                response: 404,
                description: 'Submission not found'
            )
        ]
    )]
    #[Route('/submissions/{study_id}', name: 'get_submission', methods: ['GET'])]
    public function getSubmission(Keycloak $auth, SerializerInterface $serializer, string $study_id): JsonResponse
    {
        require __DIR__ . "/../Entity/Resource.php";
        $submission = getResource($auth, 'Study', $study_id, "read");
        $submission['sampleTypes'] = array();
        $submission['experimentTypes'] = array();
        $submission['datasetTypes'] = array();
        $submission['analysisTypes'] = array();
        $submission['runTypes'] = array();
        if (is_array($submission['relationTypes']) && count($submission['relationTypes'])){
            foreach ($submission['relationTypes'] as $rel) {
                if (stripos($rel['label'], 'sample') !== false) {
                    $submission['sampleTypes'][] = $rel;
                } elseif (stripos($rel['label'], 'experiment') !== false) {
                    $submission['experimentTypes'][] = $rel;
                } elseif (stripos($rel['label'], 'dataset') !== false) {
                    $submission['datasetTypes'][] = $rel;
                } elseif (stripos($rel['label'], 'run') !== false) {
                    $submission['runTypes'][] = $rel;
                } elseif (stripos($rel['label'], 'analysis') !== false) {
                    $submission['analysisTypes'][] = $rel;
                }
            }
            unset($submission['relationTypes']);            
        }
        $content = $serializer->serialize($submission, 'json');
        return new JsonResponse($content, json: true);
        // return new JsonResponse($submissions);
    }

    #[OA\Get(
        path: '/api/pubmeds/{pmid}',
        summary: 'Get PubMed documents by PubMedID',
        tags: ['PubMed'],
        parameters: [
            new OA\Parameter(
                name: 'pmid',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string')
            )
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Successful response',
                content: new OA\JsonContent(type: 'object')
            ),
            new OA\Response(
                response: 404,
                description: 'PubMed record not found'
            )
        ]
    )]
    #[Route('/pubmeds/{pmid}', name: 'get_pubmeds', methods: ['GET'])]
    public function getPubmeds(
        string $pmid
    ): JsonResponse {
        $pubmeds = getPubmeds($pmid);
        return new JsonResponse($pubmeds);
    }

    #[OA\Post(
        path: '/api/submissions/upload-study',
        summary: 'Upload a new study',
        tags: ['Submissions'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(type: 'object')
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Study uploaded successfully',
                content: new OA\JsonContent(type: 'object')
            ),
            new OA\Response(
                response: 401,
                description: 'Unauthorized'
            ),
            new OA\Response(
                response: 400,
                description: 'Invalid input'
            )
        ]
    )]
    #[Route('/submissions/upload-study', name: 'upload_study', methods: ['POST'])]
    public function uploadStudy(Request $request, Keycloak $auth, SerializerInterface $serializer, Validator $validator): JsonResponse
    {
        if ($auth->isGuest()) {
            return new JsonResponse(['message' => 'Unauthorized'], status: 401);
        }
        require __DIR__ . "/../Entity/Resource.php";
        $content = $request->request->all();
        $project_dir = $this->getParameter('kernel.project_dir');
        $uploadResponse = uploadResources($auth, 'new', $request, $project_dir, $content, $validator, $serializer);
        return new JsonResponse($uploadResponse, json: true);
    }

    #[OA\Post(
        path: '/api/submissions',
        summary: 'Submit a new study',
        tags: ['Submissions'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(type: 'object')
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Study created or updated successfully',
                content: new OA\JsonContent(type: 'object')
            ),
            new OA\Response(
                response: 401,
                description: 'Unauthorized'
            ),
            new OA\Response(
                response: 400,
                description: 'Invalid input'
            )
        ]
    )]
    #[Route('/submissions', name: 'post_submission', methods: ['POST'])]
    public function postSubmission(Request $request, Keycloak $auth, SerializerInterface $serializer, Validator $validator): JsonResponse
    {
        if ($auth->isGuest()) {
            return new JsonResponse(['message' => 'Unauthorized'], status: 401);
        }
        require __DIR__ . "/../Entity/Resource.php";
        $content = $request->getContent();
        $study = json_decode($content);
        try {

            $publications = array();
            if (isset($study->pubmed_ids) && is_array($study->pubmed_ids) && count($study->pubmed_ids)) {
                $publications = getPubmeds($study->pubmed_ids);
                $study->pubmed_ids = array_keys($publications);
            }
            $study_id = (isset($study->id)) ? $study->id : 'new';
            $project_dir = $this->getParameter('kernel.project_dir');
            $study = editResource($study, 'Study', $study_id, $auth, $validator, $project_dir);

            // publications
            if (count($publications) > 0) {
                foreach ($publications as $pmid => $publication) {
                    $json_schemas = \DB::queryFirstField("SELECT properties from resource_type where name = 'Publication'");
                    if (!$json_schemas) {
                        throw new Exception("Unknown schemas", 500);
                    }
                    $schemas = json_decode($json_schemas);
                    if (!isset($schemas->data_schema)) {
                        throw new Exception("Unknown data_schema", 500);
                    }

                    // Validate the data against the schema
                    $publication['id'] = intval($publication['id']);
                    $validationErrors = $validator->validate((object) $publication, $schemas->data_schema);
                    if (!empty($validationErrors)) {
                        $message = implode(". ", array_map(function ($v) {
                            return $v['message'];
                        }, $validationErrors));
                        throw new Exception($message, 400);
                    }
                    $pub_resource = array(
                        "id" => null,
                        "properties" => json_encode($publication),
                        "resource_type_id" => \DB::queryFirstField("SELECT id from resource_type where name = 'Publication'"),
                        "status_type_id" => "PUB"
                    );

                    $pub_resource['id'] = \DB::queryFirstField("SELECT id from resource where resource.properties ->> 'id' = %s", $pmid);
                    if (!$pub_resource['id']) {
                        $uuid = Uuid::uuid4();
                        $pub_resource['id'] = $uuid->toString();
                        \DB::insert("resource", $pub_resource);
                    }
                }
            }
            $content = $serializer->serialize($study, 'json');
            return new JsonResponse($content, json: true);
        } catch (\Exception $e) {
            // $status = is_numeric($e->getCode()) ? $e->getCode() : 500;
            return new JsonResponse($e->getMessage(), status: 500);
        }
    }
    #[OA\Put(
        path: '/api/submissions/{study_id}',
        summary: 'Update a study submission',
        tags: ['Submissions'],
        parameters: [
            new OA\Parameter(
                name: 'study_id',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string')
            )
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(type: 'object')
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Submission created or updated successfully',
                content: new OA\JsonContent(type: 'object')
            ),
            new OA\Response(
                response: 401,
                description: 'Unauthorized'
            ),
            new OA\Response(
                response: 400,
                description: 'Invalid input'
            )
        ]
    )]
    #[Route('/submissions/{study_id}', name: 'put_submission', methods: ['PUT'])]
    public function putSubmission(Request $request, Keycloak $auth, SerializerInterface $serializer, Validator $validator, string $study_id): JsonResponse
    {
        if ($auth->isGuest()) {
            return new JsonResponse(['message' => 'Unauthorized'], status: 401);
        }
        require __DIR__ . "/../Entity/Resource.php";
        $content = $request->getContent();
        $submission = json_decode($content);
        try {

            $publications = array();
            if (isset($submission->pubmed_ids) && is_array($submission->pubmed_ids) && count($submission->pubmed_ids)) {
                $publications = getPubmeds($submission->pubmed_ids);
                $submission->pubmed_ids = array_keys($publications);
            }

            $project_dir = $this->getParameter('kernel.project_dir');
            $submission = editResource($submission, 'Study', $study_id, $auth, $validator, $project_dir);

            // publications
            if (count($publications) > 0) {
                foreach ($publications as $pmid => $publication) {
                    $json_schemas = \DB::queryFirstField("SELECT properties from resource_type where name = 'Publication'");
                    if (!$json_schemas) {
                        throw new Exception("Unknown schemas", 500);
                    }
                    $schemas = json_decode($json_schemas);
                    if (!isset($schemas->data_schema)) {
                        throw new Exception("Unknown data_schema", 500);
                    }

                    // Validate the data against the schema
                    $publication['id'] = intval($publication['id']);
                    $validationErrors = $validator->validate((object) $publication, $schemas->data_schema);
                    if (!empty($validationErrors)) {
                        $message = implode(". ", array_map(function ($v) {
                            return $v['message'];
                        }, $validationErrors));
                        throw new Exception($message, 400);
                    }
                    $pub_resource = array(
                        "id" => null,
                        "properties" => json_encode($publication),
                        "resource_type_id" => \DB::queryFirstField("SELECT id from resource_type where name = 'Publication'"),
                        "status_type_id" => "PUB"
                    );

                    $pub_resource['id'] = \DB::queryFirstField("SELECT id from resource where resource.properties ->> 'id' = %s", $pmid);
                    if (!$pub_resource['id']) {
                        $uuid = Uuid::uuid4();
                        $pub_resource['id'] = $uuid->toString();
                        \DB::insert("resource", $pub_resource);
                    }
                }
            }
            $content = $serializer->serialize($submission, 'json');
            return new JsonResponse($content, json: true);
        } catch (\Exception $e) {
            // $status = is_numeric($e->getCode()) ? $e->getCode() : 500;
            return new JsonResponse($e->getMessage(), status: 500);
        }
    }

    #[OA\Patch(
        path: '/api/submissions/{study_id}',
        summary: 'Patch a submitted study, set status',
        tags: ['Submissions'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(type: 'object')
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Study status updated successfully',
                content: new OA\JsonContent(type: 'object')
            ),
            new OA\Response(
                response: 400,
                description: 'Invalid input'
            )
        ]
    )]
    #[Route('/submissions/{study_id}', name: 'patch_submission', methods: ['PATCH'])]
    public function patchSubmission(Request $request, Keycloak $auth, SerializerInterface $serializer, Validator $validator, string $study_id): JsonResponse
    {
        if ($auth->isGuest()) {
            return new JsonResponse(['message' => 'Unauthorized'], status: 401);
        }
        $user = $auth->getDetails();
        require __DIR__ . "/../Entity/Resource.php";
        $content = $request->getContent();
        $patch = json_decode($content,true);
        try {
            require __DIR__ . "/../Entity/Dac.php";
            require __DIR__."/../../tools/keycloak.php";
            $study = patchResource($study_id, $patch, $auth);
            $field = (checkUuid($study_id)) ? "study_id" : "study_public_id";
            if (isset($patch['status_type_id'])){
                if ($patch['status_type_id'] == 'SUB'){
                    // register SDA files
                    $sdafiles = \DB::query("SELECT sdafile_public_id, dataset_public_id, study_public_id  from sdafile_study_dataset_view where ".$field." = %s and dataset_public_id is not null",$study_id);
                    if ($sdafiles){
                        $rabbitmq = new \App\Service\RabbitMq\RabbitMq;
                        $file_submission_result = $rabbitmq->mapSDAfiles($sdafiles);
                    }
                    // register DAC
                    $datasets = \DB::query("SELECT id as dataset_id, properties->>'policy_id' as policy_id, study_id from dataset_view where ".$field." = %s",$study_id);
                    foreach($datasets as $d){
                        if ($d['policy_id']){
                            registerDatasetPolicy($auth,$d['dataset_id'],$d['policy_id']);   
                            // provide review (commenter) access to dac members
                            $policy = \getPolicy($auth,$d['policy_id'],true) ;
                            if (isset($policy['dac']) && isset($policy['dac']['members'])){
                                foreach($policy['dac']['members'] as $dac_member){
                                    if ($dac_member['email']){
                                        $users = \getKeyCloakUsers(null,"email=".$dac_member['email']);
                                        foreach($users as $u){
                                            $dac_member_user_id = \DB::queryFirstField("SELECT id from \"user\" where external_id = %s",$u['username']);
                                            if (!$dac_member_user_id){
                                                $dac_member_user = array("email" => $dac_member['email'],"external_id" => $u['username']);
                                                \DB::insert("user",$dac_member_user);
                                                $dac_member_user_id = \DB::insertId();
                                            }
                                            $access = \DB::queryFirstRow("SELECT * from resource_acl where resource_id = %s and user_id = %i", $d['study_id'], $dac_member_user_id);
                                            if (!$access) {
                                                \DB::insert("resource_acl", array('user_id' => $dac_member_user_id,'resource_id' => $d['study_id'],'role_id' => 'COM'));
                                            } elseif ($access['role_id'] != 'COM' && $access['role_id'] != 'OWN') {
                                                \DB::update("resource_acl", array('role_id' => 'COM'), 'resource_id = %s and user_id = %i', $d['study_id'], $dac_member_user_id);
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }                    
                }
                
                // remove policy_id from properties and remove relationsihip
                else if ($patch['status_type_id'] !== 'PUB' && $patch['status_type_id'] !== 'VER'){
                    $datasets = \DB::query("SELECT id as dataset_id, properties->>'policy_id' as policy_id, study_id from dataset_view where ".$field." = %s",$study_id);
                    foreach($datasets as $d){
                        if ($d['policy_id']){
                            \DB::query("UPDATE resource SET properties = jsonb_set(properties, '{policy_id}', '\"\"') WHERE id = %s",$d['dataset_id']);
                            $properties = \DB::queryFirstField("SELECT properties from resource where id = %s",$d['dataset_id']);
                            $uuid = Uuid::uuid4();
                            $log_id = $uuid->toString();
                            $log = array(
                                "id" => $log_id,
                                "resource_id" => $d['dataset_id'],
                                "user_id" => $user['id'],
                                "action_type_id" => "DEL",
                                "properties" => $properties
                            );
                            \DB::insert("resource_log", $log);        
                            $relationship_id = \DB::queryFirstField("SELECT id from relationship where domain_resource_id = %s_dataset_id and range_resource_id = %s_policy_id",$d);
                            if ($relationship_id){
                                \DB::update("relationship",array("is_active" => FALSE),"id = %s",$relationship_id);
                                $uuid = Uuid::uuid4();
                                $log_id = $uuid->toString();
                                $log = array(
                                    "id" => $log_id,
                                    "relationship_id" => $relationship_id,
                                    "user_id" => $user['id'],
                                    "action_type_id" => "DEL"
                                );
                                \DB::insert("relationship_log",$log);
                            }
                            // remove  access to dac members
                            $policy = \getPolicy($auth,$d['policy_id'],true) ;
                            if (isset($policy['dac']) && isset($policy['dac']['members'])){
                                foreach($policy['dac']['members'] as $dac_member){
                                    if ($dac_member['email']){
                                        $users = \getKeyCloakUsers(null,"email=".$dac_member['email']);
                                        foreach($users as $u){
                                            $dac_member_user_id = \DB::queryFirstField("SELECT id from \"user\" where external_id = %s",$u['username']);
                                            if ($dac_member_user_id){
                                                \DB::delete("resource_acl", "user_id = %i and resource_id = %s and role_id = 'COM'",$dac_member_user_id,$d['study_id']);
                                            }
                                        }
                                    }
                                }
                            }
                            
                        }
                    }                                        
                }
            }
            return new JsonResponse("",status: 204);
        } catch (\Exception $e) {
            return new JsonResponse($e->getMessage(), status: 500);
        }
    }

    #[OA\Delete(
        path: '/api/submissions/{study_id}',
        summary: 'Delete a submission by ID',
        tags: ['Submissions'],
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
                response: 204,
                description: 'Submission deleted successfully'
            ),
            new OA\Response(
                response: 404,
                description: 'Submission not found'
            )
        ]
    )]
    #[Route('/submissions/{study_id}', name: 'delete_submission', methods: ['DELETE'])]
    public function deleteSubmission(string $study_id, Keycloak $auth, SerializerInterface $serializer): JsonResponse
    {
        require __DIR__ . "/../Entity/Resource.php";
        $deleted_id = setResourceStatus($auth, $study_id, 'DEL');
        $content = $serializer->serialize($deleted_id, 'json');
        return new JsonResponse($content, json: true);
        // return new JsonResponse($submissions);
    }

    #[OA\Post(
        path: '/api/submissions/{study_id}/users',
        summary: 'Add a user to a submission',
        tags: ['Submissions'],
        parameters: [
            new OA\Parameter(
                name: 'study_id',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string')
            )
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(type: 'object')
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'User added to study successfully',
                content: new OA\JsonContent(type: 'object')
            ),
            new OA\Response(
                response: 400,
                description: 'Invalid input'
            )
        ]
    )]
    #[Route('/submissions/{study_id}/users', name: 'post_submission_user', methods: ['POST'])]
    public function postSubmissionUser(string $study_id, Request $request, Keycloak $auth, SerializerInterface $serializer): JsonResponse
    {
        if ($auth->isGuest()) {
            return new JsonResponse(['message' => 'Unauthorized'], status: 401);
        }
        require __DIR__ . "/../Entity/Resource.php";
        $content = $request->getContent();
        $user = json_decode($content, true);
        try {
            $study = editResourceUser($study_id, $user, $auth);
            $content = $serializer->serialize($study, 'json');
            return new JsonResponse($content, json: true);
        } catch (\Exception $e) {
            return new JsonResponse($e->getMessage(), status: 500);
        }
    }

    #[OA\Delete(
        path: '/api/submissions/{study_id}/users/{user_id}',
        summary: 'Remove a user from a submission',
        tags: ['Submissions'],
        parameters: [
            new OA\Parameter(
                name: 'study_id',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string')
            ),
            new OA\Parameter(
                name: 'user_id',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string')
            )
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'User removed from study successfully',
                content: new OA\JsonContent(type: 'object')
            ),
            new OA\Response(
                response: 404,
                description: 'Study or user not found'
            )
        ]
    )]
    #[Route('/submissions/{study_id}/users/{user_id}', name: 'delete_submission_user', methods: ['DELETE'])]
    public function deleteSubmissionUser(string $study_id, string $user_id, SerializerInterface $serializer): JsonResponse
    {
        require __DIR__ . "/../Entity/Resource.php";
        try {
            $study = deleteResourceUser($study_id, $user_id);
            $content = $serializer->serialize($study, 'json');
            return new JsonResponse($content, json: true);
        } catch (\Exception $e) {
            return new JsonResponse($e->getMessage(), status: 500);
        }
    }

    #[OA\Get(
        path: '/api/submissions/{study_id}/raw-files',
        summary: 'Get raw files for a submission',
        tags: ['Submissions'],
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
                description: 'Raw files retrieved successfully',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(type: 'object'))
            ),
            new OA\Response(
                response: 401,
                description: 'Unauthorized'
            ),
            new OA\Response(
                response: 500,
                description: 'Internal server error'
            )
        ]
    )]
    #[Route('/submissions/{study_id}/raw-files', name: 'get_raw_files', methods: ['GET'])]
    public function getRawFiles(string $study_id, Request $request, Keycloak $auth, SerializerInterface $serializer): JsonResponse
    {
        if ($auth->isGuest()) {
            return new JsonResponse(['message' => 'Unauthorized'], status: 401);
        }
        require __DIR__ . "/../Entity/FileRepository.php";
        try {
            $files = getRawFiles($study_id, $auth);
            $content = $serializer->serialize($files, 'json');
            return new JsonResponse($content, json: true);
        } catch (Exception $e) {
            return new JsonResponse($e->getMessage(), status: 500);
        }
    }

    #[OA\Get(
        path: '/api/submissions/{study_id}/analysis-files',
        summary: 'Get analysis files for a study',
        tags: ['Submissions'],
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
                description: 'Analysis files retrieved successfully',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(type: 'object'))
            ),
            new OA\Response(
                response: 401,
                description: 'Unauthorized'
            ),
            new OA\Response(
                response: 500,
                description: 'Internal server error'
            )
        ]
    )]
    #[Route('/submissions/{study_id}/analysis-files', name: 'get_analysis_files', methods: ['GET'])]
    public function getAnalysisFiles(string $study_id, Request $request, Keycloak $auth, SerializerInterface $serializer): JsonResponse
    {
        if ($auth->isGuest()) {
            return new JsonResponse(['message' => 'Unauthorized'], status: 401);
        }
        require __DIR__ . "/../Entity/FileRepository.php";
        try {
            $files = getAnalysisFiles($study_id, $auth);
            $content = $serializer->serialize($files, 'json');
            return new JsonResponse($content, json: true);
        } catch (Exception $e) {
            return new JsonResponse($e->getMessage(), status: 500);
        }
    }
}
