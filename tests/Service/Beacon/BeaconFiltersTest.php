<?php

namespace App\Tests\Service\Beacon;

use App\Service\Beacon\BeaconException;
use App\Service\Beacon\BeaconFilters;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BeaconFiltersTest extends TestCase
{
    private function resources(): array
    {
        return [
            ['sample', ['biological_sex' => 'female', 'case_control' => 'case']],
            ['sample', ['biological_sex' => 'Male ', 'case_control' => 'control']],
            ['sample', ['biological_sex' => '', 'case_control' => null]],
            ['experiment', ['library_strategy' => 'WXS', 'library_layout' => 'PAIRED', 'instrument_model_id' => 'ILLUMINA: Illumina Genome Analyzer II']],
            ['experiment', ['library_strategy' => 'RNA-Seq']],
            ['run', ['library_strategy' => 'ignored: not a sample or an experiment']],
        ];
    }

    public function testFacetsHoldTheDistinctValuesOfSamplesAndExperiments(): void
    {
        $facets = BeaconFilters::facets($this->resources());

        $this->assertSame(['female', 'Male'], $facets['sex']);
        $this->assertSame(['case', 'control'], $facets['sampleStatus']);
        $this->assertSame(['WXS', 'RNA-Seq'], $facets['libraryStrategy']);
        $this->assertSame(['PAIRED'], $facets['libraryLayout']);
        $this->assertSame(['ILLUMINA: Illumina Genome Analyzer II'], $facets['platformModel']);
        $this->assertArrayNotHasKey('librarySource', $facets, 'a facet without value is left out');
    }

    public function testFilteringTermsOfWhatTheDatasetsHold(): void
    {
        $terms = BeaconFilters::filteringTerms([BeaconFilters::facets($this->resources()), ['libraryStrategy' => ['wgs']]]);
        $byId = array_column($terms['filteringTerms'], null, 'id');

        $this->assertSame(['NCIT:C16576', 'NCIT:C20197', 'EFO:0009656', 'EFO:0009654', 'libraryStrategy', 'libraryLayout', 'platformModel'], array_keys($byId));
        $this->assertSame('alphanumeric', $byId['libraryStrategy']['type']);
        $this->assertSame(['RNA-Seq', 'wgs', 'WXS'], $byId['libraryStrategy']['values'], 'values of all datasets, sorted');
        $this->assertSame(['dataset', 'cohort'], $byId['NCIT:C16576']['scopes']);
        $this->assertSame('NCI Thesaurus', $byId['NCIT:C16576']['type']);
        $this->assertSame(['ncit', 'efo'], array_column($terms['resources'], 'id'));
    }

    public function testNoFacetsNoTerms(): void
    {
        $this->assertSame(['filteringTerms' => [], 'resources' => []], BeaconFilters::filteringTerms([]));
    }

    public function testAnOntologyTermOnlyAppearsWhenSomeDatasetHasIt(): void
    {
        $terms = BeaconFilters::filteringTerms([['sex' => ['female']]]);

        $this->assertSame(['NCIT:C16576'], array_column($terms['filteringTerms'], 'id'));
        $this->assertSame(['ncit'], array_column($terms['resources'], 'id'));
    }

    #[DataProvider('filters')]
    public function testFilters(string $filter, array $facets, bool $expected): void
    {
        $this->assertSame($expected, BeaconFilters::matches($facets, BeaconFilters::parse($filter)));
    }

    public static function filters(): array
    {
        $facets = ['sex' => ['female'], 'libraryStrategy' => ['WXS', 'RNA-Seq'], 'platformModel' => ['ILLUMINA: HiSeq 3000']];

        return [
            'ontology term present' => ['NCIT:C16576', $facets, true],
            'ontology term absent' => ['NCIT:C20197', $facets, false],
            'alphanumeric equals, any case' => ['libraryStrategy:wxs', $facets, true],
            'alphanumeric with =' => ['libraryStrategy:=RNA-Seq', $facets, true],
            'alphanumeric other value' => ['libraryStrategy:WGS', $facets, false],
            'not equal: excluded value is there' => ['libraryStrategy:!WXS', $facets, false],
            'not equal: the value is not there' => ['libraryStrategy:!WGS', $facets, true],
            'not equal: no value at all' => ['librarySource:!GENOMIC', $facets, true],
            'wildcard' => ['platformModel:ILLUMINA%', $facets, true],
            'wildcard in the middle' => ['platformModel:%HiSeq%', $facets, true],
            'wildcard that does not match' => ['platformModel:NovaSeq%', $facets, false],
            'value with a colon' => ['platformModel:ILLUMINA: HiSeq 3000', $facets, true],
            'all filters must match (AND)' => ['NCIT:C16576,libraryStrategy:WGS', $facets, false],
            'all filters that match' => ['NCIT:C16576,libraryStrategy:WXS', $facets, true],
            'no filter' => ['', $facets, true],
        ];
    }

    public function testTheFiltersCanBeAList(): void
    {
        $filters = BeaconFilters::parse(['NCIT:C16576', 'libraryStrategy:WXS,libraryLayout:PAIRED']);

        $this->assertSame(['NCIT:C16576', 'libraryStrategy', 'libraryLayout'], array_column($filters, 'id'));
    }

    public function testTheRegularExpressionCharactersOfAValueAreLiteral(): void
    {
        $filters = BeaconFilters::parse('platformModel:A.B(x)');

        $this->assertFalse(BeaconFilters::matches(['platformModel' => ['AxB(x)']], $filters));
        $this->assertTrue(BeaconFilters::matches(['platformModel' => ['A.B(x)']], $filters));
    }

    #[DataProvider('badFilters')]
    public function testUnknownFiltersAreRefused(string $filter): void
    {
        $this->expectException(BeaconException::class);
        $this->expectExceptionCode(400);
        BeaconFilters::parse($filter);
    }

    public static function badFilters(): array
    {
        return [['HP:0001631'], ['nonsense'], ['libraryStrategy'], ['libraryStrategy:'], ['libraryStrategy:!'], ['age:>P70Y']];
    }
}
