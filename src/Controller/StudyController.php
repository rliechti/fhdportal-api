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
use Exception;

#[Route('/api')]
class StudyController extends AbstractController
{
    #[OA\Get(
        path: '/api/studies',
        summary: 'Get all public studies',
        tags: ['Studies'],
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
    #[Route('/studies', name: 'get_studies', methods: ['GET'])]
    public function getStudies(Request $request, Keycloak $auth, SerializerInterface $serializer): JsonResponse
    {
        require __DIR__ . "/../Entity/Resource.php";
        $studies = listResources($auth, 'Study', null, 'read', 'published');
        $studies = array_map(function($s){
            return array(
                "id" => $s['id'],
                "public_id" => $s['public_id'],
                "title" =>  $s['title'],
                "description" =>  $s['properties']->description ?? '',
                "study_type" => $s['properties']->study_type,
                "released_date" => $s['released_date'],
                "nb_datasets" =>  +$s['nb_public_datasets']
            );
        },(array) $studies);
        $content = $serializer->serialize($studies, 'json');
        return new JsonResponse($content, json: true);
    }
	
    #[OA\Get(
        path: '/api/studies/{study_id}',
        summary: 'Get a public study',
        tags: ['Studies'],
        parameters: [
            new OA\Parameter(
                name: "study_id",
                in: "path",
                required: true,
                schema: new OA\Schema(type: "string")
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
    #[Route('/studies/{study_id}', name: 'get_study', methods: ['GET'])]
    public function getStudy(Keycloak $auth, SerializerInterface $serializer, string $study_id): JsonResponse
    {
        require __DIR__ . "/../Entity/Resource.php";
        $study = getResource($auth, 'Study', $study_id, "read","published");
        if (isset($study['error'])){
            return new JsonResponse($study['error']['message'],status: $study['error']['status']);
        }
        $study = (array) $study['properties'];
        $study['datasets'] = listResources($auth,"Dataset",$study_id,"read");
        $study['datasets'] = array_map(function($d){            
            return array(
                "public_id" => $d['properties']->public_id,
                "title" => $d['properties']->title,
                "description" => $d['properties']->description,
                "types" => $d['properties']->dataset_types,
                "nb_samples" => count($d['properties']->run_public_ids)
            );
        },$study['datasets']);
        foreach($study['datasets'] as $idx => $d){
            $study['datasets'][$idx]['policy_id'] = \DB::queryFirstField("SELECT range_resource_id from relationship_view where range_type = 'Policy' and domain_type = 'Dataset' and domain_public_id = %s and is_active = TRUE",$d['public_id']);
        }
        $content = $serializer->serialize($study, 'json');
        return new JsonResponse($content, json: true);
        // return new JsonResponse($studies);
    }
	
}
