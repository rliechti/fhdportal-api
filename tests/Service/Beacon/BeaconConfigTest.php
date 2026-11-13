<?php

namespace App\Tests\Service\Beacon;

use App\Service\Beacon\BeaconConfig;
use PHPUnit\Framework\TestCase;

class BeaconConfigTest extends TestCase
{
    private array $env;

    protected function setUp(): void
    {
        $this->env = $_ENV;
        foreach (['BEACON_ID', 'BEACON_NAME', 'BEACON_DESCRIPTION', 'BEACON_ENVIRONMENT', 'BEACON_PORTAL_URL', 'BEACON_BASE_URL'] as $name) {
            unset($_ENV[$name], $_SERVER[$name]);
        }
    }

    protected function tearDown(): void
    {
        $_ENV = $this->env;
    }

    public function testEverythingIsDerivedFromTheAddressOfTheApi(): void
    {
        $_ENV['FEGA_API_URL'] = 'https://fhd-portal-dev.fega.swiss/api/api/';
        $config = new BeaconConfig();

        $this->assertSame('swiss.fega.fhd-portal-dev', $config->id());
        $this->assertSame('https://fhd-portal-dev.fega.swiss', $config->portalUrl());
        $this->assertSame('https://fhd-portal-dev.fega.swiss/api/api/beacon', $config->baseUrl());
        $this->assertSame('https://fhd-portal-dev.fega.swiss/contacts', $config->organization()['contactUrl']);
    }

    public function testTheStagingAddressHasASingleApiSegment(): void
    {
        $_ENV['FEGA_API_URL'] = 'https://fhd-portal-staging.fega.swiss/api/';
        $config = new BeaconConfig();

        $this->assertSame('https://fhd-portal-staging.fega.swiss', $config->portalUrl());
        $this->assertSame('https://fhd-portal-staging.fega.swiss/api/beacon', $config->baseUrl());
    }

    public function testTheEnvironmentOverridesTheDefaults(): void
    {
        $_ENV['FEGA_API_URL'] = 'https://x.example/api/';
        $_ENV['BEACON_ID'] = 'org.example.beacon';
        $_ENV['BEACON_ENVIRONMENT'] = 'test';
        $_ENV['BEACON_PORTAL_URL'] = 'https://portal.example/';
        $_ENV['BEACON_BASE_URL'] = 'https://beacon.example/v2/';
        $config = new BeaconConfig();

        $this->assertSame('org.example.beacon', $config->id());
        $this->assertSame('test', $config->environment());
        $this->assertSame('TEST', $config->productionStatus());
        $this->assertSame('https://portal.example', $config->portalUrl());
        $this->assertSame('https://beacon.example/v2', $config->baseUrl());
    }

    public function testAnUnknownEnvironmentIsProduction(): void
    {
        $_ENV['BEACON_ENVIRONMENT'] = 'staging';

        $this->assertSame('prod', (new BeaconConfig())->environment());
    }

    public function testWithoutSettingsTheBeaconUsesTheAddressOfTheRequest(): void
    {
        unset($_ENV['FEGA_API_URL'], $_SERVER['FEGA_API_URL']);
        $config = new BeaconConfig();

        $this->assertSame('swiss.fega.fhd-portal', $config->id());
        $this->assertSame('https://host.example/api/beacon', $config->baseUrl('https://host.example'));
    }
}
