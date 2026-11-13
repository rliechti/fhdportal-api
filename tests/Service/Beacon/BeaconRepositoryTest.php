<?php

namespace App\Tests\Service\Beacon;

use App\Service\Beacon\BeaconConfig;
use App\Service\Beacon\BeaconRepository;
use MeekroDB;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

class BeaconRepositoryTest extends TestCase
{
    private const PORTAL = 'https://portal.example';

    private function datasetRow(string $id, string $studyId, array $properties, string $title = 'A dataset'): array
    {
        return [
            'public_id' => $id, 'study_id' => $studyId, 'study_public_id' => 'S-' . $studyId, 'study_title' => 'Study ' . $studyId, 'title' => $title,
            'properties' => json_encode($properties + ['public_id' => $id]),
            'creation_date' => '2026-10-07 13:15:51.267987', 'last_update' => '2026-10-08 09:00:00', 'released_date' => '2026-10-20',
        ];
    }

    private function related(string $studyId, string $type, array $properties): array
    {
        return ['study_id' => $studyId, 'type' => $type, 'properties' => json_encode($properties)];
    }

    private function fixture(): array
    {
        $studies = [['id' => 'st1', 'properties' => json_encode(['public_id' => 'CHFEGAS1', 'title' => 'Renal study', 'description' => 'RCC cohort', 'study_type' => 'Cancer Genomics'])]];
        $datasets = [
            $this->datasetRow('CHFEGAD1', 'st1', ['description' => 'Exome of the cohort', 'dataset_types' => ['Exome sequencing'], 'molecularrun_public_ids' => ['R1', 'R2'], 'molecularanalysis_public_ids' => ['A1']]),
            $this->datasetRow('CHFEGAD2', 'st1', ['description' => 'RNA only', 'molecularrun_public_ids' => ['R3']], 'RNA dataset'),
        ];
        $related = [
            $this->related('st1', 'Sample', ['public_id' => 'N1', 'subject_id' => 'P1', 'biological_sex' => 'female', 'case_control' => 'case']),
            $this->related('st1', 'Sample', ['public_id' => 'N2', 'subject_id' => 'P1', 'biological_sex' => 'female', 'case_control' => 'control']),
            $this->related('st1', 'Sample', ['public_id' => 'N3', 'subject_id' => 'P2', 'biological_sex' => 'male', 'case_control' => 'case']),
            $this->related('st1', 'MolecularExperiment', ['public_id' => 'X1', 'library_strategy' => 'WXS']),
            $this->related('st1', 'MolecularExperiment', ['public_id' => 'X2', 'library_strategy' => 'RNA-Seq']),
            $this->related('st1', 'MolecularRun', ['public_id' => 'R1', 'molecularexperiment_public_id' => 'X1', 'sample_public_id' => 'N1']),
            $this->related('st1', 'MolecularRun', ['public_id' => 'R2', 'molecularexperiment_public_id' => 'X1', 'sample_public_id' => 'N3']),
            $this->related('st1', 'MolecularRun', ['public_id' => 'R3', 'molecularexperiment_public_id' => 'X2', 'sample_public_id' => 'N2']),
            $this->related('st1', 'MolecularAnalysis', ['public_id' => 'A1', 'molecularexperiment_public_ids' => ['X1'], 'sample_public_ids' => ['N1', 'N3']]),
        ];

        return BeaconRepository::build($datasets, $studies, $related, self::PORTAL);
    }

    public function testDatasetRecords(): void
    {
        $model = $this->fixture();
        $record = $model['datasets']['CHFEGAD1']['record'];

        $this->assertSame(['CHFEGAD1', 'CHFEGAD2'], array_keys($model['datasets']));
        $this->assertSame('CHFEGAD1', $record['id']);
        $this->assertSame('A dataset', $record['name']);
        $this->assertSame('Exome of the cohort', $record['description']);
        $this->assertSame('https://portal.example/datasets/CHFEGAD1', $record['externalUrl']);
        $this->assertSame('2026-10-07T13:15:51Z', $record['createDateTime']);
        $this->assertSame('2026-10-08T09:00:00Z', $record['updateDateTime']);
        $this->assertSame('2026-10-08T09:00:00Z', $model['updated']);
        $this->assertSame(['studyId' => 'S-st1', 'studyTitle' => 'Study st1', 'datasetTypes' => ['Exome sequencing'], 'numberOfRuns' => 2, 'numberOfAnalyses' => 1, 'releasedDate' => '2026-10-20'], $record['info']);
    }

