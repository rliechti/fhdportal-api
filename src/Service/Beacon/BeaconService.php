<?php

namespace App\Service\Beacon;

/**
 * The responses of the Beacon (GA4GH Beacon v2, discovery only): the framework endpoints (info, service-info, configuration, map,
 * entry_types, filtering_terms) and the two collections that it holds, the published datasets and the studies as cohorts.
 *
 * Shapes follow the JSON schemas of the specification (framework and default model, https://github.com/ga4gh-beacon/beacon-v2).
 * The Beacon has no records below the collections (no individuals, biosamples or variants): the data of the samples stay in FEGA.
 */
class BeaconService
{
    public const DEFAULT_LIMIT = 10;
    public const MAX_LIMIT = 1000;
    private const GRANULARITIES = ['boolean', 'count', 'record'];

    /** entry type => [name, endpoint, ontology term of the type, default schema id, description] */
    private const ENTRY_TYPES = [
        'dataset' => [
            'name' => 'Dataset',
            'endpoint' => '/datasets',
            'term' => ['id' => 'NCIT:C47824', 'label' => 'Data set'],
            'schema' => 'ga4gh-beacon-dataset-v2.0.0',
            'schemaName' => 'Default schema for datasets',
            'schemaFile' => '/models/json/beacon-v2-default-model/datasets/defaultSchema.json',
            'description' => 'A published dataset of the Swiss FEGA Node: the research data and the metadata of a study that the help desk released.',
        ],
        'cohort' => [
            'name' => 'Cohort',
            'endpoint' => '/cohorts',
            'term' => ['id' => 'NCIT:C61512', 'label' => 'Cohort'],
            'schema' => 'ga4gh-beacon-cohort-v2.0.0',
            'schemaName' => 'Default schema for cohorts',
            'schemaFile' => '/models/json/beacon-v2-default-model/cohorts/defaultSchema.json',
            'description' => 'A study of the Swiss FEGA Node that has at least one published dataset.',
        ],
    ];
    /** the URL segment of a collection => its entry type */
    private const COLLECTIONS = ['datasets' => 'dataset', 'cohorts' => 'cohort'];

    public function __construct(private BeaconConfig $config, private BeaconRepository $repository)
    {
    }

    // ---- framework endpoints -----------------------------------------------------------------------------------------------

    /** GET / and /info */
    public function info(): array
    {
        $model = $this->repository->model();
        $now = gmdate('Y-m-d\TH:i:s\Z');

        return [
            'meta' => $this->informationalMeta('beacon-info-v2.0.0'),
            'response' => array_filter([
                'id' => $this->config->id(),
                'name' => $this->config->name(),
                'apiVersion' => BeaconConfig::API_VERSION,
                'environment' => $this->config->environment(),
                'organization' => $this->config->organization(),
                'description' => $this->config->description(),
                'version' => BeaconConfig::VERSION,
                'welcomeUrl' => $this->config->portalUrl() . '/',
                'alternativeUrl' => $this->config->portalUrl() . '/datasets',
                'updateDateTime' => $model['updated'] ?? $now,
                'info' => ['numberOfDatasets' => count($model['datasets']), 'numberOfCohorts' => count($model['cohorts'])],
            ], fn ($v) => $v !== null && $v !== '/'),
        ];
    }

    /** GET /service-info: the GA4GH service-info object itself, without the Beacon envelope. */
    public function serviceInfo(): array
    {
        $organization = $this->config->organization();

        return [
            'id' => $this->config->id(),
            'name' => $this->config->name(),
            'type' => ['group' => 'org.ga4gh', 'artifact' => 'beacon', 'version' => BeaconConfig::API_VERSION],
            'description' => $this->config->description(),
            'organization' => ['name' => $organization['name'], 'url' => $organization['welcomeUrl']],
            'contactUrl' => $organization['contactUrl'],
            'documentationUrl' => $this->config->portalUrl() . '/',
            'environment' => $this->config->environment(),
            'version' => BeaconConfig::VERSION,
        ];
    }

    /** GET /configuration */
    public function configuration(): array
    {
        return [
            'meta' => $this->informationalMeta('beacon-configuration-v2.0.0'),
            'response' => [
                '$schema' => BeaconConfig::SCHEMA_BASE . '/framework/json/configuration/beaconConfigurationSchema.json',
                'maturityAttributes' => ['productionStatus' => $this->config->productionStatus()],
                'securityAttributes' => ['defaultGranularity' => 'record', 'securityLevels' => ['PUBLIC']],
                'entryTypes' => $this->entryTypeDefinitions(),
            ],
        ];
    }

