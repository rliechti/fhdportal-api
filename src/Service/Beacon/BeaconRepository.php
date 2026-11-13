<?php

namespace App\Service\Beacon;

use MeekroDB;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * What the Beacon shows: the published datasets, and the studies that hold them (the cohorts). Nothing else leaves the portal: no
 * draft, no submitted or approved dataset, no sample or file.
 *
 * The model is built from the database and kept for a few minutes (the catalogue of published datasets changes rarely).
 */
class BeaconRepository
{
    public const CACHE_KEY = 'beacon_model_v1';
    public const CACHE_SECONDS = 300;
    /** the datasets that anyone can see: the published ones */
    public const PUBLIC_STATUS = 'PUB';
    /** the resources of a study that the facets and the counts are taken from */
    public const RELATED_TYPES = ['Sample', 'MolecularExperiment', 'MolecularRun', 'MolecularAnalysis', 'ImageExperiment', 'ImageRun', 'ImageAnalysis'];

    public function __construct(
        private MeekroDB $db,
        private CacheInterface $cache,
        private BeaconConfig $config
    ) {
    }

    /**
     * @return array{datasets: array<string, array>, cohorts: array<string, array>, filtering: array, updated: ?string}
     *         datasets and cohorts by id, each [record, facets]
     */
    public function model(): array
    {
        return $this->cache->get(self::CACHE_KEY, function (ItemInterface $item) {
            $item->expiresAfter(self::CACHE_SECONDS);

            return $this->load();
        });
    }

    private function load(): array
    {
        $datasets = $this->db->query(
            "SELECT public_id, study_id, study_public_id, study_title, title, properties, creation_date, last_update, released_date
             FROM dataset_view WHERE status_type_id = %s ORDER BY public_id",
            self::PUBLIC_STATUS
        );
        if (!$datasets) {
            return self::build([], [], [], $this->config->portalUrl());
        }
        $studyIds = array_values(array_unique(array_column($datasets, 'study_id')));
        $studies = $this->db->query('SELECT id, properties FROM resource WHERE id IN %ls', $studyIds);
        $related = $this->db->query(
            "SELECT rv.range_resource_id AS study_id, rv.domain_type AS type, r.properties
             FROM relationship_view rv JOIN resource r ON r.id = rv.domain_resource_id
             WHERE rv.range_resource_id IN %ls AND rv.range_type = 'Study' AND rv.predicate_name = 'isPartOf' AND rv.is_active = true
               AND r.status_type_id NOT IN ('DEL', 'REJ') AND rv.domain_type IN %ls",
            $studyIds,
            self::RELATED_TYPES
        );

        return self::build($datasets, $studies, $related, $this->config->portalUrl());
    }

