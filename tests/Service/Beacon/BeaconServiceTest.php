<?php

namespace App\Tests\Service\Beacon;

use App\Service\Beacon\BeaconConfig;
use App\Service\Beacon\BeaconException;
use App\Service\Beacon\BeaconFilters;
use App\Service\Beacon\BeaconRepository;
use App\Service\Beacon\BeaconService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BeaconServiceTest extends TestCase
{
    private array $env;

    protected function setUp(): void
    {
        $this->env = $_ENV;
        foreach (['BEACON_ID', 'BEACON_NAME', 'BEACON_DESCRIPTION', 'BEACON_ENVIRONMENT', 'BEACON_PORTAL_URL', 'BEACON_BASE_URL'] as $name) {
            unset($_ENV[$name], $_SERVER[$name]);
        }
        $_ENV['FEGA_API_URL'] = 'https://fhd-portal-dev.fega.swiss/api/api/';
    }

    protected function tearDown(): void
    {
        $_ENV = $this->env;
    }

    /** twelve datasets D01..D12: the even ones are WXS, the odd ones RNA-Seq */
    private function service(int $datasets = 12): BeaconService
    {
        $model = ['datasets' => [], 'cohorts' => [], 'filtering' => ['filteringTerms' => [], 'resources' => []], 'updated' => '2026-10-08T09:00:00Z'];
        $allFacets = [];
        for ($i = 1; $i <= $datasets; $i++) {
            $id = sprintf('D%02d', $i);
            $facets = ['libraryStrategy' => [$i % 2 ? 'RNA-Seq' : 'WXS'], 'sex' => ['female']];
            $model['datasets'][$id] = ['record' => ['id' => $id, 'name' => "Dataset $id"], 'facets' => $facets];
            $allFacets[] = $facets;
        }
        $model['cohorts']['S1'] = ['record' => ['id' => 'S1', 'name' => 'Study 1', 'cohortType' => 'study-defined', 'cohortSize' => 4], 'facets' => ['sex' => ['female']]];
        $allFacets[] = $model['cohorts']['S1']['facets'];
        $model['filtering'] = BeaconFilters::filteringTerms($allFacets);

        $repository = $this->createStub(BeaconRepository::class);
        $repository->method('model')->willReturn($model);

        return new BeaconService(new BeaconConfig(), $repository);
    }

    public function testInfo(): void
    {
        $info = $this->service()->info();

        $this->assertSame('swiss.fega.fhd-portal-dev', $info['meta']['beaconId']);
        $this->assertSame('v2.0.0', $info['meta']['apiVersion']);
        $this->assertSame([['entityType' => 'info', 'schema' => 'beacon-info-v2.0.0']], $info['meta']['returnedSchemas']);
        $response = $info['response'];
        // required by the schema: id, name, apiVersion, environment, organization (id, name)
        $this->assertSame('swiss.fega.fhd-portal-dev', $response['id']);
        $this->assertNotSame('', $response['name']);
        $this->assertSame('v2.0.0', $response['apiVersion']);
        $this->assertSame('prod', $response['environment']);
        $this->assertSame('SIB', $response['organization']['id']);
        $this->assertSame('SIB Swiss Institute of Bioinformatics', $response['organization']['name']);
        $this->assertSame('2026-10-08T09:00:00Z', $response['updateDateTime']);
        $this->assertSame(['numberOfDatasets' => 12, 'numberOfCohorts' => 1], $response['info']);
        $this->assertStringNotContainsString('@', json_encode($info), 'no e-mail address in a public answer');
    }

    public function testServiceInfoIsTheGa4ghObjectWithoutEnvelope(): void
    {
        $info = $this->service()->serviceInfo();

        $this->assertArrayNotHasKey('meta', $info);
        $this->assertSame(['group' => 'org.ga4gh', 'artifact' => 'beacon', 'version' => 'v2.0.0'], $info['type']);
        foreach (['id', 'name', 'type', 'organization', 'version'] as $required) {
            $this->assertArrayHasKey($required, $info);
        }
        $this->assertSame(['name', 'url'], array_keys($info['organization']));
    }

    public function testEntryTypesAreDatasetsAndCohorts(): void
    {
        $types = $this->service()->entryTypes()['response']['entryTypes'];

        $this->assertSame(['dataset', 'cohort'], array_keys($types));
        $this->assertSame('/datasets', $types['dataset']['endpoint']);
        $this->assertSame('/cohorts', $types['cohort']['endpoint']);
        $this->assertSame('NCIT:C47824', $types['dataset']['ontologyTermForThisType']['id']);
        $this->assertSame('NCIT:C61512', $types['cohort']['ontologyTermForThisType']['id']);
        $this->assertSame('Beacon v2.0.0', $types['dataset']['partOfSpecification']);
        $this->assertSame('ga4gh-beacon-cohort-v2.0.0', $types['cohort']['defaultSchema']['id']);
    }

    public function testConfigurationHasTheSameEntryTypesAndNoSecrets(): void
    {
        $service = $this->service();
        $configuration = $service->configuration()['response'];

        $this->assertSame($service->entryTypes()['response']['entryTypes'], $configuration['entryTypes']);
        $this->assertSame('PROD', $configuration['maturityAttributes']['productionStatus']);
        $this->assertSame(['defaultGranularity' => 'record', 'securityLevels' => ['PUBLIC']], $configuration['securityAttributes']);
        $this->assertArrayHasKey('$schema', $configuration);
    }

    public function testMapPointsAtTheEndpointsOfTheBeacon(): void
    {
        $sets = $this->service()->map('https://fhd-portal-dev.fega.swiss/api/api/beacon')['response']['endpointSets'];

        $this->assertSame('dataset', $sets['dataset']['entryType']);
        $this->assertSame('https://fhd-portal-dev.fega.swiss/api/api/beacon/datasets', $sets['dataset']['rootUrl']);
        $this->assertSame('https://fhd-portal-dev.fega.swiss/api/api/beacon/cohorts/{id}', $sets['cohort']['singleEntryUrl']);
        $this->assertSame('https://fhd-portal-dev.fega.swiss/api/api/beacon/filtering_terms', $sets['cohort']['filteringTermsUrl']);
    }

    public function testFilteringTermsArePagedAndNameTheirResources(): void
    {
        $service = $this->service();
        $all = $service->filteringTerms(['limit' => '0']);
        $first = $service->filteringTerms(['limit' => '1', 'skip' => '0']);
        $second = $service->filteringTerms(['limit' => '1', 'skip' => '1']);

        $this->assertSame(['NCIT:C16576', 'libraryStrategy'], array_column($all['response']['filteringTerms'], 'id'));
        $this->assertSame(['NCIT:C16576'], array_column($first['response']['filteringTerms'], 'id'));
        $this->assertSame(['libraryStrategy'], array_column($second['response']['filteringTerms'], 'id'));
        $this->assertSame(['ncit'], array_column($first['response']['resources'], 'id'));
        $this->assertSame([], $second['response']['resources'], 'the resources of the terms that are shown');
        $this->assertSame(2, $first['responseSummary']['numTotalResults']);
        $this->assertSame(['skip' => 1, 'limit' => 1], $second['meta']['receivedRequestSummary']['pagination']);
    }

    public function testACollectionIsPagedWithPagesAsTheSpecificationSays(): void
    {
        $service = $this->service();

        $page0 = $service->collection('datasets');
        $page1 = $service->collection('datasets', ['skip' => '1']);
        $small = $service->collection('datasets', ['skip' => '2', 'limit' => '4']);

        $this->assertSame(range(1, 10), array_map(fn ($c) => (int) substr($c['id'], 1), $page0['response']['collections']), 'default: ten per page');
        $this->assertSame([11, 12], array_map(fn ($c) => (int) substr($c['id'], 1), $page1['response']['collections']));
        $this->assertSame([9, 10, 11, 12], array_map(fn ($c) => (int) substr($c['id'], 1), $small['response']['collections']), 'skip 2 pages of 4');
        $this->assertSame(12, $page0['responseSummary']['numTotalResults']);
        $this->assertSame(['skip' => 2, 'limit' => 4], $small['meta']['receivedRequestSummary']['pagination']);
        $this->assertSame('record', $page0['meta']['returnedGranularity']);
        $this->assertSame([['entityType' => 'dataset', 'schema' => 'ga4gh-beacon-dataset-v2.0.0']], $page0['meta']['returnedSchemas']);
        $this->assertTrue($page0['responseSummary']['exists']);
    }

    public function testLimitZeroMeansAll(): void
    {
        $this->assertCount(12, $this->service()->collection('datasets', ['limit' => '0'])['response']['collections']);
    }

    public function testFiltersNarrowTheCollection(): void
    {
        $service = $this->service();

        $wxs = $service->collection('datasets', ['filters' => 'libraryStrategy:WXS', 'limit' => '0']);
        $none = $service->collection('datasets', ['filters' => 'libraryStrategy:WGS']);

        $this->assertSame(6, $wxs['responseSummary']['numTotalResults']);
        $this->assertSame(['libraryStrategy:WXS'], $wxs['meta']['receivedRequestSummary']['filters']);
        $this->assertFalse($none['responseSummary']['exists']);
        $this->assertSame([], $none['response']['collections']);
    }

    public function testCohortsAreFilteredToo(): void
    {
        $service = $this->service();

        $this->assertSame(1, $service->collection('cohorts', ['filters' => 'NCIT:C16576'])['responseSummary']['numTotalResults']);
        $this->assertSame(0, $service->collection('cohorts', ['filters' => 'NCIT:C20197'])['responseSummary']['numTotalResults']);
    }

    public function testGranularity(): void
    {
        $service = $this->service();

        $boolean = $service->collection('datasets', ['requestedGranularity' => 'boolean']);
        $count = $service->collection('datasets', ['requestedGranularity' => 'count']);

        $this->assertSame(['exists' => true], $boolean['responseSummary']);
        $this->assertArrayNotHasKey('response', $boolean);
        $this->assertSame('boolean', $boolean['meta']['returnedGranularity']);
        $this->assertSame(['exists' => true, 'numTotalResults' => 12], $count['responseSummary']);
        $this->assertArrayNotHasKey('response', $count);
    }

    public function testASingleEntryIsAResultSet(): void
    {
        $service = $this->service();

        $found = $service->entry('datasets', 'D03');
        $missing = $service->entry('datasets', 'D99');

        $this->assertSame(['exists' => true, 'numTotalResults' => 1], $found['responseSummary']);
        $set = $found['response']['resultSets'][0];
        $this->assertSame(['id' => 'D03', 'setType' => 'dataset', 'exists' => true, 'resultsCount' => 1], array_diff_key($set, ['results' => 1]));
        $this->assertSame([['id' => 'D03', 'name' => 'Dataset D03']], $set['results']);
        $this->assertSame(['exists' => false, 'numTotalResults' => 0], $missing['responseSummary']);
        $this->assertSame([], $missing['response']['resultSets']);
        $this->assertSame('cohort', $service->entry('cohorts', 'S1')['response']['resultSets'][0]['setType']);
    }

    public function testNoPublishedDatasetNoEntries(): void
    {
        $collection = $this->service(0)->collection('datasets');

        $this->assertFalse($collection['responseSummary']['exists']);
        $this->assertSame([], $collection['response']['collections']);
    }

    #[DataProvider('badQueries')]
    public function testBadQueriesAreRefused(string $segment, array $query): void
    {
        $this->expectException(BeaconException::class);
        $this->expectExceptionCode(400);
        $this->service()->collection($segment, $query);
    }

    public static function badQueries(): array
    {
        return [
            'negative skip' => ['datasets', ['skip' => '-1']],
            'skip is not a number' => ['datasets', ['skip' => 'abc']],
            'fractional limit' => ['datasets', ['limit' => '1.5']],
            'unknown granularity' => ['datasets', ['requestedGranularity' => 'everything']],
            'unknown filter' => ['datasets', ['filters' => 'HP:0001631']],
            'the filters of a cohort are checked too' => ['cohorts', ['filters' => 'nonsense']],
        ];
    }

    public function testAnUnknownCollectionIsNotFound(): void
    {
        $this->expectException(BeaconException::class);
        $this->expectExceptionCode(404);
        $this->service()->collection('individuals');
    }

    public function testTheLimitHasAMaximum(): void
    {
        $meta = $this->service()->collection('datasets', ['limit' => '100000'])['meta'];

        $this->assertSame(BeaconService::MAX_LIMIT, $meta['receivedRequestSummary']['pagination']['limit']);
    }

    public function testErrorResponse(): void
    {
        $error = $this->service()->error(400, 'Unknown filter');

        $this->assertSame(['errorCode' => 400, 'errorMessage' => 'Unknown filter'], $error['error']);
        $this->assertSame('swiss.fega.fhd-portal-dev', $error['meta']['beaconId']);
        $this->assertSame('boolean', $error['meta']['returnedGranularity'], 'required by the schema of the response meta');
    }
}
