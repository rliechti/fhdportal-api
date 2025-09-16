<?php

use App\Service\Auth\Keycloak;
use App\Service\JsonSchema\Validator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Process\Process;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpFoundation\StreamedResponse;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\IOFactory;
use App\Service\RabbitMq\RabbitMq;

if (!function_exists("checkUuid")){
    function checkUuid($str)
    {
        return (is_string($str) && preg_match("/^[0-9A-F]{8}-[0-9A-F]{4}-4[0-9A-F]{3}-[89AB][0-9A-F]{3}-[0-9A-F]{12}$/i", $str));
    }
}

function listResourceTypes()
{
    /*
        TODO code not working anymore
    */
    //
    // $type_order = DB::query("SELECT relationship_rule_view.* from relationship_rule_view inner join resource_type on resource_type.id = relationship_rule_view.domain_type_id and resource_type.validator_mandatory = true");
    // $domains = array_values(array_unique(array_map(function ($d) {return $d['domain_type_name'];}, $type_order)));
    // $ranges = array_values(array_unique(array_map(function ($d) {return $d['range_type_name'];}, $type_order)));
    // $resource_order = array_values(array_diff($ranges, $domains));
    //
    // $idx = 0;
    // while ($idx < count($type_order) - 1) {
    //     $idx++;
    //     $resource_order = getResourceOrder_sub($type_order, $resource_order);
    // }
    return array('Study','Sample','MolecularExperiment','MolecularRun','MolecularAnalysis','Dataset');
}

function getResourceOrder_sub($type_order, $new_order)
{
    foreach ($type_order as $t) {
        $ok = true;
        if (!in_array($t['domain_type_name'], $new_order)) {
            $c = $t['domain_type_name'];
            foreach ($type_order as $t2) {
                if (!in_array($t2['domain_type_name'], $new_order) && $c == $t2['range_type_name']) {
                    $ok = false;
                }
            }
            if ($ok) {
                $new_order[] = $c;
            }
        }
    }
    return $new_order;
}

function createDirectory($destination, $writable = false)
{
    if (!file_exists($destination)) {
        mkdir($destination, 0770, true);
        if (!file_exists($destination)) {
            return new JsonResponse($destination." does not exist", 400);
        }
    }
    if ($writable && !is_writable($destination)) {
        chmod($destination, 0777);
        if (!is_writable($destination)) {
            return new JsonResponse($destination." does not exist", 400);
        }
    }
    return null;
}

