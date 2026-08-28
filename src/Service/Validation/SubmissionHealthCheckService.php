<?php

namespace App\Service\Validation;

use MeekroDB;

class SubmissionHealthCheckService
{
    private const INACTIVE_STATUSES = ['DEL', 'REJ'];
    private const DATASET_TYPES = ['Dataset', 'ImageDataset'];

    public function __construct(private MeekroDB $db) {}

    public function check(string $studyResourceId, ?string $studyPublicId = null): array
    {
        $errors = [];
        $warnings = [];
        $issues = [];

        $descendants = $this->fetchDescendants($studyResourceId);

        $byPublicId = [];
        foreach ($descendants as $d) {
            if (!empty($d['public_id'])) {
                $byPublicId[$d['public_id']] = $d;
            }
        }

        $fkMap = $this->fetchForeignKeyMap();

        $this->checkDeletedButLinked($descendants, $errors, $issues);
        $this->checkCrossReferences($descendants, $byPublicId, $fkMap, $errors, $issues);
        $this->checkDatasetComposition($descendants, $fkMap, $warnings, $issues);
        $this->checkOrphanFiles($descendants, $warnings, $issues);
        $this->checkStudyHasDatasets($descendants, $studyPublicId ?? $studyResourceId, $errors, $issues);

        $success = empty($errors);
        if ($success) {
            $message = $warnings ? 'Submission is valid, but has warnings' : 'Submission is valid';
        } else {
            $message = 'Submission has data integrity problems that must be resolved before it can be submitted';
        }

        return [
            'success'  => $success,
            'message'  => $message,
            'errors'   => $errors,
            'warnings' => $warnings,
            'issues'   => $issues,
        ];
    }