    /** GET /entry_types */
    public function entryTypes(): array
    {
        return [
            'meta' => $this->informationalMeta('beacon-entry-types-v2.0.0'),
            'response' => [
                '$schema' => BeaconConfig::SCHEMA_BASE . '/framework/json/configuration/entryTypesSchema.json',
                'entryTypes' => $this->entryTypeDefinitions(),
            ],
        ];
    }

    /** GET /map */
    public function map(string $base): array
    {
        $sets = [];
        foreach (self::COLLECTIONS as $segment => $entryType) {
            $sets[$entryType] = [
                'entryType' => $entryType,
                'rootUrl' => $base . '/' . $segment,
                'singleEntryUrl' => $base . '/' . $segment . '/{id}',
                'filteringTermsUrl' => $base . '/filtering_terms',
            ];
        }

        return [
            'meta' => $this->informationalMeta('beacon-map-v2.0.0'),
            'response' => [
                '$schema' => BeaconConfig::SCHEMA_BASE . '/framework/json/configuration/beaconMapSchema.json',
                'endpointSets' => $sets,
            ],
        ];
    }

    /**
     * GET /filtering_terms
     *
     * @param array<string, mixed> $query skip, limit
     */
    public function filteringTerms(array $query = []): array
    {
        [$skip, $limit] = $this->pagination($query);
        $filtering = $this->repository->model()['filtering'];
        $terms = $filtering['filteringTerms'];
        $page = $this->page($terms, $skip, $limit);
        $used = array_unique(array_filter(array_map(fn ($t) => BeaconFilters::ONTOLOGY_TERMS[$t['id']]['resource'] ?? null, $page)));

        return [
            'meta' => $this->informationalMeta('beacon-filtering-terms-v2.0.0') + [
                'receivedRequestSummary' => ['apiVersion' => BeaconConfig::API_VERSION, 'pagination' => ['skip' => $skip, 'limit' => $limit]],
            ],
            'response' => [
                'filteringTerms' => $page,
                'resources' => array_values(array_filter($filtering['resources'], fn ($r) => in_array($r['id'], $used, true))),
            ],
            'responseSummary' => ['exists' => $terms !== [], 'numTotalResults' => count($terms)],
        ];
    }

    // ---- collections ---------------------------------------------------------------------------------------------------------

    /**
     * GET /datasets and /cohorts
     *
     * @param string $segment datasets or cohorts
     * @param array<string, mixed> $query skip, limit, filters, requestedGranularity
     */
    public function collection(string $segment, array $query = []): array
    {
        $entryType = self::COLLECTIONS[$segment] ?? throw new BeaconException("Unknown collection '$segment'", 404);
        [$skip, $limit] = $this->pagination($query);
        $granularity = $this->granularity($query);
        $filters = BeaconFilters::parse($query['filters'] ?? null);

        $entries = array_filter($this->repository->model()[$segment], fn ($entry) => BeaconFilters::matches($entry['facets'], $filters));
        ksort($entries);
        $total = count($entries);

        $body = [
            'meta' => $this->dataMeta($entryType, $granularity, $skip, $limit, $filters),
            'responseSummary' => $granularity === 'boolean' ? ['exists' => $total > 0] : ['exists' => $total > 0, 'numTotalResults' => $total],
        ];
        if ($granularity === 'record') {
            $body['response'] = ['collections' => array_column($this->page(array_values($entries), $skip, $limit), 'record')];
        }

        return $body;
    }

    /**
     * GET /datasets/{id} and /cohorts/{id}: a result set with the entry, or an empty one when there is no such entry.
     *
     * @param array<string, mixed> $query requestedGranularity
     */
    public function entry(string $segment, string $id, array $query = []): array
    {
        $entryType = self::COLLECTIONS[$segment] ?? throw new BeaconException("Unknown collection '$segment'", 404);
        $granularity = $this->granularity($query);
        $entry = $this->repository->model()[$segment][$id] ?? null;

        $body = [
            'meta' => $this->dataMeta($entryType, $granularity, 0, 1, []),
            'responseSummary' => $granularity === 'boolean' ? ['exists' => $entry !== null] : ['exists' => $entry !== null, 'numTotalResults' => $entry ? 1 : 0],
        ];
        if ($granularity === 'record') {
            $body['response'] = ['resultSets' => $entry ? [[
                'id' => $id,
                'setType' => $entryType,
                'exists' => true,
                'resultsCount' => 1,
                'results' => [$entry['record']],
            ]] : []];
        }

        return $body;
    }