function downloadTemplate($auth, $resource_type, $project_dir, $format)
{
    if ($auth->isGuest() || !$auth->hasRole('submitter')) {
        throw new Exception("Unauthorized", 401);
    }
    $filename = "template_$resource_type.".$format;
    if ($format == 'xlsx'){
        header('Content-disposition: attachment; filename="'.$filename.'"');
        header("Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet");
        header('Content-Transfer-Encoding: binary');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');        
    }

    $template_dir = $project_dir.'/data/template/';
    createDirectory($template_dir, true);
    $filepath = $template_dir . "/".$filename;

    $json = DB::queryFirstField("SELECT properties from resource_type where name = %s", $resource_type);
    if (!$json) {
        throw new Exception("Unknown schemas", 500);
    }

    $schemas = json_decode($json,true);
    $required=$schemas['data_schema']['required'];
    
    $styleArrayRequired = [
        'font' => [
            'bold' => true,
            'color' => [
                'argb' => \PhpOffice\PhpSpreadsheet\Style\Color::COLOR_RED
            ]
        ],
        'alignment' => [
            'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT
        ]
    ];

    $styleArray = [
        'font' => [
            'bold' => true
        ],
        'alignment' => [
            'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT
        ]
    ];

    $styleArrayBorder = [
        'font' => [
          'italic' => true,
          'color' => [
              'argb' => '555555'
          ]  
        ],
        'borders' => [
            'bottom' => [
                'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN
            ]
        ]    
    ];

    $spreadsheet = new Spreadsheet();
    $activeWorksheet = $spreadsheet->getActiveSheet();
    $activeWorksheet->setTitle(preg_replace("/([A-Z])/"," $1",$resource_type));
    $vocabulary = $spreadsheet->createSheet();
    $vocabulary->setTitle("Vocabulary");
    $vocabulary->setSheetState(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet::SHEETSTATE_HIDDEN);
    $activeWorksheet = $spreadsheet->setActiveSheetIndexByName(preg_replace("/([A-Z])/"," $1",$resource_type)); 
    $activeWorksheet->getDefaultColumnDimension()->setWidth(20);
    $activeWorksheet->getDefaultRowDimension()->setRowHeight(15);
    if ($format == 'xlsx'){
        $activeWorksheet->setCellValue('B1', 'Required fields');
        $activeWorksheet->getStyle('B1')->applyFromArray($styleArrayRequired);
        $activeWorksheet->setCellValue('B2', 'extra_attributes: replace this column title with the attribute title. Multiple extra columns can be added');        
    }
    $colidx = 0;
    $rowIdx = ($format == 'csv') ? 1 : 3;
    foreach($schemas['data_schema']['properties'] as $pt => $p){
        if ($pt == 'public_id' || !in_array($pt,$required)) {
            continue;
        }
        $col = chr(65+$colidx);
        $activeWorksheet->setCellValue($col.$rowIdx, $pt);
        if ($format == 'csv') {
            $colidx++;
            continue;
        }
        $activeWorksheet->getStyle($col."3")->applyFromArray($styleArrayRequired);
        if (isset($p['enum'])) {
            $desc = 'from list';
            $activeWorksheet = $spreadsheet->setActiveSheetIndexByName('Vocabulary');
            $counter = count($p['enum']);
            for($v = 1; $v <= $counter; $v++){
                $activeWorksheet->setCellValue($col."".$v, $p['enum'][($v-1)]);                
            }
            $activeWorksheet = $spreadsheet->setActiveSheetIndexByName(preg_replace("/([A-Z])/"," $1",$resource_type));
            $dv = new DataValidation();
            $dv->setType(DataValidation::TYPE_NONE);
            $activeWorksheet->setDataValidation($col."1:".$col."4", $dv);
            $dv = new DataValidation();
            $dv->setType( \PhpOffice\PhpSpreadsheet\Cell\DataValidation::TYPE_LIST );
            $dv->setErrorStyle( \PhpOffice\PhpSpreadsheet\Cell\DataValidation::STYLE_INFORMATION );
            $dv->setAllowBlank(false);
            $dv->setShowInputMessage(true);
            $dv->setShowErrorMessage(true);
            $dv->setShowDropDown(true);
            $dv->setErrorTitle('Input error');
            $dv->setError('Value is not in list.');
            $dv->setPromptTitle('Pick from list');
            $dv->setPrompt('Please pick a value from the drop-down list.');
            $dv->setFormula1('\'Vocabulary\'!$'.$col.'$1:$'.$col.'$'.count($p['enum']));
            $activeWorksheet->setDataValidation($col.':'.$col, $dv);
        } elseif (isset($p['type'])) {
            $desc = ($p['type']==='array') ? "values separated by ;" : $p['type'];
        }
        $activeWorksheet->setCellValue($col."4", $desc);
        $activeWorksheet->getStyle($col."4")->applyFromArray($styleArrayBorder);    
        $colidx++;
    }
    foreach($schemas['data_schema']['properties'] as $pt => $p){
        if ($pt == 'public_id' || in_array($pt,$required)) {
            continue;
        }
        $col = chr(65+$colidx);
        $activeWorksheet->setCellValue($col.$rowIdx, $pt);
        if ($format == 'csv') {
            $colidx++;
            continue;
        }
        $activeWorksheet->getStyle($col."3")->applyFromArray($styleArray);
        if ($pt == 'extra_attributes') {
            $desc = "rename title";
        } elseif (isset($p['enum'])) {
            $desc = 'from list';
            $dv = new DataValidation();
            $dv->setType(DataValidation::TYPE_NONE);
            $activeWorksheet->setDataValidation($col."1:".$col."4", $dv);
            $dv = new DataValidation();
            $dv->setType( \PhpOffice\PhpSpreadsheet\Cell\DataValidation::TYPE_LIST );
            $dv->setErrorStyle( \PhpOffice\PhpSpreadsheet\Cell\DataValidation::STYLE_INFORMATION );
            $dv->setAllowBlank(false);
            $dv->setShowInputMessage(true);
            $dv->setShowErrorMessage(true);
            $dv->setShowDropDown(true);
            $dv->setErrorTitle('Input error');
            $dv->setError('Value is not in list.');
            $dv->setPromptTitle('Pick from list');
            $dv->setPrompt('Please pick a value from the drop-down list.');
            $dv->setFormula1('"'.implode(",",$p['enum']).'"');
            $activeWorksheet->setDataValidation($col.':'.$col, $dv);
        } elseif (isset($p['type'])) {
            $desc = ($p['type']==='array') ? "values separated by ;" : $p['type'];
        }
        $activeWorksheet->setCellValue($col."4", $desc);
        $activeWorksheet->getStyle($col."4")->applyFromArray($styleArrayBorder);    

        $colidx++;
    }        
    if ($format == 'xlsx'){
        $writer = new Xlsx($spreadsheet);
    }
    else if ($format == 'csv'){
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Csv($spreadsheet);
        $writer->setDelimiter("\t");
        $writer->setEnclosure('"');
        $writer->setLineEnding("\n");
        $writer->setSheetIndex(0);
    }


    $writer->save($filepath);
    return $filepath;

}

