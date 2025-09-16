#!/usr/bin/php
<?php

// json-schema uses a deprecated syntax
error_reporting(E_ERROR | E_WARNING | E_PARSE);
require __DIR__ . '/include.php';
use Ramsey\Uuid\Uuid;

$args           = getopt("s:f:t:u:o:a:lvh", array("validate"));
$study          = (isset($args['s'])) ? $args['s'] : null;
$filepath       = (isset($args['f'])) ? $args['f'] : null;
$alias_filepath = (isset($args['a'])) ? $args['a'] : null;
$email          = (isset($args['u'])) ? $args['u'] : null;
$type           = (isset($args['t'])) ? $args['t'] : null;
$local          = isset($args['validate']);
$verbose        = isset($args['v']);
$output_format  = (isset($args['o'])) ? $args['o'] : "json";
$verbose        = isset($args['v']);
$help           = isset($args['h']);

if (strtolower($output_format) === 'api') {
    $output_format = 'api';
} elseif (strtolower($output_format) === 'tsv') {
    $output_format = 'csv';
} else {
    $output_format = 'json';
}
$needsStudy = (!$local);
if (!$local && $type){
    $needsStudy = DB::queryFirstField("SELECT id from relationship_rule_view where domain_type_name = %s and range_type_name = 'Study'",$type);
}


if ((!$study && !$local && $needsStudy) || !$filepath || !$type || $help) {
    print_usage($local);
    exit(0);
}

try {
    $out = main($study, $filepath, $type, $email, $verbose, $local, $alias_filepath);
    if ($output_format === 'json') {
        fwrite(STDOUT, json_encode($out).PHP_EOL);
    } elseif ($output_format === 'csv') {
        if (count($out) > 0) {
            fwrite(STDOUT, implode(",", array_keys($out[0])).PHP_EOL);
            foreach ($out as $o) {
                $display = '';
                foreach ($o as $d) {
                    if (gettype($d) === 'string') {
                        $display .= "$d,";
                    } elseif (gettype($d) === 'array') {
                        $display .= implode(";", $d).",";
                    }
                }
                fwrite(STDOUT, $display.PHP_EOL);
            }
        }
    } else {
        return '';
    }
} catch (Exception $e) {
    fwrite(STDERR, $e->getMessage().PHP_EOL);
    exit(1);
}

function isJson($string)
{
    json_decode($string);
    return json_last_error() === JSON_ERROR_NONE;
}

