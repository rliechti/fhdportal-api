#!/usr/bin/php
<?php
require __DIR__ . '/include.php';
require_once dirname(__DIR__).'/vendor/mk-j/php_xlsxwriter/xlsxwriter.class.php';

main();

function listResourceTypes()
{
    //$resource_order = array('Study','Sample','MolecularExperiment','MolecularRun','MolecularAnalysis','Dataset');

    $type_order = DB::query("SELECT relationship_rule_view.* from relationship_rule_view inner join resource_type on resource_type.id = relationship_rule_view.domain_type_id and resource_type.validator_mandatory = true");
    $domains = array_values(array_unique(array_map(function ($d) {return $d['domain_type_name'];}, $type_order)));
    $ranges = array_values(array_unique(array_map(function ($d) {return $d['range_type_name'];}, $type_order)));
    $resource_order = array_values(array_diff($ranges, $domains));

    $idx = 0;
    while ($idx < count($type_order) - 1) {
        $idx++;
        $resource_order = getResourceOrder_sub($type_order, $resource_order);
    }
    return $resource_order;
}

function getResourceOrder_sub($type_order,$new_order){
    foreach($type_order as $t){
        $ok = true;
        if(!in_array($t['domain_type_name'],$new_order)){
            $c = $t['domain_type_name'];
            foreach($type_order as $t2){
                if(!in_array($t2['domain_type_name'],$new_order) && $c == $t2['range_type_name']){
                    $ok = false;
                }
            }
            if($ok){ $new_order[] = $c; }
        }
    }
    return $new_order;
}


function writeTemplate($local_resource_dir)
{
    // header('Content-disposition: attachment; filename="'.XLSXWriter::sanitize_filename($filename).'"');
    // header("Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet");
    // header('Content-Transfer-Encoding: binary');
    // header('Cache-Control: must-revalidate');
    // header('Pragma: public');
    $resource_types = DB::query("SELECT id, name, properties from resource_type order by name");
    foreach ($resource_types as $type) {
        $resource_type = $type['name'];
        $filename = "template_$resource_type.xlsx";


        $template_dir = $local_resource_dir.'/data/template/';
        $filepath = $template_dir . "/".$filename;
        $json_schemas = $type['properties'];
        if (!$json_schemas) {
            continue;
        }
        $schema = json_decode($json_schemas);
        if (!isset($schema->data_schema)) {
            continue;
        }

        $headers = array();
        $writer = new XLSXWriter();
        foreach ($schema->data_schema->properties as $prop_name => $prop) {
            $headers[$prop_name] = 'string';
        }
        $writer->writeSheetHeader($resource_type, $headers);

        $writer->writeToFile($filepath);
    }
}

