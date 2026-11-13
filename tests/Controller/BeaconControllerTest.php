<?php

namespace App\Tests\Controller;

use App\Controller\BeaconController;
use App\Service\Beacon\BeaconConfig;
use App\Service\Beacon\BeaconFilters;
use App\Service\Beacon\BeaconRepository;
use App\Service\Beacon\BeaconService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;

class BeaconControllerTest extends TestCase
{
    private array $env;

    protected function setUp(): void
    {
        $this->env = $_ENV;
        $_ENV['FEGA_API_URL'] = 'https://fhd-portal-dev.fega.swiss/api/api/';
        unset($_ENV['BEACON_BASE_URL'], $_ENV['BEACON_ID']);
    }

    protected function tearDown(): void
    {
        $_ENV = $this->env;
    }

    private function controller(): BeaconController
    {
        $facets = ['libraryStrategy' => ['WXS'], 'sex' => ['female']];
        $repository = $this->createStub(BeaconRepository::class);
        $repository->method('model')->willReturn([
            'datasets' => ['D1' => ['record' => ['id' => 'D1', 'name' => 'Exome', 'description' => 'Zürich – 100 % «ok» / done'], 'facets' => $facets]],
            'cohorts' => ['S1' => ['record' => ['id' => 'S1', 'name' => 'Study', 'cohortType' => 'study-defined'], 'facets' => $facets]],
            'filtering' => BeaconFilters::filteringTerms([$facets]),
            'updated' => null,
        ]);
        $config = new BeaconConfig();
        $controller = new BeaconController(new BeaconService($config, $repository), $config);
        $controller->setContainer(new Container());

        return $controller;
    }

    private function request(array $query = []): Request
    {
        return new Request($query);
    }

    public function testInfoIsOpenJsonAndMayBeCached(): void
    {
        $response = $this->controller()->info();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('application/json', $response->headers->get('Content-Type'));
        $this->assertTrue($response->headers->hasCacheControlDirective('public'));
        $this->assertSame('60', $response->headers->getCacheControlDirective('max-age'));
        $this->assertSame('swiss.fega.fhd-portal-dev', json_decode($response->getContent(), true)['response']['id']);
    }

    public function testTheMapUsesTheAddressOfTheApi(): void
    {
        $json = json_decode($this->controller()->map($this->request())->getContent(), true);

        $this->assertSame('https://fhd-portal-dev.fega.swiss/api/api/beacon/datasets', $json['response']['endpointSets']['dataset']['rootUrl']);
    }

    public function testTheJsonKeepsSlashesAndAccentsReadable(): void
    {
        $content = $this->controller()->collection($this->request(), 'datasets')->getContent();

        $this->assertStringContainsString('Zürich – 100 % «ok» / done', $content);
        $this->assertStringContainsString('"collections":[{', $content);
    }

    public function testEmptyListsAndObjectsStayWhatTheyAre(): void
    {
        $content = $this->controller()->entry($this->request(), 'datasets', 'nope')->getContent();
        $configuration = $this->controller()->configuration()->getContent();

        $this->assertStringContainsString('"resultSets":[]', $content);
        $this->assertStringContainsString('"additionallySupportedSchemas":[]', $configuration);
        $this->assertStringContainsString('"entryTypes":{"dataset":{', $configuration);
    }

    public function testACollectionWithFilters(): void
    {
        $json = json_decode($this->controller()->collection($this->request(['filters' => 'libraryStrategy:WXS']), 'cohorts')->getContent(), true);

        $this->assertSame('S1', $json['response']['collections'][0]['id']);
    }

    public function testABadRequestIsABeaconError(): void
    {
        $response = $this->controller()->collection($this->request(['filters' => 'HP:0001631']), 'datasets');
        $json = json_decode($response->getContent(), true);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(400, $json['error']['errorCode']);
        $this->assertStringContainsString("Unknown filter 'HP:0001631'", $json['error']['errorMessage']);
        $this->assertArrayHasKey('beaconId', $json['meta']);
        $this->assertFalse($response->headers->hasCacheControlDirective('public'), 'an error is not cached');
    }

    public function testAnUnknownCollectionIs404(): void
    {
        $this->assertSame(404, $this->controller()->collection($this->request(), 'individuals')->getStatusCode());
    }

    public function testSkipAndLimitComeFromTheQuery(): void
    {
        $json = json_decode($this->controller()->filteringTerms($this->request(['skip' => '1', 'limit' => '1']))->getContent(), true);

        $this->assertSame(['skip' => 1, 'limit' => 1], $json['meta']['receivedRequestSummary']['pagination']);
        $this->assertSame(['libraryStrategy'], array_column($json['response']['filteringTerms'], 'id'));
    }
}