function downloadSubmission($auth, $study_id, $project_dir)
{

    if ($auth->isGuest() || !$auth->hasRole('submitter')) {
        throw new Exception("Unauthorized", 401);
    }
    $user = $auth->getDetails();

    $study = DB::queryFirstRow("SELECT * from study_view where public_id = %s", $study_id);
    $permission = DB::queryFirstField("SELECT resource_id from resource_user_view where resource_id = %s and user_id = %i", $study['id'], $user['id']);
    if (!$permission) {
        throw new Exception("Unauthorized", 401);
    }
    require_once dirname(__DIR__).'/../vendor/mk-j/php_xlsxwriter/xlsxwriter.class.php';
    $filename = "study_$study_id.xlsx";
    // $filename = "study_$study_id".".xlsx";
    // $filename = "study_$study_id"."_".date('Ymd_His').".xlsx";
    header('Content-disposition: attachment; filename="'.XLSXWriter::sanitize_filename($filename).'"');
    header("Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet");
    header('Content-Transfer-Encoding: binary');
    header('Cache-Control: must-revalidate');
    header('Pragma: public');

    $study_dir = $project_dir.'/data/studies/'.$study_id."/";
    createDirectory($study_dir, true);
    $download_dir = $study_dir . "download/";
    createDirectory($download_dir, true);
    $filepath = $study_dir . "/".$filename;

    $study_data = [];
    $study_header = array();
    $tmp_study_header = ['public_id','status','creation_date','last_update','released_date','creator_name'];
    foreach ($tmp_study_header as $d) {
        $study_header[$d] = 'string';
        $study_data[] = $study[$d];
    }
    $props = json_decode($study['properties'], true);
    foreach ($props as $key => $prop) {
        if ($key == 'public_id') {
            continue;
        }
        $study_header[$key] = 'string';
        $study_data[] = $prop;
    }

    $writer = new XLSXWriter();
    $writer->writeSheetHeader('Study', $study_header);
    $writer->writeSheetRow('Study', $study_data);

    $relations = DB::query("SELECT * from relationship inner join relationship_rule on relationship_rule.id = relationship.relationship_rule_id inner join resource_type on resource_type.id =
relationship_rule.domain_type_id where range_resource_id = %s", $study['id']);
    $relation_types = [];
    foreach ($relations as $relation) {

        $res_data = [];
        $resource = DB::queryFirstRow("SELECT * from resource where id = %s and status_type_id != 'DEL'", $relation['domain_resource_id']);
        if (!$resource) {
            continue;
        }
        $props = json_decode($resource['properties'], true);
        if (!$props) {
            continue;
        }

        if (!in_array($relation['relationship_rule_id'], $relation_types)) {
            $relation_types[] = $relation['relationship_rule_id'];
            $rel_type_header = [];
            foreach ($props as $key => $prop) {
                $rel_type_header[$key] = 'string';
            }
            $writer->writeSheetHeader($relation['name'], $rel_type_header);
        }
        foreach ($props as $key => $prop) {
            $prop_type = gettype($prop);
            if ($prop_type === 'string') {
                $res_data[] = $prop;
            } elseif ($prop_type === 'array') {
                $concat_data = array();
                foreach ($prop as $p) {
                    $pptype = gettype($p);
                    if ($pptype === 'string') {
                        $concat_data[] = $p;
                    } elseif ($pptype === 'array') {
                        foreach ($p as $key => $pp) {
                            $concat_data[] = $key . ":".$pp;
                        }
                    }
                }
                $res_data[] = implode(";", $concat_data);
            }
        }
        $writer->writeSheetRow($relation['name'], $res_data);
    }

    $writer->writeToFile($filepath);
    $dated_filename = "study_$study_id"."_".date('Ymd_His').".xlsx";
    copy($filepath, $download_dir .$dated_filename);

    return $filepath;
}

function patchResource($resource_id, $patch, $auth)
{
    if ($auth->isGuest() || (!$auth->hasRole('submitter') && !$auth->isDacCli())) {
        throw new Exception("Unauthorized", 401);
    }
    $field = (checkUuid($resource_id)) ? "id" : "resource.properties ->> 'public_id'";
    $resource = DB::queryFirstRow("SELECT *, resource.properties ->> 'public_id' as public_id from resource where ".$field." = %s", $resource_id);
    if (!$resource){
        throw new Exception("Error: unknwon resource", 500);
    }
    
    $isDacMember = $auth->checkDacMember($resource_id);
    if ($auth->isDacCli() || $isDacMember){
        $study_id = DB::queryFirstField("SELECT range_resource_id as study_id from relationship_view where domain_resource_id = %s and range_type = 'Study'",$resource['id']);
        $policy_id = '';
        if (isset($patch['policy_public_id'])) {
            $policy_id = DB::queryFirstField("SELECT id from resource where resource.properties ->> 'public_id'::text = %s", $patch['policy_public_id']);
        } elseif (isset($patch['policy_id']) && checkUuid($patch['policy_id'])) {
            $policy_id = DB::queryFirstField("SELECT id from resource where resource.id = %s", $patch['policy_id']);
        }
        if ($policy_id && isset($patch['policy_status'])){
            $is_active = (strpos(trim($patch['policy_status']),'valid') === 0);
            $relationship_id = DB::queryFirstField("SELECT id from relationship where domain_resource_id = %s and range_resource_id = %s",$resource['id'],$policy_id);
            if (!$relationship_id){
                throw new Exception("Error: this policy was not linked to this dataaset", 500);
            }
            if ($patch['policy_status'] == 'reject'){
                DB::delete("relationship","id = %s",$relationship_id);
                // update dataset status //
                setResourceStatus($auth, $resource_id, "REV");
                if ($study_id){
                    setResourceStatus($auth, $study_id, "REV");
                }
            }
            else{
                DB::update("relationship",array("is_active" => $is_active),"id = %s",$relationship_id);    
                // update dataset status //
                setResourceStatus($auth, $resource_id, "PUB");
                $action_type_id = "PUB";
                $uuid = Uuid::uuid4();
                $log_id = $uuid->toString();
                $log = array(
                    "id" => $log_id,
                    "resource_id" => $resource['id'],
                    "user_id" => NULL,
                    "action_type_id" => $action_type_id,
                    "properties" => $resource['properties']
                );
                DB::insert("resource_log", $log);        
                
                // update study status //
                $study_dataset_status = DB::queryFirstColumn("SELECT
                        datasets.status_type_id
                    FROM
                    resource AS datasets
                    INNER JOIN relationship_view ON datasets.id = relationship_view.domain_resource_id and relationship_view.domain_type = 'Dataset' and relationship_view.range_type = 'Study'
                    WHERE relationship_view.range_resource_id = %s",$study_id
                );
                $all_study_datasets_are_pub = true;
                foreach($study_dataset_status as $s){
                    if ($s != 'PUB'){
                        $all_study_datasets_are_pub = false;
                    }
                }
                if ($all_study_datasets_are_pub){
                    setResourceStatus($auth, $study_id, "PUB");
                    $study_properties = DB::queryFirstField("SELECT properties from resource where id = %s",$study_id);
                    $action_type_id = "PUB";
                    $uuid = Uuid::uuid4();
                    $log_id = $uuid->toString();
                    $log = array(
                        "id" => $log_id,
                        "resource_id" => $study_id,
                        "user_id" => NULL,
                        "action_type_id" => $action_type_id,
                        "properties" => $study_properties
                    );
                    DB::insert("resource_log", $log);        
                }
                // send release RMQ message
                $rabbitmq = new \App\Service\RabbitMq\RabbitMq;
                $email = DB::queryFirstField("SELECT \"user\".email FROM resource_acl INNER JOIN \"user\" ON resource_acl.user_id= \"user\".id WHERE resource_acl.resource_id=%s AND resource_acl.role_id='OWN';",$resource['id']);

                // TODO: replace time() by dataset publication_date
                $rabbitmq->releaseDataset($resource['public_id'],$email,time());
                
            }            
        }
    }
    else{
        $user = $auth->getDetails();
        $studyPrefix = DB::queryFirstField("SELECT public_id_prefix from resource_type where name = 'Study'");
        if (!$studyPrefix) {
            throw new Exception("Error: unable to guess public_id_prefix for Study", 500);
        }
        $updates = array();
        foreach ($patch as $k => $v) {
            if (isset($resource[$k])) {
                $updates[$k] = $v;
                $resource[$k] = $v;
            }
        }
        if ($updates !== []) {
            DB::update("resource", $updates, "id = %s", $resource['id']);
        }
        if (isset($updates['status_type_id'])){
            setResourceStatus($auth, $resource_id, $updates['status_type_id']);
        }
        $action_type_id = (isset($updates['status_type_id'])) ? $updates['status_type_id'] : "MOD";
        $db_action_type = DB::queryFirstField("SELECT id from action_type where id = %s", $action_type_id);
        if (!$db_action_type) {
            $action_type_id = "MOD";
        }
        $uuid = Uuid::uuid4();
        $log_id = $uuid->toString();
        $log = array(
            "id" => $log_id,
            "resource_id" => $resource['id'],
            "user_id" => $user['id'],
            "action_type_id" => $action_type_id,
            "properties" => $resource['properties']
        );
        DB::insert("resource_log", $log);        
    }
    return true;
}

function convertToTsv($inputFileName, $resource_type){
    if (!file_exists($inputFileName) || !is_readable($inputFileName)){
        throw new Exception("Error: ".$inputFileName." cannot be read", 500);
    }
    $json = DB::queryFirstField("SELECT properties from resource_type where name = %s", $resource_type);
    if (!$json) {
        throw new Exception("Unknown schemas", 500);
    }

    $schemas = json_decode($json,true);
    $required=$schemas['data_schema']['required'];
    
    $tsvFileName = dirname($inputFileName)."/".str_replace(".xlsx",".tsv",basename($inputFileName));
    $fp = fopen($tsvFileName, 'w');
    $spreadsheet = IOFactory::load($inputFileName);
    $sheetData = $spreadsheet->getActiveSheet()->toArray(null, true, true, false);
    $headerIdx = -1;
    $headers = array();
    foreach($sheetData as $rowIdx => $cols){
        if (!$cols[0]){
            continue;
        }
        
        // identify header row //
        if ($headerIdx == -1) {
            $checks = array();
            foreach($required as $r){
                $checks[$r] = false;
            }
            foreach($cols as $col){
                if (isset($checks[$col])){
                    $checks[$col] = true;
                }
            }
            $found = true;
            foreach($checks as $check){
                if (!$check){
                    $found = false;
                }
            }
            if ($found){
                $headerIdx = $rowIdx;
                $headers = $cols;
                fputcsv($fp, $cols, "\t", '"', '');
            }
        } elseif ($rowIdx === ($headerIdx+1) && in_array($cols[0],array("string","from list","rename title"))) {
            continue;
        } elseif (count($headers) === count($cols)) {
            fputcsv($fp, $cols, "\t", '"', '');
        }
    }
    if ($headerIdx === -1){
        throw new Exception("Error: could not find header row. Check template format", 500);
    }
    fclose($fp);
    if (!file_exists($tsvFileName)){
        throw new Exception("Error: unable to convert to tsv file", 500);
    }
    return $tsvFileName;
}

function importResource($tools_path, $study_id, $filepath, $email, $resource_type)
{
    $file_extension = pathinfo($filepath,PATHINFO_EXTENSION);
    $mime_type = mime_content_type($filepath);
    if ($file_extension === 'xlsx' || $mime_type === 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' || $file_extension === 'xls'){
        $filepath = convertToTsv($filepath,$resource_type);
    }
    if ($_SERVER['HTTP_HOST'] == "localhost:8082") {
        $currentDir = getcwd();
        chdir($tools_path);
        $cmd = "php ".$tools_path."/import_resources.php -s $study_id -f \"$filepath\" -u ".$email." -t $resource_type";
        $process = new Process(["php",$tools_path.'/import_resources.php', '-s',$study_id,'-f',$filepath,'-u',$email,'-t',$resource_type], null, $_SERVER);
        $process->run();
        chdir($currentDir);
        // executes after the command finishes
        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }
        $output = $process->getOutput();
    } else {

        $cmd = "php ".$tools_path."/import_resources.php -s $study_id -f \"$filepath\" -u ".$email." -t $resource_type";
        $output = exec($cmd, $outputs, $status);
        if ($status !== 0) {
            throw new Exception("Error running import_resources", 500);
        }
    }

    return $output;
}

