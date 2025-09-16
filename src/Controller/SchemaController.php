<?php

namespace App\Controller;

use App\Service\Auth\Keycloak;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use OpenApi\Attributes as OA;

#[Route('/api')]
class SchemaController extends AbstractController
{
    #[Route('/schemas', name: 'get_schemas', methods: ['GET'])]
    #[OA\Get(
        path: '/api/schemas',
        summary: 'Retrieve all schemas',
        tags: ['Schemas'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Successful response',
                content: new OA\JsonContent(type: "array", items: new OA\Items(type: "object"))
            )
        ]
    )]
    public function getSchemas(Request $request, Keycloak $auth): JsonResponse
    {
        $dbSchemas = \DB::query("SELECT * from resource_type where properties is not null and properties->'data_schema'->>'x-resource' is not null");
        $schemas = array();
        foreach ($dbSchemas as $d) {
            $schemas[$d['name']] = json_decode($d['properties']);
        }
        return new JsonResponse($schemas);
    }
}