function validateResource($resource, $schema, $type, $study_id, $alias_filepath, $local, $verbose)
{
    /*Some part of this function was done by Robin.
    I'm not sure that we enter in all "if" conditions.
    I'm sure that could be improved :-)
    */

    $matching = array('sample_public_id' => 'Sample',
                        'sample_public_ids' => 'Sample',
                        'molecularexperiment_public_id' => 'MolecularExperiment',
                        'molecularexperiment_public_ids' => 'MolecularExperiment',
                        'run_public_ids' => 'MolecularRun',
                        'analysis_public_ids' => 'MolecularAnalysis',
                        'dac_public_id' => "DAC"
                    );

    $validator = new JsonSchema\Validator();
    if ($alias_filepath && file_exists($alias_filepath)) {
        $alias_content = json_decode(file_get_contents($alias_filepath), true);
    }
    foreach ($schema->data_schema->properties as $prop_name => $prop) {
        if (stripos($prop_name, 'public_id') !== false && (($prop->type === 'string' && isset($prop->enum)) || ($prop->type == 'array' && $prop->items->type == 'string' && isset($prop->items->enum)))) {
            if (!$local) {
                $xref_resource_type = trim(str_ireplace("public_id", "", $prop_name), '_');
                $sqltables = DB::tableList();
                if (in_array($xref_resource_type."_view", $sqltables)) {
                    $columns = DB::columnList($xref_resource_type."_view");
                    if (isset($columns['study_id'])) {
                        $selectedColumns = array_intersect(array_keys($columns), array("public_id","title"));
                        $xrefs = DB::queryFirstColumn("SELECT distinct concat_ws(': ',".implode(", ", $selectedColumns).") as xref from ".$xref_resource_type."_view where study_id = %s", $study_id);
                        if ($prop->type == 'string') {
                            $schema->data_schema->properties->{$prop_name}->enum = $xrefs;
                        } elseif ($prop->type == 'array') {
                            $schema->data_schema->properties->{$prop_name}->items->enum = $xrefs;
                        }
                    }
                }
            } elseif (isset($resource->{$prop_name})) {
                if ($prop->type == 'string') {
                    if (!$resource->{$prop_name}) {
                        unset($resource->{$prop_name});
                    }
                    $schema->data_schema->properties->{$prop_name}->enum = $resource->{$prop_name};
                } elseif ($prop->type == 'array') {
                    $schema->data_schema->properties->{$prop_name}->items->enum = $resource->{$prop_name};
                }
            }
        } elseif ((isset($prop->enum) && !count($prop->enum)) || (isset($prop->items) && isset($prop->items->enum) && is_array($prop->items->enum) && $prop->items->enum === []) && isset($resource->{$prop_name})) {
            if ($prop->type == 'string') {
                $schema->data_schema->properties->{$prop_name}->enum = $resource->{$prop_name};
            } elseif ($prop->type == 'array') {
                $schema->data_schema->properties->{$prop_name}->items->enum = $resource->{$prop_name};
            }
        } else {
            if (isset($prop->type) && $prop->type == 'string') {
                if (isset($resource->{$prop_name}) && !$resource->{$prop_name}) {
                    unset($resource->{$prop_name});
                }
            } elseif (isset($prop->type) && $prop->type == 'array') {
                if ($prop_name == 'sdafile_public_ids' && $local) {
                    unset($prop->pattern);
                    if (isset($prop->items) && isset($prop->items->pattern)) {
                        unset($prop->items->pattern);
                    }
                    // unset($resource->$prop_name);
                    //TODO : think about it if it's run in local because no access to SdaFile list
                    // $existingResource = DB::queryFirstField("SELECT resource.id from resource inner join resource_type on resource_type.id = resource.resource_type_id where resource_type.name = 'SdaFile' and resource.properties->>'title' =%s",$resource->title);
                    // print(json_encode($resource)."\n");
                    // print("existing resource :".$resource->title." ".$existingResource."\n");

                }
                // TODO : check if empty
                // $schema->data_schema->properties->{$prop_name}->items->enum = $resource->{$prop_name};
            }
            if (stripos($prop_name, 'public_id') > 0 && $local) {

                unset($prop->pattern);
                if (isset($prop->items) && isset($prop->items->pattern)) {
                    unset($prop->items->pattern);
                }

                if (isset($alias_content) && $prop_name != 'sdafile_public_ids') {
                    $list_alias = $alias_content[$matching[$prop_name]];
                    $tmp_type = gettype($resource->$prop_name);

                    $compare = array();
                    if ($tmp_type === 'string') {
                        $compare[] = $resource->$prop_name;
                    } elseif ($tmp_type === 'array') {
                        foreach ($resource->$prop_name as $r) {
                            $compare[] = $r;
                        }
                    }

                    foreach ($compare as $c) {
                        if (!in_array($c, $list_alias)) {
                            $prop_title = (isset($prop->title)) ? $prop->title : "";
                            $mess =  "ERROR : ". $prop_title." '".$c. "' doesn't exist.";
                            if ($verbose) {
                                // print("\t".$mess . "\tit should be in (". implode(",",$list_alias).")\n\n");
                                fwrite(STDERR, "\t".$mess . "\tit should be in (". implode(",", $list_alias).")".PHP_EOL);
                            }
                            return $mess;
                        }
                    }
                }
            }
        }
    }
    $validator->validate($resource, $schema->data_schema);
    if ($validator->isValid()) {
        return true;
    } else {
        $content = '';
        if ($verbose) {
            print "\tJSON does not validate. Violations:\t";
        }
        foreach ($validator->getErrors() as $error) {
            $content .= "[".$error['property']."]:". $error['message']."; ";
            if ($verbose) {
                print("\t". $error['property']."\t". $error['message']);
            }
        }
        return substr($content, 0, -2);
    }
}