function getSchemaAndValidate($resource_type_id, $properties, $validator)
{
    $json_schemas = DB::queryFirstField("SELECT properties from resource_type where id = %i", $resource_type_id);
    if (!$json_schemas) {
        throw new Exception("Unknown schemas", 500);
    }
    $schemas = json_decode($json_schemas);
    if (!isset($schemas->data_schema)) {
        throw new Exception("Unknown data_schema", 500);
    }

    // Validate the data against the schema
    $validationErrors = $validator->validate((object) $properties, $schemas->data_schema);
    if (!empty($validationErrors)) {
        $message = implode(". ", array_map(function ($v) {return $v['message'];}, $validationErrors));
        throw new Exception($message, 400);
    }
}


function listResources(Keycloak $auth, string $resource_type, ?string $parent_id, string $permission, ?string $status = null)
{
    $status = ($status) ? explode(",",$status) : null;
    if ($auth->isGuest()) {
        throw new Exception("Unauthorized", 401);
    }
    $user = $auth->getDetails();
    $join = '';
    
    $params = array('resource_type' => $resource_type,'user_id' => $user['id'],'permission' => $permission);
    $where = "where resource_user_view.resource_type_name like %ss_resource_type and resource_user_view.user_id = %i_user_id and resource_user_view.permissions like %ss_permission and resource_user_view.status_type_id <> 'DEL'";
    if ($auth->isDacCli()) {
        $where = "";
    }
    if ($parent_id) {
        $join = "inner join relationship_view as relationship on relationship.domain_resource_id = resource_user_view.resource_id ";
        $where .= " and relationship.range_public_id = %s_parent_id";
        $params['parent_id'] = $parent_id;
    }
    $resource_ids = DB::queryFirstColumn("SELECT resource_id from resource_user_view $join $where", $params);
    if ($permission == 'read'){
        $public_ids = DB::queryFirstColumn("SELECT id FROM resource_view WHERE resource_view.resource_type LIKE %ss AND resource_view.status_type_id='PUB'",$resource_type);
        foreach($public_ids as $public_id){
            $resource_ids[] = $public_id;
        }
    }
    if (!$resource_ids) {
        return array();
    }
    $db_tables = DB::tableList();
    $db_view = strtolower($resource_type)."_view";
    if (!in_array($db_view, $db_tables)) {
        $db_view = 'resource';
    }
    $params = array(
        "status" => $status,
        "ids" => $resource_ids
    );

    $where = '';
    if ($status && is_array($status) && count($status)) {
        if ($status[0] === 'own') {
            $params['status'] = $user['id'];
            $where = ' and creator_id in %ls_status';
        } else {
            $where = ' and status in %ls_status';
        }
    }
    
    if (!$auth->isDacCli()){
        $where .= " and status_type_id <> 'DEL' ";
    }
    
    $resources =  DB::query("SELECT * from ".$db_view." where id in %ls_ids $where", $params);
    if (is_array($resources)) {
        $resources = array_map(function ($s) use ($user) {
            $s['access'] = DB::query("SELECT username, permissions, user_id, role,role_id from resource_user_view where resource_id = %s", $s['id']);
            $s['properties'] = json_decode($s['properties']);
            $s['current_permission'] = DB::queryFirstField("SELECT permissions from resource_user_view where resource_id = %s and user_id = %i", $s['id'], $user['id']);
            return $s;
        }, $resources);
    }
    return $resources;
}

