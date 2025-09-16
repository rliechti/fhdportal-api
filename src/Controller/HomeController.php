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
class HomeController extends AbstractController
{
    #[Route('/', name: 'about', methods: ['GET'])]
    #[OA\Get(
        path: "/api/",
        summary: "Get API information",
        responses: [
            new OA\Response(
                response: 200,
                description: "Returns API information",
                content: new OA\JsonContent(
                    type: "object",
                    properties: [
                        new OA\Property(property: "name", type: "string"),
                        new OA\Property(property: "version", type: "string"),
                        new OA\Property(property: "authenticated", type: "boolean"),
                        new OA\Property(property: "user", type: "object")
                    ]
                )
            )
        ]
    )]
    public function getInfo(Keycloak $auth): JsonResponse
    {
        $name = 'FEGA Switzerland API';
        $version = '0.0.1';

        $content = [
            'cors' => getenv("CORS_ALLOW_ORIGIN"),
            'name' => $name,
            'version' => $version,
            'authenticated' => $auth->isAuthenticated(),
            'user' => $auth->getDetails(),
        ];

        return new JsonResponse($content);
    }

    #[OA\Get(
        path: "/api/status-types",
        summary: "Get status types",
        tags: ['Vocabularies'],
        responses: [
            new OA\Response(
                response: 200,
                description: "Successful response",
                content: new OA\JsonContent(
                    type: "array",
                    items: new OA\Items(type: "string")
                )
            )
        ]
    )]
    #[Route('/status-types', name: 'get_status_types', methods: ['GET'])]
    public function getStatusTypes(Request $request, Keycloak $auth, SerializerInterface $serializer): JsonResponse
    {
        if ($auth->isGuest()) {
            return new JsonResponse(['message' => 'Unauthorized'], status: 401);
        }
        $statusTypes = \DB::query("SELECT * from status_type");
        $content = $serializer->serialize($statusTypes, 'json');
        return new JsonResponse($content, json: true);
    }
}