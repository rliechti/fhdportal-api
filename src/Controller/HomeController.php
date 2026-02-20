<?php

namespace App\Controller;

use App\Service\Auth\Keycloak;
use App\Service\CvService;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\SerializerInterface;

class HomeController extends AbstractController
{
    public function __construct(private SerializerInterface $serializer) {}

    #[Route('/', name: 'home', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('home.html.twig');
    }

    #[Route('/api/status-types', name: 'get_status_types', methods: ['GET'])]
    #[OA\Get(
        path: '/api/status-types',
        summary: 'Get status types',
        tags: ['Vocabularies'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Successful response',
                content: new OA\JsonContent(
                    type: 'array',
                    items: new OA\Items(type: 'string')
                )
            ),
            new OA\Response(response: 401, description: 'Unauthorized')
        ]
    )]
    public function getStatusTypes(Request $request, Keycloak $auth, CvService $cv): JsonResponse
    {
        if ($auth->isGuest()) {
            return new JsonResponse(['message' => 'Unauthorized'], 401);
        }

        $statusTypes = $cv->getStatusTypes();
        $content = json_encode($statusTypes);

        return new JsonResponse($content, json: true);
    }
}