function listChildrenResources(Keycloak $auth, string $resource_type, ?string $parent_id, string $permission, ?string $status = null)
{

    if ($auth->isGuest()) {
        throw new Exception("Unauthorized", 401);
    }
    $user = $auth->getDetails();
    $join = '';
    
    $params = array('resource_type' => $resource_type,'user_id' => $user['id'],'permission' => $permission);
    $where = "where resource_user_view.resource_type_name like %ss_resource_type and resource_user_view.user_id = %i_user_id and resource_user_view.permissions like %ss_permission and resource_user_view.status_type_id <> 'DEL'";
    if ($parent_id) {
        $join = "inner join relationship_view as relationship on relationship.range_resource_id = resource_user_view.resource_id ";
        $where .= " and relationship.domain_public_id = %s_parent_id";
        $params['parent_id'] = $parent_id;
    }
    $resource_ids = DB::queryFirstColumn("SELECT resource_id from resource_user_view $join $where", $params);
    
    if (!$resource_ids) {
        return array();
    }
    $db_tables = DB::tableList();
    $db_view = strtolower($resource_type)."_view";
    if (!in_array($db_view, $db_tables)) {
        $db_view = 'resource';
    }
    $params = array(
        "status" => $status,
        "ids" => $resource_ids
    );

    $where = '';
    if ($status) {
        if ($status === 'own') {
            $params['status'] = $user['id'];
            $where = ' and creator_id = %s_status';
        } else {
            $where = ' and status = %s_status';
        }
    }
    $resources =  DB::query("SELECT * from ".$db_view." where status_type_id <> 'DEL' and id in %ls_ids $where", $params);
    if (is_array($resources)) {
        $resources = array_map(function ($s) use ($user) {
            $s['access'] = DB::query("SELECT username, permissions, user_id, role,role_id from resource_user_view where resource_id = %s", $s['id']);
            $s['properties'] = json_decode($s['properties']);
            $s['current_permission'] = DB::queryFirstField("SELECT permissions from resource_user_view where resource_id = %s and user_id = %i", $s['id'], $user['id']);
            return $s;
        }, $resources);
    }
    return $resources;
}