    /**
     * @param array<int, array> $datasets rows of dataset_view of the published datasets
     * @param array<int, array> $studies  rows [id, properties] of their studies
     * @param array<int, array> $related  rows [study_id, type, properties] of the samples, experiments, runs and analyses of the studies
     */
    public static function build(array $datasets, array $studies, array $related, string $portalUrl): array
    {
        $studyProperties = [];
        foreach ($studies as $study) {
            $studyProperties[$study['id']] = self::json($study['properties']);
        }

        // by study: the resources, by kind and public id
        $byStudy = [];
        foreach ($related as $row) {
            $kind = self::kind((string) $row['type']);
            if ($kind === null) {
                continue;
            }
            $properties = self::json($row['properties']);
            $publicId = (string) ($properties['public_id'] ?? '');
            // a resource without public id still counts
            $byStudy[$row['study_id']][$kind][$publicId !== '' ? $publicId : '#' . count($byStudy[$row['study_id']][$kind] ?? [])] = $properties;
        }

        $out = ['datasets' => [], 'cohorts' => [], 'filtering' => ['filteringTerms' => [], 'resources' => []], 'updated' => null];
        $datasetsOfStudy = [];
        $allFacets = [];
        foreach ($datasets as $row) {
            $properties = self::json($row['properties']);
            $resources = $byStudy[$row['study_id']] ?? [];
            [$runs, $analyses, $linked] = self::linked($properties, $resources);

            $record = [
                'id' => $row['public_id'],
                'name' => $row['title'],
                'description' => (string) ($properties['description'] ?? ''),
                'externalUrl' => $portalUrl . '/datasets/' . rawurlencode($row['public_id']),
                'info' => array_filter([
                    'studyId' => $row['study_public_id'],
                    'studyTitle' => $row['study_title'],
                    'datasetTypes' => $properties['dataset_types'] ?? null,
                    'numberOfRuns' => count($runs),
                    'numberOfAnalyses' => count($analyses),
                    'releasedDate' => $row['released_date'] ?: null,
                ], fn ($v) => $v !== null && $v !== ''),
            ];
            if ($created = self::iso($row['creation_date'] ?? null)) {
                $record['createDateTime'] = $created;
            }
            if ($updated = self::iso($row['last_update'] ?? null)) {
                $record['updateDateTime'] = $updated;
                $out['updated'] = max((string) $out['updated'], $updated);
            }
            $facets = BeaconFilters::facets($linked);
            $out['datasets'][$row['public_id']] = ['record' => $record, 'facets' => $facets];
            $allFacets[] = $facets;
            $datasetsOfStudy[$row['study_id']][] = $row['public_id'];
        }

        foreach ($datasetsOfStudy as $studyId => $datasetIds) {
            $properties = $studyProperties[$studyId] ?? [];
            $publicId = (string) ($properties['public_id'] ?? '');
            if ($publicId === '') {
                continue;
            }
            $resources = $byStudy[$studyId] ?? [];
            $all = [];
            foreach (['sample', 'experiment'] as $kind) {
                foreach ($resources[$kind] ?? [] as $metadata) {
                    $all[] = [$kind, $metadata];
                }
            }
            $subjects = [];
            foreach ($resources['sample'] ?? [] as $sample) {
                if (is_scalar($sample['subject_id'] ?? null) && trim((string) $sample['subject_id']) !== '') {
                    $subjects[trim((string) $sample['subject_id'])] = true;
                }
            }
            $record = [
                'id' => $publicId,
                'name' => (string) ($properties['title'] ?? $publicId),
                'cohortType' => 'study-defined',
                'info' => array_filter([
                    'description' => $properties['description'] ?? null,
                    'studyType' => $properties['study_type'] ?? null,
                    'datasetIds' => $datasetIds,
                    'numberOfSamples' => count($resources['sample'] ?? []),
                ], fn ($v) => $v !== null && $v !== ''),
            ];
            if ($subjects) {
                $record['cohortSize'] = count($subjects);
            }
            $facets = BeaconFilters::facets($all);
            $out['cohorts'][$publicId] = ['record' => $record, 'facets' => $facets];
            $allFacets[] = $facets;
        }

        $out['filtering'] = BeaconFilters::filteringTerms($allFacets);

        return $out;
    }

    /**
     * The runs and the analyses of a dataset (it lists them by public id), and the samples and experiments that they use.
     *
     * @return array{0: array, 1: array, 2: array<int, array{0: string, 1: array}>}
     */
    private static function linked(array $dataset, array $resources): array
    {
        $runs = $analyses = [];
        foreach ($dataset as $key => $ids) {
            if (!is_array($ids)) {
                continue;
            }
            foreach ($ids as $id) {
                if (str_ends_with($key, 'run_public_ids') && isset($resources['run'][$id])) {
                    $runs[$id] = $resources['run'][$id];
                } elseif (str_ends_with($key, 'analysis_public_ids') && isset($resources['analysis'][$id])) {
                    $analyses[$id] = $resources['analysis'][$id];
                }
            }
        }

        $wanted = ['sample' => [], 'experiment' => []];
        foreach ([...array_values($runs), ...array_values($analyses)] as $properties) {
            foreach ($properties as $key => $value) {
                foreach (['sample', 'experiment'] as $kind) {
                    if (preg_match('/' . $kind . '_public_ids?$/', $key)) {
                        foreach ((array) $value as $id) {
                            $wanted[$kind][(string) $id] = true;
                        }
                    }
                }
            }
        }
        $linked = [];
        foreach ($wanted as $kind => $ids) {
            foreach (array_keys($ids) as $id) {
                if (isset($resources[$kind][$id])) {
                    $linked[] = [$kind, $resources[$kind][$id]];
                }
            }
        }

        return [$runs, $analyses, $linked];
    }

    private static function kind(string $type): ?string
    {
        foreach (['sample' => 'Sample', 'experiment' => 'Experiment', 'run' => 'Run', 'analysis' => 'Analysis'] as $kind => $needle) {
            if (str_contains($type, $needle)) {
                return $kind;
            }
        }

        return null;
    }

    private static function json(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        return json_decode((string) $value, true) ?: [];
    }

    /** A database timestamp (UTC) as the date and time of Beacon: 2026-10-07T13:15:51Z */
    private static function iso(?string $timestamp): ?string
    {
        if (!$timestamp) {
            return null;
        }
        try {
            return (new \DateTimeImmutable($timestamp, new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        } catch (\Exception) {
            return null;
        }
    }
}
