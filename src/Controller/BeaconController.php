<?php

namespace App\Controller;

use App\Service\Beacon\BeaconConfig;
use App\Service\Beacon\BeaconException;
use App\Service\Beacon\BeaconService;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * GA4GH Beacon v2 of the portal (discovery of the published datasets and of their studies as cohorts). Open to anyone: it only
 * tells what the catalogue of published datasets already shows. See docs/beacon.md.
 */
#[Route('/api/beacon')]
final class BeaconController extends AbstractController
{
    public function __construct(private BeaconService $beacon, private BeaconConfig $config)
    {
    }

    #[Route('', name: 'beacon_root', methods: ['GET'])]
    #[Route('/info', name: 'beacon_info', methods: ['GET'])]
    #[OA\Get(path: '/api/beacon/info', summary: 'Information about the Beacon and its organisation', tags: ['Beacon'], responses: [new OA\Response(response: 200, description: 'Beacon info')])]
    public function info(): JsonResponse
    {
        return $this->answer(fn () => $this->beacon->info());
    }

    #[Route('/service-info', name: 'beacon_service_info', methods: ['GET'])]
    #[OA\Get(path: '/api/beacon/service-info', summary: 'GA4GH service-info of the Beacon', tags: ['Beacon'], responses: [new OA\Response(response: 200, description: 'GA4GH service-info')])]
    public function serviceInfo(): JsonResponse
    {
        return $this->answer(fn () => $this->beacon->serviceInfo());
    }

    #[Route('/configuration', name: 'beacon_configuration', methods: ['GET'])]
    #[OA\Get(path: '/api/beacon/configuration', summary: 'Configuration of the Beacon', tags: ['Beacon'], responses: [new OA\Response(response: 200, description: 'Beacon configuration')])]
    public function configuration(): JsonResponse
    {
        return $this->answer(fn () => $this->beacon->configuration());
    }

    #[Route('/map', name: 'beacon_map', methods: ['GET'])]
    #[OA\Get(path: '/api/beacon/map', summary: 'Map of the endpoints of the Beacon', tags: ['Beacon'], responses: [new OA\Response(response: 200, description: 'Beacon map')])]
    public function map(Request $request): JsonResponse
    {
        return $this->answer(fn () => $this->beacon->map($this->config->baseUrl($request->getSchemeAndHttpHost())));
    }

    #[Route('/entry_types', name: 'beacon_entry_types', methods: ['GET'])]
    #[OA\Get(path: '/api/beacon/entry_types', summary: 'Entry types of the Beacon: cohorts and datasets', tags: ['Beacon'], responses: [new OA\Response(response: 200, description: 'Entry types')])]
    public function entryTypes(): JsonResponse
    {
        return $this->answer(fn () => $this->beacon->entryTypes());
    }

    #[Route('/filtering_terms', name: 'beacon_filtering_terms', methods: ['GET'])]
    #[OA\Get(
        path: '/api/beacon/filtering_terms',
        summary: 'Filtering terms accepted by the Beacon',
        tags: ['Beacon'],
        parameters: [
            new OA\Parameter(name: 'skip', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 0)),
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 0)),
        ],
        responses: [new OA\Response(response: 200, description: 'Filtering terms')]
    )]
    public function filteringTerms(Request $request): JsonResponse
    {
        return $this->answer(fn () => $this->beacon->filteringTerms($request->query->all()));
    }

    #[Route('/datasets', name: 'beacon_datasets', methods: ['GET'], defaults: ['segment' => 'datasets'])]
    #[Route('/cohorts', name: 'beacon_cohorts', methods: ['GET'], defaults: ['segment' => 'cohorts'])]
    #[OA\Get(
        path: '/api/beacon/datasets',
        summary: 'Published datasets (and /api/beacon/cohorts: the studies that hold them)',
        tags: ['Beacon'],
        parameters: [
            new OA\Parameter(name: 'filters', in: 'query', required: false, description: 'comma separated filters, see filtering_terms', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'skip', in: 'query', required: false, description: 'pages to skip', schema: new OA\Schema(type: 'integer', minimum: 0)),
            new OA\Parameter(name: 'limit', in: 'query', required: false, description: 'page size (default 10, 0 = all)', schema: new OA\Schema(type: 'integer', minimum: 0)),
            new OA\Parameter(name: 'requestedGranularity', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['boolean', 'count', 'record'])),
        ],
        responses: [new OA\Response(response: 200, description: 'Collections')]
    )]
    public function collection(Request $request, string $segment): JsonResponse
    {
        return $this->answer(fn () => $this->beacon->collection($segment, $request->query->all()));
    }

    #[Route('/datasets/{id}', name: 'beacon_dataset', methods: ['GET'], requirements: ['id' => '[^/]+'], defaults: ['segment' => 'datasets'])]
    #[Route('/cohorts/{id}', name: 'beacon_cohort', methods: ['GET'], requirements: ['id' => '[^/]+'], defaults: ['segment' => 'cohorts'])]
    #[OA\Get(
        path: '/api/beacon/datasets/{id}',
        summary: 'One published dataset (and /api/beacon/cohorts/{id}: one study)',
        tags: ['Beacon'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [new OA\Response(response: 200, description: 'Result set, empty when there is no such entry')]
    )]
    public function entry(Request $request, string $segment, string $id): JsonResponse
    {
        return $this->answer(fn () => $this->beacon->entry($segment, $id, $request->query->all()));
    }

    /** The answer, or the error response of the Beacon with the matching HTTP status. */
    private function answer(callable $build): JsonResponse
    {
        try {
            $response = new JsonResponse($build());
            $response->setPublic();
            $response->setMaxAge(60);
        } catch (BeaconException $e) {
            // an error is not kept in caches
            $response = new JsonResponse($this->beacon->error($e->getCode(), $e->getMessage()), $e->getCode());
        }
        // readable accents and slashes; browsers of other sites are allowed by config/packages/nelmio_cors.yaml
        $response->setEncodingOptions(JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);

        return $response;
    }
}