function getResource(Keycloak $auth, string $resource_type, string $resource_id, string $permission, ?string $status = null)
{
    $status = ($status) ? explode(",",$status) : null;
    $user = $auth->getDetails();
    $isDacCli = $auth->isDacCli();
    $resource_prefix = DB::queryFirstField("SELECT public_id_prefix from resource_type where name = %s", $resource_type);
    if ($resource_prefix) {
        $field =    (strtolower(substr($resource_id, 0, strlen($resource_prefix))) === strtolower($resource_prefix)) ? "public_id" : "id";
    } else {
        $field = "id";
    }
    $db_tables = DB::tableList();
    $db_view = strtolower($resource_type)."_view";
    if (!in_array($db_view, $db_tables)) {
        $db_view = 'resource';
    }
    $params = array("resource_id" => $resource_id);
    $where = '';
    if ($status && is_array($status) && count($status)) {
        if ($status[0] === 'own') {
            $params['status'] = $user['id'];
            $where = ' and creator_id in %ls_status';
        } else {
            $where = ' and status in %ls_status';
            $params['status'] = $status;
        }
    }

    $resource = DB::queryFirstRow("SELECT * from ".$db_view." where ".$field." = %s_resource_id and status_type_id <> 'DEL' ".$where, $params);
    if (!$resource){
        return array("error" => array("message" => "Unknown resource","status" => 404));
    }
    $resource['properties'] = json_decode($resource['properties']);
    $resource['access'] = array();
    $resource['owner'] = array();
    if (isset($user['id'])){
        $resource['access'] = DB::queryFirstRow("SELECT preferred_username, username, permissions, user_id, role,role_id, email, creator_sub from resource_user_view where resource_id = %s and user_id = %i", $resource['id'],$user['id']);    
        $resource['current_permission'] = DB::queryFirstField("SELECT permissions from resource_user_view where resource_id = %s and user_id = %i", $resource['id'], $user['id']);
    }    
    if ($resource['status_type_id'] == 'PUB' || $isDacCli){
      $resource['access'] = array("permissions" => "read");  
    } 
    $auth_permissions = ($resource['access']) ? explode(",",$resource['access']['permissions']) : array();
    if (in_array('edit',$auth_permissions) || $isDacCli){
        $resource['access'] = DB::query("SELECT preferred_username, username, permissions, user_id, role,role_id, email, creator_sub from resource_user_view where resource_id = %s", $resource['id']);
        foreach($resource['access'] as $u){
            if ($u['role_id'] == 'OWN'){
                $resource['owner'] = array(
                    "username" => $u['preferred_username'],
                    "name" => $u["username"],
                    "email" => $u['email'],
                    "sub" => $u['creator_sub']
                );
            }
        }
    }
    if (!in_array('read',$auth_permissions)){
        return array("error" => array("message" => "Unauthorized","status",401));
    }
    
    
    $resource['relationTypes'] = DB::query("SELECT domain_type_name as label, domain_type_id as resource_type_id from relationship_rule_view where range_type_name = %s", $resource_type);
    return $resource;
}

function editResource($resource, string $resource_type, string $study_id, Keycloak $auth, Validator $validator, string $project_dir): array
{
    $user = $auth->getDetails();
    $resource_type_id = intval(DB::queryFirstField("SELECT id from resource_type where name = %s", $resource_type));
    if ($resource_type_id === 0) {
        throw new Exception("Unknown type: ".$resource_type, 500);
    }
    // Check study ID
    $study_prefix = DB::queryFirstField("SELECT public_id_prefix from resource_type where name = 'Study'");
    if ($study_prefix) {
        $field = (strtolower(substr($study_id, 0, strlen($study_prefix))) === strtolower($study_prefix)) ? "public_id" : "id";
    } else {
        $field = "id";
    }
    if ($study_id === 'new') {
        $destination = $project_dir . '/data/studies/new_'.date('Y_m_d_H_i_s');
    } else {
        $study = DB::queryFirstRow("SELECT * from study_view where ".$field." = %s", $study_id);
        $study_id = $study['id'];
        $destination = $project_dir . '/data/studies/'.$study['public_id']."/";
    }
    createDirectory($destination, true);
    $tools_path = $project_dir . '/tools/';
    $filepath = $destination."upload_".date('YmdHis').".json";
    file_put_contents($filepath, json_encode($resource), FILE_APPEND);
    $json_output = importResource($tools_path, $study_id, $filepath, $user['email'], $resource_type);
    // unlink($filepath);
    if ($study_id == 'new') {
        unlink($filepath);
        rmdir($destination);
    }

    if ($json_output) {
        $output = json_decode($json_output);

        if (!$output) {
            throw new Exception("Error during import: ".$json_output, 500);
        }
        if (count($output) && isset($output[0]->public_id)) {
            $output1 = $output[0];
            $resource = getResource($auth, $resource_type, $output1->public_id, 'WRI');
            if (isset($output1->comment)) {
                $resource['action_type_id'] = $output1->comment;
            }
            return $resource;
        } elseif (isset($output[0]->comment)) {
            throw new Exception("Problems: ".$output[0]->comment, 500);
        } else {
            throw new Exception("Error importing resource", 500);
        }
    } else {
        throw new Exception("Error: no output", 500);
    }

}


function setResourceStatus(Keycloak $auth, $id, $status)
{
    $user = $auth->getDetails();
    $field = checkUuid($id) ? "id" : "properties->>'public_id'";
    $resource = DB::queryFirstRow("SELECT id, properties->>'public_id' as public_id from resource where $field = %s_id", array("id" => $id));
    if (!$resource) {
        throw new Exception("Error: unknown resource ".$id, 500);
    }
    if (!$auth->isDacCli()){
        $permission = ($status == 'DEL') ? "delete" : "edit";
        $test = DB::queryFirstField("SELECT resource_id from resource_user_view where resource_id = %s and user_id = %i and permissions like %ss", $resource['id'], $user['id'], $permission);
        if (!$test) {
            throw new Exception("Error: permission denied to edit resource: ".$resource['public_id'], 401);
        }        
    }
    setResourceStatusById($resource['id'], $status);
    return $resource['id'];
}