function writeExample($local_resource_dir)
{

    $cmd = "mkdir $local_resource_dir/data/package";
    exec($cmd);
    $content_sample = "alias\ttitle\tcell_line\tphenotype\tsubject_id\tdescription\tbiosample_id\tcase_control\torganism_part\tbiological_sex\tDNA_concentration\nTEST-NSCLC-0846-FIXT-01-DNA-01\tTEST-NSCLC-0846-FIXT-01-DNA-01\t\tIVB:Adenocarcinoma (8140)\tTEST-NSCLC-0846\tFixed tissue. DNA\tTEST-NSCLC-0846-FIXT-01-DNA-01\ttumor\tUpper lobe, lung (C34.1): Regional lymph node metastases\tmale\t33\nTEST-NSCLC-0846-PXD-01-DNA-01\tTEST-NSCLC-0846-PXD-01-DNA-01\t\tIVB:Adenocarcinoma (8140)\tTEST-NSCLC-0846\tBlood PXD. DNA\tTEST-NSCLC-0846-PXD-01-DNA-01\tgermline\tUpper lobe, lung (C34.1)\tmale\t5.83\nTEST-NSCLC-0915-FIXT-01-DNA-01\tTEST-NSCLC-0915-FIXT-01-DNA-01\t\tIVA:Adenocarcinoma (8140)\tTEST-NSCLC-0915\tFixed tissue. DNA\tTEST-NSCLC-0915-FIXT-01-DNA-01\ttumor\tUpper lobe, lung (C34.1): Regional lymph node metastases\tmale\t51\nTEST-NSCLC-0915-PXD-01-DNA-01\tTEST-NSCLC-0915-PXD-01-DNA-01\t\tIVA:Adenocarcinoma (8140)\tTEST-NSCLC-0915\tBlood PXD. DNA\tTEST-NSCLC-0915-PXD-01-DNA-01\tgermline\tUpper lobe, lung (C34.1)\tmale\t5.81\nTEST-NSCLC-1984-FIXT-01-DNA-01\tTEST-NSCLC-1984-FIXT-01-DNA-01\t\tIVA:Adenocarcinoma (8140)\tTEST-NSCLC-1984\tFixed tissue. DNA\tTEST-NSCLC-1984-FIXT-01-DNA-01\ttumor\tUpper lobe, lung (C34.1): Primary tumor\tfemale\t7.54\nTEST-NSCLC-1984-PXD-01-DNA-01\tTEST-NSCLC-1984-PXD-01-DNA-01\t\tIVA:Adenocarcinoma (8140)\tTEST-NSCLC-1984\tBlood PXD. DNA\tTEST-NSCLC-1984-PXD-01-DNA-01\tgermline\tUpper lobe, lung (C34.1)\tfemale\t127\nTEST-NSCLC-1123-FIXT-01-DNA-01\tTEST-NSCLC-1123-FIXT-01-DNA-01\t\tIVA:Adenocarcinoma (8140)\tTEST-NSCLC-1123\tFixed tissue. DNA\tTEST-NSCLC-1123-FIXT-01-DNA-01\ttumor\tMiddle lobe, lung (C34.2): Distant metastases\tmale\t51.6\nTEST-NSCLC-1123-PXD-01-DNA-01\tTEST-NSCLC-1123-PXD-01-DNA-01\t\tIVA:Adenocarcinoma (8140)\tTEST-NSCLC-1123\tBlood PXD. DNA\tTEST-NSCLC-1123-PXD-01-DNA-01\tgermline\tMiddle lobe, lung (C34.2)\tmale\t175\nTEST-NSCLC-0512-FIXT-01-DNA-01\tTEST-NSCLC-0512-FIXT-01-DNA-01\t\tIVB:Adenocarcinoma (8140)\tTEST-NSCLC-0512\tFixed tissue. DNA\tTEST-NSCLC-0512-FIXT-01-DNA-01\ttumor\tLower lobe, lung (C34.3): Primary tumor\tfemale\t1.37\nTEST-NSCLC-0512-PXD-01-DNA-01\tTEST-NSCLC-0512-PXD-01-DNA-01\t\tIVB:Adenocarcinoma (8140)\tTEST-NSCLC-0512\tBlood PXD. DNA\tTEST-NSCLC-0512-PXD-01-DNA-01\tgermline\tLower lobe, lung (C34.3)\tfemale\t149\nTEST-NSCLC-1201-FIXT-01-DNA-01\tTEST-NSCLC-1201-FIXT-01-DNA-01\t\tIVB:Adenocarcinoma (8140)\tTEST-NSCLC-1201\tFixed tissue. DNA\tTEST-NSCLC-1201-FIXT-01-DNA-01\ttumor\tUpper lobe, lung (C34.1): Distant metastases\tmale\t29.8\nTEST-NSCLC-1201-PXD-02-DNA-01\tTEST-NSCLC-1201-PXD-02-DNA-01\t\tIVB:Adenocarcinoma (8140)\tTEST-NSCLC-1201\tBlood PXD. DNA\tTEST-NSCLC-1201-PXD-02-DNA-01\tgermline\tUpper lobe, lung (C34.1)\tmale\t86.4\nTEST-NSCLC-0337-FIXT-01-DNA-01\tTEST-NSCLC-0337-FIXT-01-DNA-01\t\tIVB:Adenocarcinoma (8140)\tTEST-NSCLC-0337\tFixed tissue. DNA\tTEST-NSCLC-0337-FIXT-01-DNA-01\ttumor\tUpper lobe, lung (C34.1): Distant metastases\tmale\t4.53\nTEST-NSCLC-0337-PXD-01-DNA-01\tTEST-NSCLC-0337-PXD-01-DNA-01\t\tIVB:Adenocarcinoma (8140)\tTEST-NSCLC-0337\tBlood PXD. DNA\tTEST-NSCLC-0337-PXD-01-DNA-01\tgermline\tUpper lobe, lung (C34.1)\tmale\t99.9\n";
    file_put_contents($local_resource_dir."/data/samples.tsv", $content_sample);
    file_put_contents($local_resource_dir."/data/package/samples.tsv", $content_sample);
    $content_sample_error = "alias\ttitle\tcell_line\tphenotype\tsubject_id\tdescription\tbiosample_id\tcase_control\torganism_part\tbiological_sex\tDNA_concentration\nTEST-NSCLC-0846-FIXT-01-DNA-01\tTEST-NSCLC-0846-FIXT-01-DNA-01\t\tIVB:Adenocarcinoma (8140)\tTEST-NSCLC-0846\tFixed tissue. DNA\tTEST-NSCLC-0846-FIXT-01-DNA-01\ttumor\tUpper lobe, lung (C34.1): Regional lymph node metastases\t\t33\nTEST-NSCLC-0846-PXD-01-DNA-01\tTEST-NSCLC-0846-PXD-01-DNA-01\t\tIVB:Adenocarcinoma (8140)\tTEST-NSCLC-0846\tBlood PXD. DNA\tTEST-NSCLC-0846-PXD-01-DNA-01\tgermline\tUpper lobe, lung (C34.1)\tmale\t5.83\nTEST-NSCLC-0915-FIXT-01-DNA-01\tTEST-NSCLC-0915-FIXT-01-DNA-01\t\tIVA:Adenocarcinoma (8140)\tTEST-NSCLC-0915\tFixed tissue. DNA\tTEST-NSCLC-0915-FIXT-01-DNA-01\ttumor\tUpper lobe, lung (C34.1): Regional lymph node metastases\tmale\t51\nTEST-NSCLC-0915-PXD-01-DNA-01\tTEST-NSCLC-0915-PXD-01-DNA-01\t\tIVA:Adenocarcinoma (8140)\tTEST-NSCLC-0915\tBlood PXD. DNA\tTEST-NSCLC-0915-PXD-01-DNA-01\tgermline\tUpper lobe, lung (C34.1)\tmale\t5.81\nTEST-NSCLC-1984-FIXT-01-DNA-01\tTEST-NSCLC-1984-FIXT-01-DNA-01\t\tIVA:Adenocarcinoma (8140)\tTEST-NSCLC-1984\tFixed tissue. DNA\tTEST-NSCLC-1984-FIXT-01-DNA-01\ttumor\tUpper lobe, lung (C34.1): Primary tumor\tfemale\t7.54\nTEST-NSCLC-1984-PXD-01-DNA-01\tTEST-NSCLC-1984-PXD-01-DNA-01\t\tIVA:Adenocarcinoma (8140)\tTEST-NSCLC-1984\tBlood PXD. DNA\tTEST-NSCLC-1984-PXD-01-DNA-01\tgermline\tUpper lobe, lung (C34.1)\tfemale\t127\nTEST-NSCLC-1123-FIXT-01-DNA-01\tTEST-NSCLC-1123-FIXT-01-DNA-01\t\tIVA:Adenocarcinoma (8140)\tTEST-NSCLC-1123\tFixed tissue. DNA\tTEST-NSCLC-1123-FIXT-01-DNA-01\ttumor\tMiddle lobe, lung (C34.2): Distant metastases\tmale\t51.6\nTEST-NSCLC-1123-PXD-01-DNA-01\tTEST-NSCLC-1123-PXD-01-DNA-01\t\tIVA:Adenocarcinoma (8140)\tTEST-NSCLC-1123\tBlood PXD. DNA\tTEST-NSCLC-1123-PXD-01-DNA-01\tgermline\tMiddle lobe, lung (C34.2)\tmale\t175\nTEST-NSCLC-0512-FIXT-01-DNA-01\tTEST-NSCLC-0512-FIXT-01-DNA-01\t\tIVB:Adenocarcinoma (8140)\tTEST-NSCLC-0512\tFixed tissue. DNA\tTEST-NSCLC-0512-FIXT-01-DNA-01\ttumor\tLower lobe, lung (C34.3): Primary tumor\tfemale\t1.37\nTEST-NSCLC-0512-PXD-01-DNA-01\tTEST-NSCLC-0512-PXD-01-DNA-01\t\tIVB:Adenocarcinoma (8140)\tTEST-NSCLC-0512\tBlood PXD. DNA\tTEST-NSCLC-0512-PXD-01-DNA-01\tgermline\tLower lobe, lung (C34.3)\tfemale\t149\nTEST-NSCLC-1201-FIXT-01-DNA-01\tTEST-NSCLC-1201-FIXT-01-DNA-01\t\tIVB:Adenocarcinoma (8140)\tTEST-NSCLC-1201\tFixed tissue. DNA\tTEST-NSCLC-1201-FIXT-01-DNA-01\ttumor\tUpper lobe, lung (C34.1): Distant metastases\tmale\t29.8\nTEST-NSCLC-1201-PXD-02-DNA-01\tTEST-NSCLC-1201-PXD-02-DNA-01\t\tIVB:Adenocarcinoma (8140)\tTEST-NSCLC-1201\tBlood PXD. DNA\tTEST-NSCLC-1201-PXD-02-DNA-01\tgermline\tUpper lobe, lung (C34.1)\tmale\t86.4\nTEST-NSCLC-0337-FIXT-01-DNA-01\tTEST-NSCLC-0337-FIXT-01-DNA-01\t\tIVB:Adenocarcinoma (8140)\tTEST-NSCLC-0337\tFixed tissue. DNA\tTEST-NSCLC-0337-FIXT-01-DNA-01\ttumor\tUpper lobe, lung (C34.1): Distant metastases\tmale\t4.53\nTEST-NSCLC-0337-PXD-01-DNA-01\tTEST-NSCLC-0337-PXD-01-DNA-01\t\tIVB:Adenocarcinoma (8140)\tTEST-NSCLC-0337\tBlood PXD. DNA\tTEST-NSCLC-0337-PXD-01-DNA-01\tgermline\tUpper lobe, lung (C34.1)\tmale\t99.9\n";
    file_put_contents($local_resource_dir."/data/samples_error.tsv", $content_sample_error);

    $content_run = "title\texperiment\tsample\tfiles\tfile_type\trun_date\nrun_TEST-NSCLC-0846-FIXT-01-DNA-01\texperiment_1\tTEST-NSCLC-0846-FIXT-01-DNA-01\tTEST-NSCLC-0846-FIXT-01-DNA-01_e51df8e396ffdace22ba7ee2db929fe3.1.fastq.gz,TEST-NSCLC-0846-FIXT-01-DNA-01_c7645c6e8b7da30def0e69327b8067a6.2.fastq.gz\tfastq\t2023-01-02\nrun_TEST-NSCLC-0846-PXD-01-DNA-01\texperiment_1\tTEST-NSCLC-0846-PXD-01-DNA-01\tTEST-NSCLC-0846-PXD-01-DNA-01_0a0a3da9081f5b79e0f4d938d9c7ff21.1.fastq.gz,TEST-NSCLC-0846-PXD-01-DNA-01_25782b93a4602d1346d47f098821e5df.2.fastq.gz\tfastq\t2023-01-02\nrun_TEST-NSCLC-0915-FIXT-01-DNA-01\texperiment_1\tTEST-NSCLC-0915-FIXT-01-DNA-01\tTEST-NSCLC-0915-FIXT-01-DNA-01_bc4baf54d7898cf12d1da05867f63c2c.1.fastq.gz,TEST-NSCLC-0915-FIXT-01-DNA-01_277ae19dd428e8013337dee396146513.2.fastq.gz\tfastq\t2023-01-02\nrun_TEST-NSCLC-0915-PXD-01-DNA-01\texperiment_1\tTEST-NSCLC-0915-PXD-01-DNA-01\tTEST-NSCLC-0915-PXD-01-DNA-01_3be6020a4d4d695220adc616eeb91189.1.fastq.gz,TEST-NSCLC-0915-PXD-01-DNA-01_235e4ae50043b0881d65aae555a76f92.2.fastq.gz\tfastq\t2023-01-02\nrun_TEST-NSCLC-1984-FIXT-01-DNA-01\texperiment_1\tTEST-NSCLC-1984-FIXT-01-DNA-01\tTEST-NSCLC-1984-FIXT-01-DNA-01_55d7b362509e723cc38b07d585000b60.1.fastq.gz,TEST-NSCLC-1984-FIXT-01-DNA-01_96a724bc7e39646fb297ad145b2bce8a.2.fastq.gz\tfastq\t2023-01-02\nrun_TEST-NSCLC-1984-PXD-01-DNA-01\texperiment_1\tTEST-NSCLC-1984-PXD-01-DNA-01\tTEST-NSCLC-1984-PXD-01-DNA-01_d1c8e8a33ddb7bf11ec787849d3e805e.1.fastq.gz,TEST-NSCLC-1984-PXD-01-DNA-01_127477b6d991c2f09dadb609441b1521.2.fastq.gz\tfastq\t2023-01-02\nrun_TEST-NSCLC-1123-FIXT-01-DNA-01\texperiment_1\tTEST-NSCLC-1123-FIXT-01-DNA-01\tTEST-NSCLC-1123-FIXT-01-DNA-01_2ca40a2616e12e55d8f7a79ae3059156.1.fastq.gz,TEST-NSCLC-1123-FIXT-01-DNA-01_1ccb3f87c6bbc4f558260489ac924446.2.fastq.gz\tfastq\t2023-01-02\nrun_TEST-NSCLC-1123-PXD-01-DNA-01\texperiment_1\tTEST-NSCLC-1123-PXD-01-DNA-01\tTEST-NSCLC-1123-PXD-01-DNA-01_b2a7b31070899fefedc4357a9f0e92c3.1.fastq.gz,TEST-NSCLC-1123-PXD-01-DNA-01_1042aed0ecdc2b5b2b06d6be6bcb5ade.2.fastq.gz\tfastq\t2023-01-02\nrun_TEST-NSCLC-0512-FIXT-01-DNA-01\texperiment_1\tTEST-NSCLC-0512-FIXT-01-DNA-01\tTEST-NSCLC-0512-FIXT-01-DNA-01_79db10c64ea021d0b184b7b7a4715308.1.fastq.gz,TEST-NSCLC-0512-FIXT-01-DNA-01_355e756ff3101581ca4d9b639479ee79.2.fastq.gz\tfastq\t2023-01-02\nrun_TEST-NSCLC-0512-PXD-01-DNA-01\texperiment_1\tTEST-NSCLC-0512-PXD-01-DNA-01\tTEST-NSCLC-0512-PXD-01-DNA-01_73d8ea97e16cfc2fd2732cc299b7e828.1.fastq.gz,TEST-NSCLC-0512-PXD-01-DNA-01_e3e3c10453b36e8a56d8f46d012528c8.2.fastq.gz\tfastq\t2023-01-02\nrun_TEST-NSCLC-1201-FIXT-01-DNA-01\texperiment_1\tTEST-NSCLC-1201-FIXT-01-DNA-01\tTEST-NSCLC-1201-FIXT-01-DNA-01_7d944bc369f29c75c6648d54d8d28de6.1.fastq.gz,TEST-NSCLC-1201-FIXT-01-DNA-01_1247c831c8429e1d787fafe2472821ee.2.fastq.gz\tfastq\t2023-01-02\nrun_TEST-NSCLC-1201-PXD-02-DNA-01\texperiment_1\tTEST-NSCLC-1201-PXD-02-DNA-01\tTEST-NSCLC-1201-PXD-02-DNA-01_367730fa6d1449ec5d4ebbafaa471c7d.1.fastq.gz,TEST-NSCLC-1201-PXD-02-DNA-01_03f16e82c526396d586db58a5fb75ae2.2.fastq.gz\tfastq\t2023-01-02\nrun_TEST-NSCLC-0337-FIXT-01-DNA-01\texperiment_1\tTEST-NSCLC-0337-FIXT-01-DNA-01\tTEST-NSCLC-0337-FIXT-01-DNA-01_bfee2383e2cf9cf11e4a5faeb93132da.1.fastq.gz,TEST-NSCLC-0337-FIXT-01-DNA-01_ccf85c675c3aa6248928e1b8cab2b138.2.fastq.gz\tfastq\t2023-01-02\nrun_TEST-NSCLC-0337-PXD-01-DNA-01\texperiment_1\tTEST-NSCLC-0337-PXD-01-DNA-01\tTEST-NSCLC-0337-PXD-01-DNA-01_b8b5aea7dad76600c93233821f9a827f.1.fastq.gz,TEST-NSCLC-0337-PXD-01-DNA-01_18eeec6c4293e0ffa2d47dd46c69fa3a.2.fastq.gz\tfastq\t2023-01-02";
    file_put_contents($local_resource_dir."/data/package/runs.tsv", $content_run);
    $content_exp = "title\tlibrary_layout\tlibrary_source\tlibrary_strategy\tlibrary_selection\tdesign_description\tinstrument_model_id\nexperiment_1\tPAIRED\tGENOMIC\tAMPLICON\tChIP\ttest\tABI_SOLID: AB SOLiD 4 System";
    file_put_contents($local_resource_dir."/data/package/experiments.tsv", $content_exp);
    $content_ana = "files\ttitle\tplatform\tgenome_id\tdescription\tanalysis_type\texperiment_types\tsamples\texperiments\nTEST-NSCLC-0846-FIXT-01-DNA-01_vs_TEST-NSCLC-0846-PXD-01-DNA-01_00b6a80667dd59bc4dad62b2e96829b9.vcf\tVariant Calling for TEST-NSCLC-0846\tIllumina\tGRCh38.p14\tVariant calling\tSEQUENCE VARIATION\tGenotyping by sequencing\tTEST-NSCLC-0846-PXD-01-DNA-01,TEST-NSCLC-0846-FIXT-01-DNA-01\texperiment_1\nTEST-NSCLC-0915-FIXT-01-DNA-01_vs_TEST-NSCLC-0915-PXD-01-DNA-01_5b1c8c2e263a2d1fd0fad2f77847eb5c.vcf\tVariant Calling for TEST-NSCLC-0915\tIllumina\tGRCh38.p14\tVariant calling\tSEQUENCE VARIATION\tGenotyping by sequencing\tTEST-NSCLC-0915-PXD-01-DNA-01,TEST-NSCLC-0915-FIXT-01-DNA-01\texperiment_1\nTEST-NSCLC-1984-FIXT-01-DNA-01_vs_TEST-NSCLC-1984-PXD-01-DNA-01_ce337898d44aa58a046599affdcc9366.vcf\tVariant Calling for TEST-NSCLC-1984\tIllumina\tGRCh38.p14\tVariant calling\tSEQUENCE VARIATION\tGenotyping by sequencing\tTEST-NSCLC-1984-PXD-01-DNA-01,TEST-NSCLC-1984-FIXT-01-DNA-01\texperiment_1\nTEST-NSCLC-1123-FIXT-01-DNA-01_vs_TEST-NSCLC-1123-PXD-01-DNA-01_2b6b715acfe56dfba00ff87fc274cab1.vcf\tVariant Calling for TEST-NSCLC-1123\tIllumina\tGRCh38.p14\tVariant calling\tSEQUENCE VARIATION\tGenotyping by sequencing\tTEST-NSCLC-1123-PXD-01-DNA-01,TEST-NSCLC-1123-FIXT-01-DNA-01\texperiment_1\nTEST-NSCLC-0512-FIXT-01-DNA-01_vs_TEST-NSCLC-0512-PXD-01-DNA-01_d0d5cd008d5640381959bea8e5b59c8d.vcf\tVariant Calling for TEST-NSCLC-0512\tIllumina\tGRCh38.p14\tVariant calling\tSEQUENCE VARIATION\tGenotyping by sequencing\tTEST-NSCLC-0512-PXD-01-DNA-01,TEST-NSCLC-0512-FIXT-01-DNA-01\texperiment_1\nTEST-NSCLC-1201-FIXT-01-DNA-01_vs_TEST-NSCLC-1201-PXD-02-DNA-01_1bc1517effb7dfc5ec3dbb0af9729c70.vcf\tVariant Calling for TEST-NSCLC-1201\tIllumina\tGRCh38.p14\tVariant calling\tSEQUENCE VARIATION\tGenotyping by sequencing\tTEST-NSCLC-1201-PXD-02-DNA-01,TEST-NSCLC-1201-FIXT-02-DNA-01\texperiment_1\nTEST-NSCLC-0337-FIXT-01-DNA-01_vs_TEST-NSCLC-0337-PXD-01-DNA-01_476c1ac473cebe81fb171b606fa8047a.vcf\tVariant Calling for TEST-NSCLC-0337\tIllumina\tGRCh38.p14\tVariant calling\tSEQUENCE VARIATION\tGenotyping by sequencing\tTEST-NSCLC-0337-PXD-01-DNA-01,TEST-NSCLC-0337-FIXT-01-DNA-01\texperiment_1\n";
    file_put_contents($local_resource_dir."/data/package/analyses.tsv", $content_ana);
    $content_data = "title\tdescription\tdataset_types\truns\tanalyses\nDataset 1\ttest\tWhole genome sequencing;Genotyping by array\trun_TEST-NSCLC-0846-FIXT-01-DNA-01;run_TEST-NSCLC-0846-PXD-01-DNA-01;run_TEST-NSCLC-0915-FIXT-01-DNA-01;run_TEST-NSCLC-0915-PXD-01-DNA-01\tVariant Calling for TEST-NSCLC-0846;Variant Calling for TEST-NSCLC-0915";
    file_put_contents($local_resource_dir."/data/package/datasets.tsv", $content_data);
    $content_study = "status\tcreation_date\tlast_update\tpublished_date\tcreator_name\ttitle\tstudy_type\ndraft\t2024-11-08 08:46:59.717871\t2024-11-08 08:46:59.717871\t\tLou Gotz\ttest new\ttest";
    file_put_contents($local_resource_dir."/data/package/study.tsv", $content_study);
    $content_manifest = "Study\tstudy.tsv\nSample\tsamples.tsv\nMolecularExperiment\texperiments.tsv\nMolecularRun\truns.tsv\nMolecularAnalysis\tanalyses.tsv\nDataset\tdatasets.tsv";
    file_put_contents($local_resource_dir."/data/package/manifest.tsv", $content_manifest);
    $cmd = "zip $local_resource_dir/data/package.zip $local_resource_dir/data/package";
    exec($cmd);
}

