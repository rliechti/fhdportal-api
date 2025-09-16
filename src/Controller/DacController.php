<?php

namespace App\Controller;

use App\Service\Auth\Keycloak;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[Route('/api')]
class DacController extends AbstractController
{
    
    #[OA\Get(
        path: "/api/dacs",
        summary: "Get DACs",
        tags: ['DAC'],
        responses: [
            new OA\Response(
                response: 200,
                description: "Successful response",
                content: new OA\JsonContent(
                    type: "object",
                    properties: [
                        new OA\Property(property: "title", type: "string"),
                        new OA\Property(property: "description", type: "string"),
                        new OA\Property(property: "dac_title", type: "string"),
                        new OA\Property(property: "dac_description", type: "string"),
                        new OA\Property(property: "policies", type: "object")
                    ]
                )
            )
        ]
    )]
    #[Route('/dacs', name: 'get_dacs', methods: ['GET'])]
    public function getDacs(Keycloak $auth, SerializerInterface $serializer, HttpClientInterface $http): JsonResponse
    {
        require __DIR__."/../Entity/Dac.php";
        $dacs = array();
        $offset = 0;
        $limit = 99;
        $totalCount = -1;
		
		// TODO: remove in production
		
		$http = $http->withOptions([
		    'verify_host' => false,
		    'verify_peer' => false
		]);
        $dacs = fetchPolicies($http,$auth,$offset, $limit, $dacs);
        
        $json_content = $serializer->serialize($dacs, 'json');
        return new JsonResponse($json_content, json: true);
    }
    
    #[OA\Get(
        path: "/api/dacs/{dataset_id}/request-form",
        summary: "Get Dataset Request form from DAC",
        tags: ['DAC'],
        responses: [
            new OA\Response(
                response: 200,
                description: "Successful response",
                content: new OA\JsonContent(
                    type: "object",
                    properties: [
                        new OA\Property(property: "formSchema", type: "object"),
                        new OA\Property(property: "uiSchema", type: "object"),
                        new OA\Property(property: "formData", type: "object")
                    ]
                )
            )
        ]
    )]
    #[Route('/dacs/{dataset_id}/request-form', name: 'get_dataset_request_form_schemas', methods: ['GET'])]
    public function getDatasetRequestFormSchemas(Keycloak $auth, SerializerInterface $serializer, HttpClientInterface $http, string $dataset_id): JsonResponse
    {
        require __DIR__."/../Entity/Dac.php";
        
        // TODO: remove in production
        
        $http = $http->withOptions([
            'verify_host' => false,
            'verify_peer' => false
        ]);
        $schemas = getDataRequestFormSchemas($http,$auth,$dataset_id);
        $json_schemas = json_encode($schemas);
        return new JsonResponse($json_schemas, json: true);
    }
    #[OA\Get(
        path: "/api/dacs/{dataset_id}/policies/{policy_id}/{form}",
        summary: "Get Policy DAA or DGA form",
        tags: ['DAC'],
        responses: [
            new OA\Response(
                response: 200,
                description: "Successful response",
                content: new OA\JsonContent(
                    type: "object",
                    properties: [
                        new OA\Property(property: "formSchema", type: "object"),
                        new OA\Property(property: "uiSchema", type: "object"),
                        new OA\Property(property: "formData", type: "object")
                    ]
                )
            )
        ]
    )]
    #[Route('/dacs/{dataset_id}/policies/{policy_id}/{form}', name: 'get_policy_form', methods: ['GET'])]
    public function getPolicyForm(Keycloak $auth, SerializerInterface $serializer, HttpClientInterface $http, string $dataset_id, string $policy_id, string $form): JsonResponse
    {
        require __DIR__."/../Entity/Dac.php";
        
        // TODO: remove in production
        
        $http = $http->withOptions([
            'verify_host' => false,
            'verify_peer' => false
        ]);
        $schemas = getPolicyForm($http,$auth,$dataset_id,$policy_id, $form);
        $json_schemas = json_encode($schemas);
        return new JsonResponse($json_schemas, json: true);
    }
}