function formatMolecularType($type, $rows, $separator, $study_id, $local)
{
    /*
    This function was created by Robin and I update it after.
    the user file in input may not contain terms "molecularexperiment_public_id" but "experiment" and the title of the experiment instead of the public_id. So the aim of this function is to get the correct public ID from the title of the resource.
    */
    $mapping_tables = array(
        "experiment"  => "molecularexperiment_view",
        "sample"      => "sample_view",
        "experiments" => "molecularexperiment_view",
        "samples"     => "sample_view",
        "runs"        => "molecularrun_view",
        "files"       => "sdafile_view",
        "analyses"    => "molecularanalysis_view"
    );
    if ($type == 'run') {
        $header_mapping = array(
            "experiment" => "molecularexperiment_public_id",
            "sample"     => "sample_public_id",
            "file_type"  => "run_file_type",
            "files"      => 'sdafile_public_ids'
        );
    } elseif ($type == 'analysis') {
        $header_mapping = array(
            "files"       => "sdafile_public_ids",
            "experiments" => "molecularexperiment_public_ids",
            "samples"     => "sample_public_ids"
        );
    } elseif ($type == 'dataset') {
        $header_mapping = array(
            "runs"     => "run_public_ids",
            "analyses" => "analysis_public_ids"
        );
    } elseif ($type == 'policy') {
        $header_mapping = array(
            "dac"     => "dac_public_id"
        );
    }
    $header_mapping_keys = array_keys($header_mapping);
    $object = array();
    $header = array_shift($rows);
    $headers = str_getcsv($header, $separator);
    $cols = array();
    foreach ($headers as $header) {
        $header = trim(strtolower($header));
        $cols[] = isset($header_mapping[$header]) ? $header_mapping[$header] : $header;
    }
    $content[] = implode($separator, $cols);
    while (count($rows)) {
        $key = '';
        $row = array_shift($rows);
        $cols = str_getcsv($row, $separator);
        if (count($cols) === count($headers)) {
            $data = array_combine($headers, $cols);

            foreach ($header_mapping_keys as $field_key) {

                if ($field_key == 'file_type' || !isset($data[$field_key])) {
                    continue;
                }

                $object_type = null;
                if ($field_key == 'sample' || $field_key == 'experiment') {
                    if ($local) {
                        $current_id = $data[$field_key];
                    } else {
                        $current_id = DB::queryFirstField("SELECT public_id from %b where public_id = %s or title = %s and study_id = %s", $mapping_tables[$field_key], $data[$field_key], $data[$field_key], $study_id);
                    }
                    if (!$current_id) {
                        continue;
                    }
                    $object_type = $current_id;
                    $key .= $current_id. "_";
                } elseif (in_array($field_key, array('samples','experiments','runs','analyses','files'))) {
                    $data[$field_key] = preg_split("/[,;]/", $data[$field_key]);
                    $object_type = array();
                    foreach ($data[$field_key] as $d) {
                        if ($local) {
                            $current_id = $d;
                        } elseif ($field_key === 'files') {
                            $current_id = DB::queryFirstField("SELECT public_id from %b where public_id = %s or title = %s", $mapping_tables[$field_key], $d, $d);
                        } else {
                            $current_id = DB::queryFirstField("SELECT public_id from %b where public_id = %s or title = %s and study_id = %s", $mapping_tables[$field_key], $d, $d, $study_id);
                        }
                        if (!$current_id) {
                            continue;
                        }
                        $object_type[] = $current_id;
                        $key .= $current_id. "_";
                    }
                }
                $data[$field_key] = json_encode($object_type);
            }
            if (!isset($object[$key])) {
                $object[$key] = $data;
            }
        }
    }
    foreach ($object as $obj) {
        $content[] = implode($separator, $obj);
    }
    return $content;
}