    private function fetchDescendants(string $studyResourceId): array
    {
        return $this->db->query(
            "WITH RECURSIVE descendants AS (
                SELECT %s::uuid AS id
                UNION
                SELECT rv.domain_resource_id
                FROM relationship_view rv
                JOIN descendants d ON rv.range_resource_id = d.id
                WHERE rv.predicate_name IN ('isPartOf', 'isProcessedIn')
                  AND rv.is_active = true
            )
            SELECT r.id, rt.name AS resource_type, r.status_type_id,
                   r.properties ->> 'public_id' AS public_id,
                   r.properties ->> 'title' AS title,
                   r.properties AS properties
            FROM descendants d
            JOIN resource r ON r.id = d.id
            JOIN resource_type rt ON rt.id = r.resource_type_id
            WHERE d.id <> %s::uuid",
            $studyResourceId,
            $studyResourceId
        );
    }

    private function fetchForeignKeyMap(): array
    {
        $rows = $this->db->query(
            "SELECT rt.name, rt.properties -> 'data_schema' -> 'x-resource' ->> 'schema' AS x_schema
             FROM resource_type rt"
        );

        $map = [];
        foreach ($rows as $row) {
            if (empty($row['x_schema'])) {
                continue;
            }
            $schema = json_decode($row['x_schema'], true);
            $fields = $schema['fields'] ?? [];

            $fieldsByName = [];
            foreach ($fields as $f) {
                if (isset($f['name'])) {
                    $fieldsByName[$f['name']] = $f;
                }
            }

            $refs = [];
            foreach ($fields as $f) {
                if (isset($f['aliasOf']) && str_starts_with($f['aliasOf'], 'sdafile_public_id')) {
                    $refs[$f['aliasOf']] = 'SdaFile';
                }
            }
            foreach ($schema['foreignKeys'] ?? [] as $fk) {
                $fieldName = $fk['fields'][0] ?? null;
                $refType = $fk['reference']['resource'] ?? null;
                if (!$fieldName || !$refType) {
                    continue;
                }
                $isList = ($fieldsByName[$fieldName]['type'] ?? null) === 'list';
                $propKey = strtolower($refType) . ($isList ? '_public_ids' : '_public_id');
                $refs[$propKey] = $refType;
            }

            if ($refs) {
                $map[$row['name']] = $refs;
            }
        }

        return $map;
    }

    private function checkDeletedButLinked(array $descendants, array &$errors, array &$issues): void
    {
        foreach ($descendants as $r) {
            if (!in_array($r['status_type_id'], self::INACTIVE_STATUSES, true)) {
                continue;
            }
            $verb = $r['status_type_id'] === 'DEL' ? 'deleted' : 'rejected';
            $message = "{$r['resource_type']} {$r['public_id']} is linked to the study but has been $verb";
            $errors[] = $message;
            $issues[] = $this->issue('error', 'deleted_but_linked', $r, $message);
        }
    }

    private function checkCrossReferences(array $descendants, array $byPublicId, array $fkMap, array &$errors, array &$issues): void
    {
        $pending = [];
        $toLookup = [];

        foreach ($descendants as $owner) {
            if (in_array($owner['status_type_id'], self::INACTIVE_STATUSES, true)) {
                continue; // already reported by checkDeletedButLinked
            }
            $refs = $fkMap[$owner['resource_type']] ?? null;
            if (!$refs) {
                continue;
            }
            $props = json_decode($owner['properties'], true) ?: [];
            foreach ($refs as $propKey => $refType) {
                if (empty($props[$propKey])) {
                    continue;
                }
                $values = is_array($props[$propKey]) ? $props[$propKey] : [$props[$propKey]];
                foreach ($values as $refPublicId) {
                    if (!$refPublicId) {
                        continue;
                    }
                    $pending[] = ['owner' => $owner, 'refType' => $refType, 'refPublicId' => $refPublicId];
                    if (!isset($byPublicId[$refPublicId])) {
                        $toLookup[$refPublicId] = true;
                    }
                }
            }
        }

        $globalLookup = [];
        if ($toLookup) {
            $rows = $this->db->query(
                "SELECT r.properties ->> 'public_id' AS public_id, rt.name AS resource_type, r.status_type_id
                 FROM resource r
                 JOIN resource_type rt ON rt.id = r.resource_type_id
                 WHERE r.properties ->> 'public_id' IN %ls",
                array_keys($toLookup)
            );
            foreach ($rows as $row) {
                $globalLookup[$row['public_id']] = $row;
            }
        }

        foreach ($pending as $p) {
            $owner = $p['owner'];
            $refPublicId = $p['refPublicId'];
            $local = $byPublicId[$refPublicId] ?? null;
            $target = $local ?? ($globalLookup[$refPublicId] ?? null);

            if (!$target) {
                $message = "{$owner['resource_type']} {$owner['public_id']} references {$p['refType']} $refPublicId, which does not exist";
            } elseif (in_array($target['status_type_id'], self::INACTIVE_STATUSES, true)) {
                $message = "{$owner['resource_type']} {$owner['public_id']} references {$p['refType']} $refPublicId, which has been deleted";
            } elseif (!$local) {
                $message = "{$owner['resource_type']} {$owner['public_id']} references {$p['refType']} $refPublicId, which is no longer linked to this study";
            } else {
                continue;
            }

            $errors[] = $message;
            $issues[] = $this->issue('error', 'stale_reference', $owner, $message);
        }
    }

    private function checkDatasetComposition(array $descendants, array $fkMap, array &$warnings, array &$issues): void
    {
        $datasets = array_filter(
            $descendants,
            fn($r) => in_array($r['resource_type'], self::DATASET_TYPES, true)
                && !in_array($r['status_type_id'], self::INACTIVE_STATUSES, true)
        );
        if (!$datasets) {
            return;
        }

        $datasetIds = array_column($datasets, 'id');
        $policyLinks = $this->db->query(
            "SELECT rv.domain_resource_id AS dataset_id, rv.is_active
             FROM relationship_view rv
             WHERE rv.domain_resource_id IN %ls
               AND rv.predicate_name = 'isLinkedTo'
               AND rv.range_type = 'Policy'",
            $datasetIds
        );
        $policyLinkByDataset = [];
        foreach ($policyLinks as $pl) {
            $policyLinkByDataset[$pl['dataset_id']] = $this->isTruthy($pl['is_active']);
        }

        foreach ($datasets as $d) {
            $props = json_decode($d['properties'], true) ?: [];
            $refs = $fkMap[$d['resource_type']] ?? [];

            $hasComposition = false;
            foreach (array_keys($refs) as $propKey) {
                if (!empty($props[$propKey])) {
                    $hasComposition = true;
                    break;
                }
            }
            if (!$hasComposition && $refs) {
                $this->addWarning($warnings, $issues, 'no_runs_or_analyses', $d, "Dataset {$d['public_id']} has no linked runs or analyses");
            }

            $policyId = $props['policy_id'] ?? null;
            if (!$policyId) {
                $this->addWarning($warnings, $issues, 'no_policy', $d, "Dataset {$d['public_id']} has no DAC/policy assigned");
            } elseif (!($policyLinkByDataset[$d['id']] ?? false)) {
                $this->addWarning($warnings, $issues, 'policy_not_active', $d, "Dataset {$d['public_id']}'s data access policy is not yet active/approved");
            }
        }
    }

    private function checkOrphanFiles(array $descendants, array &$warnings, array &$issues): void
    {
        $files = array_filter(
            $descendants,
            fn($r) => $r['resource_type'] === 'SdaFile' && in_array($r['status_type_id'], ['PUB', 'VER'], true)
        );
        if (!$files) {
            return;
        }

        $fileIds = array_column($files, 'id');
        $linked = $this->db->query(
            "SELECT DISTINCT sdafile_id
             FROM sdafile_study_dataset_view
             WHERE sdafile_id IN %ls AND dataset_id IS NOT NULL",
            $fileIds
        );
        $linkedSet = array_flip(array_column($linked, 'sdafile_id'));

        foreach ($files as $f) {
            if (!isset($linkedSet[$f['id']])) {
                $this->addWarning($warnings, $issues, 'orphan_file', $f, "File {$f['public_id']} is not linked to any dataset");
            }
        }
    }

    private function checkStudyHasDatasets(array $descendants, string $studyLabel, array &$errors, array &$issues): void
    {
        foreach ($descendants as $r) {
            if (in_array($r['resource_type'], self::DATASET_TYPES, true) && !in_array($r['status_type_id'], self::INACTIVE_STATUSES, true)) {
                return;
            }
        }

        $message = "Study $studyLabel has no datasets";
        $errors[] = $message;
        $issues[] = [
            'severity' => 'error',
            'code' => 'empty_study',
            'resource_type' => 'Study',
            'resource_id' => null,
            'resource_public_id' => $studyLabel,
            'message' => $message,
        ];
    }

    private function addWarning(array &$warnings, array &$issues, string $code, array $resource, string $message): void
    {
        $warnings[] = $message;
        $issues[] = $this->issue('warning', $code, $resource, $message);
    }

    private function issue(string $severity, string $code, array $resource, string $message): array
    {
        return [
            'severity' => $severity,
            'code' => $code,
            'resource_type' => $resource['resource_type'],
            'resource_id' => $resource['id'],
            'resource_public_id' => $resource['public_id'],
            'message' => $message,
        ];
    }

    private function isTruthy(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't' || $value === 'true';
    }
}