    /** The error response of the Beacon. */
    public function error(int $code, string $message): array
    {
        return [
            // the schema of the response meta requires a granularity and a summary of the request, whatever the answer holds
            'meta' => ['beaconId' => $this->config->id(), 'apiVersion' => BeaconConfig::API_VERSION, 'returnedGranularity' => 'boolean', 'receivedRequestSummary' => ['apiVersion' => BeaconConfig::API_VERSION, 'requestedSchemas' => [], 'requestedGranularity' => 'boolean', 'pagination' => ['skip' => 0, 'limit' => self::DEFAULT_LIMIT]], 'returnedSchemas' => []],
            'error' => ['errorCode' => $code, 'errorMessage' => $message],
        ];
    }

    // ---- helpers -------------------------------------------------------------------------------------------------------------

    private function entryTypeDefinitions(): array
    {
        $definitions = [];
        foreach (self::ENTRY_TYPES as $id => $type) {
            $definitions[$id] = [
                'id' => $id,
                'name' => $type['name'],
                'ontologyTermForThisType' => $type['term'],
                'partOfSpecification' => BeaconConfig::SPECIFICATION,
                'description' => $type['description'],
                'defaultSchema' => [
                    'id' => $type['schema'],
                    'name' => $type['schemaName'],
                    'referenceToSchemaDefinition' => BeaconConfig::SCHEMA_BASE . $type['schemaFile'],
                    'schemaVersion' => BeaconConfig::API_VERSION,
                ],
                'additionallySupportedSchemas' => [],
                'endpoint' => $type['endpoint'],
                'nonFilteredQueriesAllowed' => true,
            ];
        }

        return $definitions;
    }

    private function informationalMeta(string $schema): array
    {
        return [
            'beaconId' => $this->config->id(),
            'apiVersion' => BeaconConfig::API_VERSION,
            'returnedSchemas' => [['entityType' => 'info', 'schema' => $schema]],
        ];
    }

    /** @param array<int, array{id: string}> $filters */
    private function dataMeta(string $entryType, string $granularity, int $skip, int $limit, array $filters): array
    {
        $schema = ['entityType' => $entryType, 'schema' => self::ENTRY_TYPES[$entryType]['schema']];

        return [
            'beaconId' => $this->config->id(),
            'apiVersion' => BeaconConfig::API_VERSION,
            'returnedGranularity' => $granularity,
            'receivedRequestSummary' => array_filter([
                'apiVersion' => BeaconConfig::API_VERSION,
                'requestedSchemas' => [$schema],
                'filters' => $filters ? array_map(fn ($f) => $f['id'] . (isset(BeaconFilters::ALPHANUMERIC_TERMS[$f['id']]) ? ':' . ($f['negate'] ? '!' : '') . $f['value'] : ''), $filters) : null,
                'requestedGranularity' => $granularity,
                'pagination' => ['skip' => $skip, 'limit' => $limit],
            ], fn ($v) => $v !== null),
            'returnedSchemas' => [$schema],
        ];
    }

    /**
     * skip is a number of pages, limit the size of a page (0 = all, up to the maximum), as the specification says.
     *
     * @return array{0: int, 1: int}
     */
    private function pagination(array $query): array
    {
        $skip = $query['skip'] ?? 0;
        $limit = $query['limit'] ?? self::DEFAULT_LIMIT;
        foreach (['skip' => $skip, 'limit' => $limit] as $name => $value) {
            if (!is_numeric($value) || (int) $value < 0 || (string) (int) $value !== (string) $value) {
                throw new BeaconException("'$name' must be a whole number, 0 or more", 400);
            }
        }
        $limit = (int) $limit;

        return [(int) $skip, $limit === 0 ? self::MAX_LIMIT : min($limit, self::MAX_LIMIT)];
    }

    private function page(array $items, int $skip, int $limit): array
    {
        return array_slice($items, $skip * $limit, $limit);
    }

    private function granularity(array $query): string
    {
        $granularity = $query['requestedGranularity'] ?? 'record';
        if (!in_array($granularity, self::GRANULARITIES, true)) {
            throw new BeaconException("'requestedGranularity' must be boolean, count or record", 400);
        }

        return $granularity;
    }
}
