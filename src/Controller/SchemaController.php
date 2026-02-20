<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use OpenApi\Attributes as OA;
use MeekroDB;

#[Route('/api')]
class SchemaController extends AbstractController
{
    protected MeekroDB $db;

    public function __construct(MeekroDB $db)
    {
        $this->db = $db;
    }

    #[Route('/schemas', name: 'get_schemas', methods: ['GET'])]
    #[OA\Get(
        path: '/api/schemas',
        summary: 'Retrieve all schemas',
        tags: ['Schemas'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Successful response',
                content: new OA\JsonContent(
                    type: 'object'
                )
            )
        ]
    )]
    public function getSchemas(): JsonResponse
    {
        $dbSchemas = $this->db->query(
            "SELECT * FROM resource_type WHERE properties IS NOT NULL AND properties->'data_schema'->>'x-resource' IS NOT NULL"
        );
        
        $schemas = [];
        foreach ($dbSchemas as $d) {
            $schemas[$d['name']] = json_decode($d['properties'], true);
        }
        
        return new JsonResponse($schemas);
    }
}