function format_MolecularRun($rows, $separator, $study_id, $local)
{
    return formatMolecularType('run', $rows, $separator, $study_id, $local);
}

function format_MolecularAnalysis($rows, $separator, $study_id, $local)
{
    return formatMolecularType('analysis', $rows, $separator, $study_id, $local);
}

function format_Dataset($rows, $separator, $study_id, $local)
{
    return formatMolecularType('dataset', $rows, $separator, $study_id, $local);
}

function getSeparator($line, $format)
{
    $separator = ',';
    if ($format == 'csv') {
        $testTabs = str_getcsv($line, "\t");
        if (count($testTabs) > 2) {
            $separator = "\t";
        }
    } else {
        $separator = "\t";
    }
    return $separator;
}

function parseFile($file, $resource_type, $schema, $study_id, $local, $verbose)
{
    $format = pathinfo($file, PATHINFO_EXTENSION);
    if ($format != 'json' && $format != 'csv' && $format != 'tsv') {
        throw new Exception("Invalid file format. Should be JSON, CSV or TSV", 1);
    }
    if ($verbose) {
        print("\tfile format $format is accepted\n");
    }

    $properties = [];

    if ($format === 'csv' || $format === 'tsv') {
        $rows = file($file, FILE_IGNORE_NEW_LINES);
        $separator = getSeparator($rows[0], $format);
        $function = "format_".$resource_type['name'];
        if ($verbose) {
            print("\tFUNCTION :: $function\n");
        }
        if (function_exists($function)) {
            $rows = $function($rows, $separator, $study_id, $local);
        }
        $header = array_shift($rows);
        $headers = array_map(function ($a) use ($separator) {return strtolower($a);}, str_getcsv($header, $separator));
        if ($verbose) {
            print("\t".count($rows) ." resources\n\t");
        }
        $props = (array) $schema->data_schema->properties;
        $schema_props = array_keys($props);
        foreach ($rows as $idx => $r) {
            if ($verbose) {
                print(".");
            }
            $cols = str_getcsv($r, $separator);
            $obj = array('extra_attributes' => array());
            foreach ($cols as $idx => $c) {
                if (in_array($headers[$idx], $schema_props) && $headers[$idx] !== 'extra_attributes') {
                    if (isset($props[$headers[$idx]]->type) && $props[$headers[$idx]]->type == 'array') {
                        $content = json_decode($c);
                        if ($content) {
                            $obj[$headers[$idx]] = $content;
                        } elseif (isset($props[$headers[$idx]]->items->enum) && !in_array($c, $props[$headers[$idx]]->items->enum) && (strpos($c, ",") || strpos($c, ";"))) {
                            $split_c = preg_split("/[,;]/", $c);
                            $obj[$headers[$idx]] = $split_c;
                        } else {
                            $obj[$headers[$idx]] = array($c);
                        }
                    } else {
                        $obj[$headers[$idx]] = (is_numeric($c) || (isset($props[$headers[$idx]]->type) && $props[$headers[$idx]]->type == 'number')) ? floatval($c) : $c;
                    }
                } else {
					if ($c === 0 || $c === "0" || $c){
						$obj['extra_attributes'][] = array('tag' => $headers[$idx],'value' => $c);	
					}
                    
                }
            }
            $resource = json_decode(json_encode($obj));
            $properties[] = $resource;
        }
        if ($verbose) {
            print("\n");
        }
    } elseif ($format === 'json') {
        $json = file_get_contents($file);
        if (!isJson($json)) {
            print("Json not valid\n");
            return null;
        }
        $rows = json_decode($json);
        $jsontype = getType($rows);
        if ($jsontype === 'array') {
            foreach ($rows as $r) {
                $properties[] = $r;
            }
        } elseif ($jsontype === 'object') {
            $properties[] = $rows;
        }
    }
    return $properties;
}