function writeResourceTypeJson($local_resource_dir){
    $schema_dir = $local_resource_dir."/schemas";
    if(!file_exists($schema_dir)){
        mkdir($schema_dir,0770);
    }
    $resource_types = DB::query("SELECT id, name, properties from resource_type order by name");
    $list = '';
    foreach($resource_types as $ridx=>$r){
        if ($ridx) {
            $list .=',';
        }
        $list .= $r['name'];
        file_put_contents($schema_dir.'/'.$r['name'].".json",$r['properties'],FILE_APPEND);
    }
    
    $resource_types = listResourceTypes();
    
    file_put_contents($local_resource_dir."/data/resource_type_list.txt",implode(",",$resource_types));
    
}

function main()
{

    $local_resource_dir = 'local_version';

    $cmd = "rm -rf $local_resource_dir; mkdir $local_resource_dir; mkdir $local_resource_dir/vendor; mkdir $local_resource_dir/data; mkdir $local_resource_dir/data/template";
    $cmd .= ";cp import_resources.php $local_resource_dir";
    $cmd .= ";cp import_study.php $local_resource_dir";
    // $cmd .= ";cp composer.json $local_resource_dir";
    // $cmd .= ";cp vendor/autoload.php $local_resource_dir/vendor/";
    // $cmd .= ";cp -r vendor/ $local_resource_dir/vendor/";
    // $cmd .= ";cp -r vendor/composer $local_resource_dir/vendor/";
    // $cmd .= ";cp -r vendor/symfony $local_resource_dir/vendor/";
    // $cmd .= ";cp -r vendor/ramsey $local_resource_dir/vendor/";
    // $cmd .= ";cp -r vendor/bin $local_resource_dir/vendor/;";
    exec($cmd);
    writeResourceTypeJson($local_resource_dir);
    writeExample($local_resource_dir);
    writeTemplate($local_resource_dir);

    $composer_content = '{ "require": { "justinrainbow/json-schema": "^6.0"} } ';
    // $composer_content = '{ "require": { "justinrainbow/json-schema": "^6.0", "ramsey/uuid": "^4.7" } } ';
    file_put_contents($local_resource_dir.'/composer.json', $composer_content, FILE_APPEND);

    $readme_content = "require composer, php.\r\n
        1. run 'composer update' to create vendor libraries\r\n
        2. run 'php import_resources.php or import_study.php '\r\nYou can use the files in data directory as example.\r\nExample : php import_resources.php --validate -f data/samples.tsv -t Sample -o tsv\r\nExample : php import_study.php --validate -f data/package.zip \r\nYou can use template in data directory to create your metadata file. ";
    file_put_contents($local_resource_dir.'/README', $readme_content, FILE_APPEND);

    $include_content = "<?php require 'vendor/autoload.php'; ?>";
    file_put_contents($local_resource_dir.'/include.php', $include_content, FILE_APPEND);

    exec('zip -r local_import_resource.zip "local_version"');
    // unlink("local_version");

}
