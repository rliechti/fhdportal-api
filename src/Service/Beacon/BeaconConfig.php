<?php

namespace App\Service\Beacon;

/**
 * Identity and addresses of the Beacon of this portal. Everything has a default that is derived from FEGA_API_URL (the address
 * under which the API is reachable, as set for the image); each value can be set in the environment:
 *
 *   BEACON_ID            reverse domain name, default: the host of FEGA_API_URL reversed (swiss.fega.fhd-portal-dev)
 *   BEACON_NAME          name of the service
 *   BEACON_DESCRIPTION   description of the service
 *   BEACON_ENVIRONMENT   dev, test or prod (default prod)
 *   BEACON_PORTAL_URL    the portal (default: FEGA_API_URL without its /api part)
 *   BEACON_BASE_URL      the address of this Beacon (default: FEGA_API_URL + beacon)
 */
class BeaconConfig
{
    /** Version of the Beacon specification that the responses follow (the schemas of its default model carry v2.0.0). */
    public const API_VERSION = 'v2.0.0';
    public const SPECIFICATION = 'Beacon v2.0.0';
    /** Version of this implementation. */
    public const VERSION = '0.1.0';
    public const SCHEMA_BASE = 'https://raw.githubusercontent.com/ga4gh-beacon/beacon-v2/main';

    private function env(string $name, ?string $default = null): ?string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? null;

        return ($value === null || $value === '') ? $default : (string) $value;
    }

    public function id(): string
    {
        if ($id = $this->env('BEACON_ID')) {
            return $id;
        }
        $host = parse_url((string) $this->env('FEGA_API_URL', ''), PHP_URL_HOST);

        return $host ? implode('.', array_reverse(explode('.', strtolower($host)))) : 'swiss.fega.fhd-portal';
    }

    public function name(): string
    {
        return $this->env('BEACON_NAME', 'Swiss FEGA Node (FHDportal) Beacon');
    }

    public function description(): string
    {
        return $this->env(
            'BEACON_DESCRIPTION',
            'Discovery of the published datasets and studies (cohorts) of the Swiss Federated EGA Node. The data of the samples stay '
            . 'encrypted in the Swiss FEGA Node: access is requested through the portal.'
        );
    }

    /** dev, test or prod */
    public function environment(): string
    {
        $environment = strtolower((string) $this->env('BEACON_ENVIRONMENT', 'prod'));

        return in_array($environment, ['dev', 'test', 'prod'], true) ? $environment : 'prod';
    }

    /** DEV, TEST or PROD, as the configuration of a Beacon says it. */
    public function productionStatus(): string
    {
        return strtoupper($this->environment());
    }

    /** The portal, without a trailing slash. */
    public function portalUrl(): string
    {
        if ($url = $this->env('BEACON_PORTAL_URL')) {
            return rtrim($url, '/');
        }
        $api = rtrim((string) $this->env('FEGA_API_URL', ''), '/');

        return (string) preg_replace('#/api(/api)?$#', '', $api);
    }

    /** Address of this Beacon, without a trailing slash (https://portal/api/beacon). */
    public function baseUrl(?string $requestBase = null): string
    {
        if ($url = $this->env('BEACON_BASE_URL')) {
            return rtrim($url, '/');
        }
        if ($api = $this->env('FEGA_API_URL')) {
            return rtrim($api, '/') . '/beacon';
        }

        return rtrim((string) $requestBase, '/') . '/api/beacon';
    }

    /** @return array{id: string, name: string, description: string, address: string, welcomeUrl: string, contactUrl: string} */
    public function organization(): array
    {
        return [
            'id' => 'SIB',
            'name' => 'SIB Swiss Institute of Bioinformatics',
            'description' => 'The Swiss FEGA Node is operated and coordinated by SIB.',
            'address' => 'Quartier Sorge - Bâtiment Amphipôle, 1015 Lausanne, Switzerland',
            'welcomeUrl' => 'https://www.sib.swiss/',
            // no e-mail address: the contact form of the portal
            'contactUrl' => $this->portalUrl() . '/contacts',
        ];
    }
}