function createRelationShip($domain_type_name, $range_type_name, $domain_id, $range_id, $verbose)
{
    if ($verbose){
        fwrite(STDOUT,"Create Relashionship...".PHP_EOL);
    }
    $relation_rule = DB::queryFirstRow("SELECT id,predicate_id,default_is_active from relationship_rule_view where predicate_name = 'isPartOf' and domain_type_name = %s and range_type_name = %s", $domain_type_name, $range_type_name);
    // NEEDED when registring policies that are part of DAC.
    if (!$relation_rule){
        $relation_rule = DB::queryFirstRow("SELECT id,predicate_id,default_is_active from relationship_rule_view where predicate_name = 'isLinkedTo' and domain_type_name = %s and range_type_name = %s", $range_type_name, $domain_type_name);
        if ($relation_rule){
            $tmp_id = $domain_id;
            $domain_id = $range_id;
            $range_id = $tmp_id;
            unset($tmp_id);
        }
    }
    // END NEEDED
    if ($relation_rule) {
        $existing_relation = DB::queryFirstField("SELECT id from relationship where predicate_id = %i and relationship_rule_id = %i and range_resource_id =%s and domain_resource_id =%s ", $relation_rule['predicate_id'], $relation_rule['id'], $range_id, $domain_id);
        $relation = array('id' => Uuid::uuid4(),'relationship_rule_id' => $relation_rule['id'],'predicate_id' => $relation_rule['predicate_id'],'domain_resource_id' => $domain_id,'range_resource_id' => $range_id);
        if (!$existing_relation) {
            if ($verbose) {
                print("\tcreate relationship\t");
            }
            $relation['is_active'] = $relation_rule['default_is_active'];
            $sequence_nb = DB::queryFirstField("SELECT max(sequence_number) from relationship where predicate_id = %i and relationship_rule_id = %i and range_resource_id = %s ", $relation_rule['id'], $relation_rule['predicate_id'], $range_id);
            if ($sequence_nb) {
                $relation['sequence_number'] = $sequence_nb++ ;
            }
            DB::insert("relationship", $relation);
        }
    }
}