function setResourceStatusById($resource_id, $status)
{
    
    $status_id = DB::queryFirstField("SELECT id from status_type where id = %s or name = %s", $status, $status);
    if (!$status_id) {
        throw new Exception("Error: $status is invalid", 500);
    }
    $resource = DB::queryFirstRow("SELECT id, resource_type_id, status_type_id,properties->>'public_id' as public_id  from resource where id = %s", $resource_id);
    if (!$resource) {
        throw new Exception("Error: $resource_id is unknown", 500);
    }
    if ($resource['status_type_id'] != $status_id) {
        DB::update("resource", array("status_type_id" => $status_id), "id = %s", $resource['id']);
    }
    // check if sdaFile
    $resource_type = DB::queryFirstField("SELECT name from resource_type where id = %i_resource_type_id",$resource);

    // update children resources //
    $children_resource_ids = DB::queryFirstColumn("SELECT domain_resource_id from relationship_view  where range_resource_id = %s and predicate_name = 'isPartOf' and domain_type <> 'SdaFile'", $resource['id']);
    foreach ($children_resource_ids as $children_resource_id) {
        // check if other relationships exists
        if ($status_id == 'DEL') {
            $other_relationships = DB::queryFirstColumn("SELECT range_resource_id from relationship_view where domain_resource_id = %s and range_resource_id <> %s and predicate_name = 'isPartOf'", $children_resource_id, $resource['id']);
        } else {
            $other_relationships = null;
        }
        if (!$other_relationships) {
            setResourceStatusById($children_resource_id, $status_id);
        }
    }
    return $resource['id'];
}

function deleteResourceUser($resource_id, $user_id)
{
    DB::delete("resource_acl", 'resource_id = %s and user_id = %i', $resource_id, $user_id);
    return DB::query("SELECT username, permissions, user_id, role,role_id from resource_user_view where resource_id = %s", $resource_id);
}

function editResourceUser($resource_id, $user, $auth)
{
    $resource_type = DB::queryFirstField("SELECT resource_type.name from resource inner join resource_type on resource.resource_type_id = resource_type.id where resource.id = %s", $resource_id);
    if (!$resource_type) {
        throw new Exception($resource_type." doesn't exist !", 500);
    }
    $access = DB::queryFirstRow("SELECT * from resource_acl where resource_id = %s and user_id = %i", $resource_id, $user['id']);
    if (!$access) {
        DB::insert("resource_acl", array('user_id' => $user['id'],'resource_id' => $resource_id,'role_id' => $user['role']['id']));
    } elseif ($access['role_id'] != $user['role_id']) {
        DB::update("resource_acl", array('role_id' => $user['role_id']), 'resource_id = %s and user_id = %i', $resource_id, $user['id']);
    }
    return DB::query("SELECT username, permissions, user_id, role,role_id from resource_user_view where resource_id = %s", $resource_id);
}

