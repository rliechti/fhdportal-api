<?php

namespace App\Service\Beacon;

/**
 * The filtering terms of the Beacon and the evaluation of the `filters` parameter.
 *
 * A dataset (or a study, as a cohort) carries facets: the values that its samples and experiments have for some properties of the
 * metadata. A filter asks for the datasets that have a value:
 *
 *   - ontology terms (NCIT:C16576): the metadata of the portal is not linked to ontologies yet, so only a few values are mapped,
 *     with the terms that the report on the Beacon / FHD model uses (sex, case or control).
 *   - alphanumeric terms (libraryStrategy:WGS, libraryStrategy:!WGS, libraryStrategy:WG%): the value of the metadata as it is. The
 *     value starts with ! to exclude it, % is a wildcard, the comparison ignores the case.
 *
 * Several filters are combined with AND (there are no boolean expressions in Beacon). The descendants of an ontology term are not
 * included (includeDescendantTerms is a parameter of the POST requests, which this Beacon does not offer).
 */
class BeaconFilters
{
    /**
     * Ontology terms: id => [facet, value of the metadata, label, resource]
     *
     * @var array<string, array{facet: string, value: string, label: string, resource: string}>
     */
    public const ONTOLOGY_TERMS = [
        'NCIT:C16576' => ['facet' => 'sex', 'value' => 'female', 'label' => 'female', 'resource' => 'ncit'],
        'NCIT:C20197' => ['facet' => 'sex', 'value' => 'male', 'label' => 'male', 'resource' => 'ncit'],
        'EFO:0009656' => ['facet' => 'sampleStatus', 'value' => 'case', 'label' => 'abnormal sample', 'resource' => 'efo'],
        'EFO:0009654' => ['facet' => 'sampleStatus', 'value' => 'control', 'label' => 'reference sample', 'resource' => 'efo'],
    ];

    /** Alphanumeric terms: id => [facet, label]. The facet is also the id. */
    public const ALPHANUMERIC_TERMS = [
        'libraryStrategy' => 'Library strategy',
        'librarySource' => 'Library source',
        'librarySelection' => 'Library selection',
        'libraryLayout' => 'Library layout',
        'platformModel' => 'Instrument model',
    ];

    /** Where the values come from: facet => [kind of resource, property of its metadata] */
    public const SOURCES = [
        'sex' => ['sample', 'biological_sex'],
        'sampleStatus' => ['sample', 'case_control'],
        'libraryStrategy' => ['experiment', 'library_strategy'],
        'librarySource' => ['experiment', 'library_source'],
        'librarySelection' => ['experiment', 'library_selection'],
        'libraryLayout' => ['experiment', 'library_layout'],
        'platformModel' => ['experiment', 'instrument_model_id'],
    ];

    public const RESOURCES = [
        'ncit' => ['id' => 'ncit', 'name' => 'NCI Thesaurus', 'namespacePrefix' => 'NCIT', 'iriPrefix' => 'http://purl.obolibrary.org/obo/NCIT_', 'url' => 'http://purl.obolibrary.org/obo/ncit.owl'],
        'efo' => ['id' => 'efo', 'name' => 'Experimental Factor Ontology', 'namespacePrefix' => 'EFO', 'iriPrefix' => 'http://www.ebi.ac.uk/efo/EFO_', 'url' => 'http://www.ebi.ac.uk/efo/efo.owl'],
    ];

    /**
     * The facets of a group of resources.
     *
     * @param array<int, array{0: string, 1: array}> $resources [kind, metadata] of the samples and experiments
     * @return array<string, string[]> facet => the distinct values (as they are written in the metadata)
     */
    public static function facets(array $resources): array
    {
        $facets = [];
        foreach (self::SOURCES as $facet => [$kind, $property]) {
            $values = [];
            foreach ($resources as [$resourceKind, $metadata]) {
                $value = $resourceKind === $kind ? ($metadata[$property] ?? null) : null;
                if (is_scalar($value) && trim((string) $value) !== '') {
                    $values[trim((string) $value)] = true;
                }
            }
            if ($values) {
                $facets[$facet] = array_keys($values);
            }
        }

        return $facets;
    }