function insertResource($study_id, $resource_type, $properties, $user_id, $role_id, $verbose)
{
    $ret = array('action_type_id' => null,'public_id' => null);
    $data = array(
        "id"               => null,
        "properties"       => null,
        "resource_type_id" => $resource_type['id'],
        "status_type_id"   => DB::queryFirstField("SELECT id from status_type where name = 'draft'")
    );
    $properties = (array)$properties;
    $action_type_id = 'CRE';
    if (isset($properties['id']) && $properties['id']) {
        $action_type_id = 'MOD';
        $data['id'] = $properties['id'];
    } elseif (isset($properties['public_id']) && $properties['public_id']) {
        $properties['id'] = DB::queryFirstField("SELECT id from resource where resource.properties ->> 'public_id' = %s", $properties['public_id']);
        $action_type_id = 'MOD';
        $data['id'] = $properties['id'];
    }
    if ($action_type_id === 'CRE') {
        // check if a resource already exists
        $required_json = DB::queryFirstField("SELECT resource_type.properties -> 'data_schema' ->> 'required' from resource_type where id = %s;", $resource_type['id']);
        $required = json_decode($required_json);
        $where = "";
        $params = array(
            "user_id"            => $user_id,
            "resource_type_name" => $resource_type['name']
        );
        if ($study_id){
            $params['study_id'] = $study_id;
        }
        foreach ($required as $req) {
            if (isset($properties[$req])) {
                if (is_array($properties[$req])) {
                    $where .= " and to_jsonb(string_to_array('".implode(",", $properties[$req])."',',')) <@ (resource.properties -> '".$req."')::jsonb and to_jsonb(string_to_array('".implode(",", $properties[$req])."',',')) @> (resource.properties -> '".$req."')::jsonb";
                } else {
                    $where .= " and resource.properties ->> '".$req."' = %s_".$req;
                    $params[$req] = $properties[$req];
                }
            }
        }

        if ($study_id && $study_id != 'new') {
            $where .= " and relationship.range_resource_id = %s_study_id ";
        }
        $existing_resource = DB::queryFirstRow(
            "SELECT RESOURCE.ID,
               RESOURCE.PROPERTIES ->> 'public_id' as PUBLIC_ID
        FROM resource
        INNER JOIN RESOURCE_TYPE ON RESOURCE.RESOURCE_TYPE_ID = RESOURCE_TYPE.ID
        INNER JOIN RESOURCE_ACL on RESOURCE.ID = RESOURCE_ACL.RESOURCE_ID
        and RESOURCE_ACL.ROLE_ID in ('OWN',
                                     'WRI')
        LEFT JOIN RELATIONSHIP on RESOURCE.ID = RELATIONSHIP.DOMAIN_RESOURCE_ID
        LEFT JOIN PREDICATE on RELATIONSHIP.PREDICATE_ID = PREDICATE.ID
        and PREDICATE.\"name\" = 'isPartOf'
                        WHERE resource_acl.user_id = %i_user_id
                        and resource_type. \"name\" = %s_resource_type_name
        
        ".$where,
            $params
        );
        
        if ($existing_resource) {
            $data['id']              = $existing_resource['id'];
            $properties['public_id'] = $existing_resource['public_id'];
            $action_type_id          = 'MOD';
            // print("Error: a resource with the same alias already exists\n");
        }
    }
    $data['properties'] = json_encode($properties);


    // create resource
    if ($verbose) {
        print("\t".$action_type_id."\t");
    }
    if (!$data['id']) {
        $uuid = Uuid::uuid4();
        $data['id'] = $uuid->toString();
        DB::insert('resource', $data);
        if ($verbose) {
            print("\tcreate resource\t");
        }
        // register creator
        if ($role_id) {
            $acl = array( "resource_id" => $data['id'], "user_id" => $user_id, "role_id" => $role_id );
            DB::insert('resource_acl', $acl);
        }
    } else {
        if ($verbose) {
            print("\tupdate resource\t");
        }
        DB::update('resource', $data, "id = %s", $data['id']);
    }
    // DB::debugMode(TRUE);
    // Create relationship between new resource and study
    if ($study_id) {
        createRelationShip($resource_type['name'], 'Study', $data['id'], $study_id, $verbose);
    }

    // Create relationship between new resource and dependencies
    foreach ($properties as $prop_name => $prop) {
        if (strpos($prop_name, 'public_id')) {
            if (gettype($prop) === 'string') {
                $public_ids = array($prop);
            } elseif (gettype($prop) === 'array') {
                $public_ids = $prop;
            }

            foreach ($public_ids as $public_id) {
                $dep_resource = DB::queryFirstRow("SELECT resource.id, resource_type.name as resource_type_name from resource inner join resource_type on resource_type.id = resource.resource_type_id where resource.properties->>'public_id' =  %s", $public_id);
                if ($dep_resource){
                    createRelationShip($dep_resource['resource_type_name'], $resource_type['name'], $dep_resource['id'], $data['id'], $verbose);
                    if ($dep_resource['resource_type_name'] == 'SdaFile' && $study_id){
                        createRelationShip($dep_resource['resource_type_name'], 'Study', $dep_resource['id'], $study_id, $verbose);
                    }                    
                }
                else{
                    error_log($public_id." NOT FOUND in resource");
                }
            }
        }
    }


    // register log
    $uuid = Uuid::uuid4();
    $log_id = $uuid->toString();
    $log = array(
        "id"             => $log_id,
        "resource_id"    => $data['id'],
        "user_id"        => $user_id,
        "action_type_id" => $action_type_id,
        "properties"     => $data['properties']
    );

    DB::insert("resource_log", $log);
    if ($verbose) {
        print("\tcreate log\n");
    }
    $ret['action_type_id'] = $action_type_id;
    $ret['public_id'] = DB::queryFirstField("SELECT resource.properties ->> 'public_id' as public_id from resource where id = %s", $data['id']);
    return $ret;
}