    public function testTheFacetsOfADatasetComeFromItsOwnRunsAndAnalyses(): void
    {
        $facets = $this->fixture()['datasets'];

        // CHFEGAD1: runs R1, R2 and analysis A1 => samples N1, N3, experiment X1
        $this->assertSame(['female', 'male'], $facets['CHFEGAD1']['facets']['sex']);
        $this->assertSame(['WXS'], $facets['CHFEGAD1']['facets']['libraryStrategy']);
        // CHFEGAD2: run R3 => sample N2, experiment X2
        $this->assertSame(['female'], $facets['CHFEGAD2']['facets']['sex']);
        $this->assertSame(['control'], $facets['CHFEGAD2']['facets']['sampleStatus']);
        $this->assertSame(['RNA-Seq'], $facets['CHFEGAD2']['facets']['libraryStrategy']);
    }

    public function testAStudyIsACohort(): void
    {
        $model = $this->fixture();
        $cohort = $model['cohorts']['CHFEGAS1'];

        $this->assertSame(['CHFEGAS1'], array_keys($model['cohorts']));
        $this->assertSame('CHFEGAS1', $cohort['record']['id']);
        $this->assertSame('Renal study', $cohort['record']['name']);
        $this->assertSame('study-defined', $cohort['record']['cohortType']);
        $this->assertSame(2, $cohort['record']['cohortSize'], 'distinct subjects, not samples');
        $this->assertSame(['description' => 'RCC cohort', 'studyType' => 'Cancer Genomics', 'datasetIds' => ['CHFEGAD1', 'CHFEGAD2'], 'numberOfSamples' => 3], $cohort['record']['info']);
        // the whole study: all its samples and experiments
        $this->assertSame(['female', 'male'], $cohort['facets']['sex']);
        $this->assertSame(['WXS', 'RNA-Seq'], $cohort['facets']['libraryStrategy']);
    }

    public function testFilteringTermsCoverAllTheEntries(): void
    {
        $ids = array_column($this->fixture()['filtering']['filteringTerms'], 'id');

        $this->assertContains('NCIT:C16576', $ids);
        $this->assertContains('NCIT:C20197', $ids);
        $this->assertContains('libraryStrategy', $ids);
    }

    public function testACohortWithoutSubjectIdsHasNoSize(): void
    {
        $model = BeaconRepository::build(
            [$this->datasetRow('D1', 'st1', [])],
            [['id' => 'st1', 'properties' => json_encode(['public_id' => 'S1', 'title' => 'S'])]],
            [$this->related('st1', 'Sample', ['public_id' => 'N1'])],
            self::PORTAL
        );

        $this->assertArrayNotHasKey('cohortSize', $model['cohorts']['S1']['record']);
    }

    public function testNothingPublishedNothingShown(): void
    {
        $model = BeaconRepository::build([], [], [], self::PORTAL);

        $this->assertSame([], $model['datasets']);
        $this->assertSame([], $model['cohorts']);
        $this->assertSame(['filteringTerms' => [], 'resources' => []], $model['filtering']);
    }

    public function testOnlyPublishedDatasetsAreRead(): void
    {
        $queries = [];
        $db = $this->createStub(MeekroDB::class);
        $db->method('query')->willReturnCallback(function (string $sql, ...$args) use (&$queries) {
            $queries[] = [$sql, $args];

            return [];
        });

        (new BeaconRepository($db, new ArrayAdapter(), new BeaconConfig()))->model();

        $this->assertCount(1, $queries, 'without a published dataset nothing else is read');
        $this->assertStringContainsString('FROM dataset_view WHERE status_type_id = %s', $queries[0][0]);
        $this->assertSame(['PUB'], $queries[0][1]);
    }

    public function testTheModelIsKeptForAWhile(): void
    {
        $calls = 0;
        $db = $this->createStub(MeekroDB::class);
        $db->method('query')->willReturnCallback(function () use (&$calls) {
            $calls++;

            return [];
        });
        $repository = new BeaconRepository($db, new ArrayAdapter(), new BeaconConfig());

        $repository->model();
        $repository->model();

        $this->assertSame(1, $calls);
    }
}