function uploadResources($auth, string $study_id, $request, $project_dir, $content, Validator $validator, SerializerInterface $serializer)
{
    $destination = $project_dir . '/data/studies/';

    $tools_path = $project_dir . '/tools/';
    $user = $auth->getDetails();
    if ($study_id === 'new') {
        $study_public_id = 'new_'.date('Y_m_d_H_i');
    } else {
        $resource_type_id = $content['resource_type_id'];
        $resource_type = DB::queryFirstRow("SELECT id, name from resource_type where id = %i", $resource_type_id);
        if (!$resource_type) {
            throw new Exception("Error: resource type ".$content['resource_type_id']." is unknown", 500);
        }
        $study = DB::queryFirstRow("SELECT id, resource.properties->>'public_id' as public_id from resource where resource.properties->>'public_id' = %s", $study_id);
        $study_public_id = $study_id;
        $study_id = $study['id'];
    }

    createDirectory($destination, true);

    $destination .= $study_public_id . "/";
    createDirectory($destination, true);

    for ($i = 1; $i <= $content['nb_files']; $i++) {
        $file = $request->files->get("file".$i);
        if ($file) {
            if (!$file->isValid()) {
                $errorMessages = [
                    UPLOAD_ERR_INI_SIZE => 'The uploaded file exceeds the maximum allowed size.',
                    UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the maximum allowed size specified in the form.',
                    UPLOAD_ERR_PARTIAL => 'The file was only partially uploaded.',
                    UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
                    UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder.',
                    UPLOAD_ERR_CANT_WRITE => 'Failed to write the file to disk.',
                    UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload.',
                ];
                $errorCode = $file->getError();
                $errorMessage = isset($errorMessages[$errorCode]) ? $errorMessages[$errorCode] : 'Unknown error during file upload.';
                return new JsonResponse($errorMessage, 400);
            }

            $filename = $file->getClientOriginalName();
            $original_name = $filename;
            $numbers = array();
            $pathinfo = pathinfo($filename);
            $basename = $pathinfo['filename'];
            $ext = $pathinfo['extension'];

            $filepath = $destination.$filename;
            $fileProperties = array(
                "name" => $filepath,
                "original_name" => $filename,
                "filesize" => "",
                "mime_type" => "",
                "md5" => ""
            );

            if (file_exists($filepath)) {
                $found = false;
                $nmd5 = md5_file($file);

                $pattern = "$basename*$ext";
                $test = glob($destination.$pattern);
                $nb_match = count($test);
                if ($nb_match) {
                    foreach (glob($destination.$pattern) as $efile) {
                        if (!$found) {
                            $ebasename = pathinfo($efile, PATHINFO_BASENAME);
                            $emd5 = md5_file($efile);

                            if ($emd5 === $nmd5) {
                                $filename = $ebasename;
                                $found = true;
                            } elseif ($nb_match == 1) {
                                $filename = "$basename.1.$ext";
                                $found = true;
                            } else {
                                preg_match("/$basename\.(\d+)\.$ext/", $ebasename, $m);
                                if ($m !== []) {
                                    $numbers[] = (int)$m[1];
                                }
                            }
                        }
                    }
                    if (!$found && count($numbers)) {
                        $max = max($numbers);
                        $nb = $max + 1;
                        $filename = "$basename.$nb.$ext";
                    }
                }
            }

            $filepath = $destination.$filename;
            $fileProperties['name'] = str_replace($project_dir, "", $filepath);
            $file->move($destination, $filename);
            if (!file_exists($destination."/".$filename)) {
                $errorMessage = $destination."/".$filename." not copied to final directory";
                return new JsonResponse($errorMessage, 400);
            }
            $fileProperties['md5'] = md5_file($filepath);
            $fileProperties['mime_type'] = mime_content_type($filepath);
            $fileProperties['filesize'] = filesize($filepath);

            if ($study_id == 'new') {

                if (!file_exists($tools_path."/import_study.php")) {
                    throw new Exception("Error: import_study script not found", 500);
                }
                if (!is_readable($tools_path."/import_study.php")) {
                    throw new Exception("Error: import_study script is not readable", 500);
                }

                $cmd = "php ".$tools_path."/import_study.php -f \"$destination/$filename\" -u ".$user['email']  ;
                if ($_SERVER['HTTP_HOST'] == "localhost:8082") {
                    $process = new Process(["php",$tools_path.'/import_study.php','-f',"$destination/$filename",'-u',$user['email']], null, $_SERVER);
                    $process->run();
                    // executes after the command finishes
                    if (!$process->isSuccessful()) {
                        throw new ProcessFailedException($process);
                    }
                    $output = $process->getOutput();
                } else {
                    $output = exec($cmd, $outputs, $status);
                    if ($status !== 0) {
                        error_log(json_encode($output));
                        //  error_log("ici ERROR - remove directory :  ".$destination );
                        // $destination_not_null = strpos($destination,'new_'.date('Y_m_d'));
                        // if($destination_not_null){ exec("rm -rf ".$destination); }
                        throw new Exception("Error running import_study", 500);
                    }
                }

                return $output;
            } else {

                $uuid = Uuid::uuid4();
                $resource_id = $uuid->toString();

                $file_resource_type_id = DB::queryFirstField("SELECT id from resource_type where name = 'File'");
                getSchemaAndValidate($file_resource_type_id, $fileProperties, $validator);

                $file_properties = $serializer->serialize($fileProperties, 'json');
                $resource = array('id' => $resource_id,'properties' => $file_properties,'resource_type_id' => $file_resource_type_id,'status_type_id' => 'DRA');
                $existing_resource_id = DB::queryFirstField("SELECT * from resource where resource_type_id = %i and properties->>'name'::text = %s", $file_resource_type_id, $fileProperties['name']);

                if ($existing_resource_id) {
                    $resource['id'] = $existing_resource_id;
                    $action_type_id = 'MOD';
                } else {
                    DB::insert("resource", $resource);
                    $action_type_id = 'CRE';
                }

                // Create relationship
                $relation_rule = DB::queryFirstRow("SELECT id,predicate_id from relationship_rule_view where predicate_name = 'isPartOf' and domain_type_name = 'File' and range_type_name = 'Study'");
                if (!$relation_rule) {
                    throw new Exception("Error: relation rule: File isPartOf Study is missing", 500);
                }

                $existing_relation = DB::queryFirstField("SELECT id from relationship where predicate_id = %i and relationship_rule_id = %i and range_resource_id =%s and domain_resource_id =%s ", $relation_rule['predicate_id'], $relation_rule['id'], $study_id, $resource['id']);

                if (!$existing_relation) {
                    $uuid = Uuid::uuid4();
                    $relation_id = $uuid->toString();
                    $relation = array('id' => $relation_id,'relationship_rule_id' => $relation_rule['id'],'predicate_id' => $relation_rule['predicate_id'],'domain_resource_id' => $resource['id'],'range_resource_id' => $study_id);

                    $sequence_nb = DB::queryFirstField("SELECT max(sequence_number) from relationship where predicate_id = %i and relationship_rule_id = %i and range_resource_id = %s ", $relation_rule['id'], $relation_rule['predicate_id'], $study_id);
                    if ($sequence_nb) {
                        $relation['sequence_number'] = $sequence_nb++ ;
                    }
                    DB::insert("relationship", $relation);
                }

                // register log
                $uuid = Uuid::uuid4();
                $log_id = $uuid->toString();
                $log = array(
                    "id" => $log_id,
                    "resource_id" => $resource['id'],
                    "user_id" => $user['id'],
                    "action_type_id" => $action_type_id,
                    "properties" => $resource['properties']
                );

                DB::insert("resource_log", $log);

                if (!file_exists($tools_path."/import_resources.php")) {
                    throw new Exception("Error: import_resources script not found", 500);
                }
                if (!is_readable($tools_path."/import_resources.php")) {
                    throw new Exception("Error: import_resources script is not readable", 500);
                }
                return importResource($tools_path, $study_id, "$destination/$filename", $user['email'], $resource_type['name']);
            }
        }
    }
    return null;
}