function main($study_id, $filepath, $type, $email, $verbose, $local, $alias_filepath)
{

    $global_status = null;
    $logs = array();
    $user_id = null;

    if ($verbose) {
        print("0. Setup : \n\tfilepath : $filepath\n\tstudy ID : $study_id\n\tType : $type\n\temail : $email\n\tVerbose : $verbose\n\tlocal : $local\n\tAlias filepath : $alias_filepath\n\n1. Check : \n");
    }
    if ($local && !$email) {
        $schemas = getSchemas();
        if (!isset($schemas[$type])) {
            print("\tFile schemas for type $type doesn't exist !\n");
            exit;
        }
        $resource_type = array('name' => $type,'properties' => json_encode($schemas[$type]));
    } else {
        $user_id = DB::queryFirstField('SELECT id from "user" where email = %s or external_id = %s', $email, $email);
        if (!$user_id) {
            print("User ID '$email' doesn't belongs to any user\n");
            return null;
        }
        if ($verbose) {
            print("\tUser with email '$email' exists\n");
        }
        $studyPrefix = DB::queryFirstField("SELECT public_id_prefix from resource_type where name = 'Study'");
        if (!$studyPrefix) {
            throw new Exception("Error: unable to guess public_id_prefix for Study", 1);
        }
        if ($study_id && $study_id != 'new') {
            $field = (substr(strtolower($study_id), 0, strlen($studyPrefix)) === strtolower($studyPrefix)) ? "resource.properties ->> 'public_id'" : "id";
            $study = DB::queryFirstRow("SELECT * from resource where ".$field." = %s", $study_id);
            if (!$study) {
                print("Study '$study_id' doesn't exist\n");
                return null;
            }
            if ($verbose) {
                print("\tCheck parameters : \n\tStudy exists\n");
            }
            $study_id = $study['id'];
            $access = DB::queryFirstField("SELECT permissions from resource_user_view where user_id = %i and resource_id = %s;", $user_id, $study_id);
            if (!$access || !in_array('edit', explode(",", $access))) {
                print("You have not the permission to add resources to this study: $user_id $study_id\n");
                return null;
            }
            if ($verbose) {
                print("\tUser is allowed to add resources in this study\n");
            }
        }
        $role_id = DB::queryFirstField("SELECT id from \"role\" where name = 'owner'");
        if (!$role_id) {
            print("Error: owner role is unknown\n");
            return null;
        }

        $resource_type = DB::queryFirstRow("SELECT id, name, properties from resource_type where name = %s", $type);
        if (!$resource_type) {
            print("Type '$type' doesn't exist\n");
            return null;
        }
        if ($verbose) {
            print("\tType '$type' exists\n");
        }
    }


    if (!file_exists($filepath)) {
        print("File '$filepath' doesn't exist\n");
        return null;
    }
    if ($verbose) {
        print("\tCheck File : '$filepath' exists\n");
    }

    $schema = json_decode($resource_type['properties']);

    if (!$schema) {
        throw new Exception("Unknown schemas", 500);
    }
    if (!isset($schema->data_schema) || !isset($schema->data_schema->properties)) {
        throw new Exception("Unknown data_schema", 500);
    }
    if ($verbose) {
        print("\tJson schema found and validated\n");
    }

    $requireProperties = $schema->data_schema->required;

    if ($verbose) {
        print("\n2. Parsing file\n");
    }
    $properties = parseFile($filepath, $resource_type, $schema, $study_id, $local, $verbose);
    if ($verbose) {
        $mess = "End of Parsing : ".count($properties)." resource";
        if (count($properties) > 1) {
            $mess .= 's';
        }
        print($mess);
        $cas = ($local) ? 'validation' : 'insertion';
        print("\n\n3. Beginning of $cas\n");
    }

    foreach ($properties as $props) {
        $log = array();
        foreach ($requireProperties as $req) {
            $log[$req] = isset($props->{$req}) ? $props->{$req} : null;
        }
        try {
            if ($verbose) {
                print("\t");
                foreach ($requireProperties as $req) {
                    print(json_encode($props->{$req})."\t");
                }
                print("\n");
            }
            $original_schema = unserialize(serialize($schema)) ;
            $pass = validateResource($props, $original_schema, $type, $study_id, $alias_filepath, $local, $verbose);
            if ($pass == 1) {
                if ($local) {
                    $log['status'] = 'SUCCESS';
                } else {
                    $inserted_data = insertResource($study_id, $resource_type, $props, $user_id, $role_id, $verbose);
                    $log['public_id'] = $inserted_data['public_id'];
                    $log['comment'] = $inserted_data['action_type_id'];
                    $log['status'] = 'SUCCESS';
                }
            } else {
                $global_status = 'FAIL';
                $log['comment'] = $pass;
                $log['status'] = 'FAIL';
            }
            $logs[] = $log;
        } catch (\Exception $e) {
            $log['comment'] = $e->getMessage();
            $log['action'] = '';
            $global_status = 'FAIL';
            $log['status'] = 'FAIL';
            $logs[] = $log;
        }
    }
    if ($global_status == 'FAIL') {
        // print("ROLLBACK"."\n");
        // DB::rollback();
    } else {
        // print("COMMIT"."\n");
    }
    if ($verbose) {
        print("End of $cas\n");
    }
    if ($verbose) {
        print("\n4. OUTPUT\n");
    }

    return $logs;
}