    /**
     * The filtering terms that the facets of the datasets allow.
     *
     * @param array<int, array<string, string[]>> $allFacets the facets of every dataset and cohort
     * @return array{filteringTerms: array<int, array>, resources: array<int, array>}
     */
    public static function filteringTerms(array $allFacets): array
    {
        $present = [];
        foreach ($allFacets as $facets) {
            foreach ($facets as $facet => $values) {
                foreach ($values as $value) {
                    $present[$facet][strtolower($value)] = $value;
                }
            }
        }

        $terms = [];
        $resources = [];
        foreach (self::ONTOLOGY_TERMS as $id => $term) {
            if (isset($present[$term['facet']][$term['value']])) {
                $terms[] = [
                    'type' => self::RESOURCES[$term['resource']]['name'],
                    'id' => $id,
                    'label' => $term['label'],
                    'scopes' => ['dataset', 'cohort'],
                ];
                $resources[$term['resource']] = self::RESOURCES[$term['resource']];
            }
        }
        foreach (self::ALPHANUMERIC_TERMS as $id => $label) {
            if (!empty($present[$id])) {
                $values = array_values($present[$id]);
                sort($values, SORT_NATURAL | SORT_FLAG_CASE);
                $terms[] = ['type' => 'alphanumeric', 'id' => $id, 'label' => $label, 'scopes' => ['dataset', 'cohort'], 'values' => $values];
            }
        }

        return ['filteringTerms' => $terms, 'resources' => array_values($resources)];
    }

    /**
     * Turns the value of the `filters` parameter into filters.
     *
     * @param string|string[]|null $filters comma separated, or a list
     * @return array<int, array{facet: string, value: string, negate: bool, wildcard: bool, id: string}>
     * @throws BeaconException 400 for a filter that this Beacon does not know
     */
    public static function parse(string|array|null $filters): array
    {
        $items = [];
        foreach ((array) $filters as $filter) {
            foreach (explode(',', (string) $filter) as $item) {
                if (trim($item) !== '') {
                    $items[] = trim($item);
                }
            }
        }

        $parsed = [];
        foreach ($items as $item) {
            if (isset(self::ONTOLOGY_TERMS[$item])) {
                $term = self::ONTOLOGY_TERMS[$item];
                $parsed[] = ['id' => $item, 'facet' => $term['facet'], 'value' => $term['value'], 'negate' => false, 'wildcard' => false];
                continue;
            }
            [$id, $rest] = array_pad(explode(':', $item, 2), 2, null);
            if ($rest !== null && isset(self::ALPHANUMERIC_TERMS[$id])) {
                $negate = str_starts_with($rest, '!');
                $value = ltrim($rest, '!=');
                if ($value === '') {
                    throw new BeaconException("The filter '$item' has no value", 400);
                }
                $parsed[] = ['id' => $id, 'facet' => $id, 'value' => $value, 'negate' => $negate, 'wildcard' => str_contains($value, '%')];
                continue;
            }
            throw new BeaconException("Unknown filter '$item': see /filtering_terms for the filters of this Beacon", 400);
        }

        return $parsed;
    }

    /**
     * Whether an entry (its facets) satisfies all the filters.
     *
     * @param array<string, string[]> $facets
     * @param array<int, array{facet: string, value: string, negate: bool, wildcard: bool}> $filters
     */
    public static function matches(array $facets, array $filters): bool
    {
        foreach ($filters as $filter) {
            $pattern = '/^' . str_replace('%', '.*', preg_quote(strtolower($filter['value']), '/')) . '$/su';
            $found = false;
            foreach ($facets[$filter['facet']] ?? [] as $value) {
                if (preg_match($pattern, strtolower($value))) {
                    $found = true;
                    break;
                }
            }
            if ($found === $filter['negate']) {
                return false; // wanted and missing, or excluded and present
            }
        }

        return true;
    }
}