function getSchemas()
{
    $schemas = file_get_contents("https://fega-portal-dev.vital-it.ch/api/api/schemas");
    return json_decode($schemas, true);
}

function getResourceTypes()
{
    $resource_types = file_get_contents("https://fega-portal-dev.vital-it.ch/api/api/resource-types");
    return json_decode($resource_types, true);
}

function print_usage($local = false)
{

    fwrite(STDOUT, "usage: php ".basename(__FILE__)." -s <study> -f <filepath> -u <user_id> -t <type> [-o <output_format>] [-a <attached_filepath>] [--validate] [-v] [-h]".PHP_EOL);
    $resource_types = getResourceTypes();
    $types = "One of: ".implode(", ", $resource_types);
    // $types = '';
    // if($local && file_exists("data/resource_type_list.txt")){
    //  $types .= 'One of: '. file_get_contents("data/resource_type_list.txt");
    // }
    // else{
    //  $tmp_types = DB::queryFirstColumn("SELECT resource_type.name as resourceType from resource_type where properties is not null and public_id_prefix is not null order by name");
    //  if ($tmp_types){
    //    $types .= 'One of: ' . implode(", ",$tmp_types);
    //  }
    // }
    fwrite(STDOUT, "  -s <study>         : study public ID ".PHP_EOL);
    fwrite(STDOUT, "  -f <filepath>      : path of the file to import. Accepted file formats are: .json, .csv, .tsv".PHP_EOL);
    fwrite(STDOUT, "  -t <type>          : type of resource. $types".PHP_EOL);
    fwrite(STDOUT, "  -u <user_id>         : user ID (email or EduID) of user. Optional if validation mode".PHP_EOL);
    fwrite(STDOUT, "  -o <output_format> : json or tsv. Default: json".PHP_EOL);
    fwrite(STDOUT, "  --validate         : validate. Performs only JSON format validation, with no insertion in the database".PHP_EOL);
    fwrite(STDOUT, "  -a <filepath>      : path of the attached file to list other resource dependencies. Accepted file formats are: .json".PHP_EOL);
    fwrite(STDOUT, "  -v                 : verbose".PHP_EOL);
    fwrite(STDOUT, "  -h                 : print this help".PHP_EOL);
    return true;
}
