#!/usr/bin/php
<?php
require_once __DIR__ . '/include.php';
use Ramsey\Uuid\Uuid;
use Symfony\Component\Yaml\Yaml;
$args = getopt("f:u:o:vh", array("validate"));

$filepath = (isset($args['f'])) ? $args['f'] : null;
$email = (isset($args['u'])) ? $args['u'] : null;
$local = isset($args['validate']);

$output_format = (isset($args['o'])) ? $args['o'] : "json";
$verbose = isset($args['v']);
$help = isset($args['h']);

if (strtolower($output_format) === 'api') {
    $output_format = 'api';
} elseif (strtolower($output_format) === 'tsv') {
    $output_format = 'tsv';
} else{
    $output_format = 'json';
}

if (!$filepath || $help){
    print_usage($local);
    exit(0);
}

try {
    $tmpdir = tempnam(sys_get_temp_dir(),'FEGA');
    unlink($tmpdir);
    mkdir($tmpdir,0750);
    if (!file_exists($filepath) || !is_readable($filepath)){
        fwrite(STDERR,$filepath." not found or cannot be read".PHP_EOL);
    }
    copy($filepath,$tmpdir."/".basename($filepath));
    $filepath = $tmpdir."/".basename($filepath);
    $out = main($filepath,$email,$output_format,$verbose,$local);
    // exec("rm -rf ".$tmpdir);
     if($out){   
         if ($output_format === 'json'){
             fwrite(STDOUT,json_encode($out).PHP_EOL);
         }
         elseif ($output_format === 'csv'){
             if (count($out) > 0){
                 fwrite(STDOUT,implode(",",array_keys($out[0])).PHP_EOL);
                 foreach($out as $o){
                     $display = '';
                     foreach($o as $d){
                         if (gettype($d) === 'string') {
                             $display.="$d,";
                         } elseif (gettype($d) === 'array') {
                             $display.=implode(";",$d).",";
                         }
                     }
                     fwrite(STDOUT,$display.PHP_EOL);
                 }
             }
         }
     }
     else{
         return '';
     }
} catch (Exception $e) {
    fwrite(STDERR,$e->getMessage().PHP_EOL);
    exit(1);
}



function getSeparator($line,$format){
    $separator = ',';
    if ($format == 'csv'){
        $testTabs = str_getcsv($line,"\t");
        if (count($testTabs) > 2){
            $separator = "\t";
         }
     }
     else{
         $separator = "\t";
     }
     return $separator;
}


function parseManifest($package_dir,$types,$local,$verbose){
  
  /* Scan directory, extract manifest file.
  Parse it. Raise error if : 
  - manifest not exist or is not csv, tsv, json
  - listed types are not existing type
  - file of resource doesn't exist  
  - missing a required type 
  */
    
  if ($verbose) {
      print("\tDirectory : $package_dir\n");
  }
  $manifest=null;
  $files = scandir($package_dir);
  foreach($files as $f){
      if ($f === '.' || $f === '..') {
          continue;
      }
      if ($verbose) {
          print("\t\tFILE  $f\n");
      }
      if(stripos($f,'manifest')>-1){ $manifest = $f; }
  }
  if(!$manifest){
      return array('status'=>'FAIL','message'=>"Manifest file doesn't exist. It is essential to know resource type of each file"); 
      // print("ERROR. Manifest file doesn't exist. It is essential to know resource type of each file\n"); return; 
  }
  
  $manifest_data = array();
  $minfo = pathinfo($manifest);
  $format= $minfo['extension'];

  if($format != 'csv' && $format != 'tsv' && $format != 'json' && $format != 'yaml'){
      if($verbose){ print("\tManifest file '$manifest' is a ".$format."\n"); }
      return array('status'=>'FAIL','message'=>"Manifest file '$manifest' shoud be a csv, tsv, json or yaml"); 
      // print("ERROR. Manifest file '$manifest' shoud be a csv, tsv or json \n"); return; 
  }
  $mlines = array();
  if ($format == 'yaml'){
      $separator = "\t";
      $yaml = file_get_contents($package_dir."/".$manifest);
      $data = Yaml::parse($yaml);
      foreach ($data['files'] as $row) {
         $mlines[] = $row['resource_type'].$separator.$row['file_name'];
      }
  }
  else {
      $mlines = file($package_dir."/".$manifest,FILE_IGNORE_NEW_LINES);
      $separator = getSeparator($mlines[0],$format);
  }
  foreach($mlines as $l){
      if(trim($l) !== '' && trim($l) !== '0'){
          $row = explode($separator,$l);
          $current_type = trim($row[0]);
          $current_file = trim($row[1]);
          if(!in_array($current_type,$types)){
              return array('status'=>'FAIL','message'=>$current_type . " is not an recognized type. Should be one of ".implode(", ",$types)); 
              // print("ERROR. ".$current_type . " is not a official type. Should be in ".implode(", ",$types)."\n"); return;
          }
          if(!file_exists($package_dir."/".$current_file)){
              return array('status'=>'FAIL','message'=>"file ".$package_dir."/".$current_file . " doesn't exist."); 
              // print("ERROR. file ".$package_dir."/".$current_file . " doesn't exist.\n");    return;

          }
          $manifest_data[$current_type] = $current_file;
      }
  }
  
  foreach($types as $t){
      if(!isset($manifest_data[$t])){
          return array('status'=>'FAIL','message'=>"Missing resource $t"); 
          // print("ERROR. Missing resource $t ! \n");  return;
      }
  }
  return array('status'=>'SUCCESS','message'=>null,'data'=>$manifest_data);
}

function getResourceTypes(){
  $resource_types = file_get_contents("https://fega-portal-dev.vital-it.ch/api/api/resource-types");
  return json_decode($resource_types,true);
}

function loopOnResources($verbose, $logs, $types, $metadata, $package_dir, $output_format, $local, $email)
{

    $tmp_file = $package_dir."/config_alias.json";
    file_put_contents($tmp_file,"");
    $study_id='new';
    $outputs = array();
    $data_return = array('output'=>null,'status'=>'SUCCESS','message'=>array(),'study_id'=>null);
    $all_titles = array();
    foreach($types as $type){
        $tmp_titles = array();
        // if($logs['status']=='FAIL') continue;
        if($type !='Study' && $study_id){
            // continue;
            // print("ERROR. Study fail !\n"); return;
        }
        if ($verbose) {
            print("\t$type : ".$metadata[$type]."\n");
        }
        $out = null;
        if($local){
             $cmd_args = "-s new -f ".$package_dir."/".$metadata[$type]." -t $type -o $output_format -u $email --validate -a $tmp_file";
        }
        else{
            $cmd_args = " -s $study_id -f ".$package_dir."/".$metadata[$type]." -u $email -t $type -o $output_format ";     
        }
        // if ($verbose) $cmd_args .= " -v";
        $cmd = "php ".__DIR__."/import_resources.php $cmd_args ";
        if ($verbose) {
            print("\t\tCommand : $cmd\n");
        }
        exec($cmd,$out);
		if (count($out) > 1){
	        array_shift($out);			
		}
            
        if($output_format=='tsv'){
            //TODO, for the moment, I focus on json output
            $output = $out;
            foreach($out as $o){
                if ($verbose) {
                    print($o."\n");
                }
                $outputs[$type] = $out;
            }
        }
        else{
            $output = json_decode($out[0],true);
            if (!$output) {
                return null;
            }
            foreach($output as $idx=>$o){
                if ($o['status']!='SUCCESS') {
                    return array('status'=>'FAIL','message'=>"ERROR during $type import. File ".$metadata[$type] ." : line ".($idx+1) ."\n",'data'=>json_encode($o));
                } elseif (isset($o['alias'])) {
                    $tmp_titles[] = $o['alias'];
                } elseif (isset($o['title'])) {
                    $tmp_titles[] = $o['title'];
                }
                $all_titles[$type] = $tmp_titles;
                file_put_contents($tmp_file,json_encode($all_titles));          
            } 
            $outputs[$type] = $output;

            if($type=='Study') {
                $study = $output[0];
                $study_id = isset($study['public_id']) ? $study['public_id'] : "FEGAST_local";
            }
        }
    }
    // if($logs['status']!='SUCCESS' ){
    //  print("rollback ?"."\n");
    //   DB::rollback();
    // }
    // else{
    //  print("commit ?"."\n");
    //   DB::commit();
    // }
    $data_return['study_id'] = $study_id;
    $data_return['message'] = implode(", ",array_unique($data_return['message']));
    $data_return['output'] = $outputs;
    return $data_return;
  
}


function main($filepath,$email,$output_format,$verbose,$local){
    if ($verbose) {
        print("0. Setup : \n\tfilepath : $filepath\n\temail : $email\n\tOutput : $output_format\n\tVerbose : $verbose\n\tlocal : $local\n");
    }
    $logs = array('status'=>'SUCCESS','errors'=>array(),'message'=>null);
    $user_id = null;
      
    if(!$local){    
        //GET USER from email
        $user_id = DB::queryFirstField('SELECT id from "user" where email = %s or external_id = %s',$email,$email);
        if(!$user_id){ return array('status'=>'FAIL','message'=>"Email '$email' doesn't belongs to any user\n"); }
        if($verbose){ print("\tUser with email '$email' exists\n"); }
    }

    //CHECK FILE
    if(!file_exists($filepath)){ return array('status'=>'FAIL','message'=>"ERROR. File '$filepath' doesn't exist\n"); }
    if($verbose){ print("----------------\n\n1. Check files and parse\n\tFile '$filepath' exists\n"); }
      
    $info = pathinfo($filepath);
    if($info['extension'] != 'zip'){ return array('status'=>'FAIL','message'=>"ERROR. File '$filepath' shoud be a zip archive\n"); }
    if($verbose){ print("\tFile '$filepath' is a zip. ".json_encode($info)."\n"); }
      
    //UNZIP FILE 
    //TODO : I have problem when package directory has not the same name of zip file...
    $package_dir = $info['dirname'];
    chdir($package_dir);
    $cmd = "unzip -q -j ".basename($filepath)." 2>/dev/null";
    passthru($cmd);
    // $zip = new ZipArchive;
    // if ($zip->open($filepath) === TRUE) {
    //     $zip->extractTo($info['dirname']);
    //     $zip->close();
    //     if($verbose){ print("\tZIP unzipped\n"); }
    // } else {
    //     return array('status'=>'FAIL','message'=>"File unzip fail\n");
    //  }
    // $zipFilePath = $dir."/".basename($filepath);
    // $extractToDir = $dir;
    //
    // $zip = new ZipArchive;
    // if ($zip->open($zipFilePath) === TRUE) {
    //     for ($i = 0; $i < $zip->numFiles; $i++) {
    //         $fileInfo = $zip->statIndex($i);
    //         $fileName = basename($fileInfo['name']);
    //         if (!is_dir($fileName)) {
    //             copy("zip://{$zipFilePath}#{$fileInfo['name']}", $extractToDir . $fileName);
    //         }
    //     }
    //     $zip->close();
    // } else {
    //     return array('status'=>'FAIL','message'=>"File unzip fail\n");
    // }
    //GET RESOURCE TYPE in good order 
    $types = getResourceTypes();
    if(!$types){ return array('status'=>'FAIL','message'=>"get resource types failed\n"); }
    if ($verbose) {
        print("\tOfficial types : ".json_encode($types)."\n");
    }
      
    //PARSE MANIFEST
    $tmp_parseManifest = parseManifest($package_dir,$types,$local,$verbose);
    if($tmp_parseManifest['status']=='FAIL'){
        return $tmp_parseManifest;
    }
    $metadata = $tmp_parseManifest['data'];
    if ($verbose) {
        print("\tMatching type - filename : ".json_encode($metadata)."\n");
    }
      
    //FOREACH types, validate data by running import_resources.php
    if ($verbose) {
        print("----------------\n\n2. Loop on resources : VALIDATION\n");
    }
    $logs = loopOnResources($verbose,$logs,$types,$metadata,$package_dir,$output_format,true,$email);
      
    if (isset($logs['status']) && $logs['status']=='FAIL') {
        return $logs;
    }
    if(!$local){
        //FOREACH types, insert data by running import_resources.php
        if ($verbose) {
            print("----------------\n\n3. Loop on resources : INSERTION\n");
        }
        $logs = loopOnResources($verbose,$logs,$types,$metadata,$package_dir,$output_format,false,$email);
        if ($logs['status']=='FAIL') {
            return $logs;
        }
    }

    $study_id = $logs['study_id'];
      
    $from = $info['dirname'];
    $dir_info = pathinfo($from);
    $to = str_replace($dir_info['basename'],$study_id,$from);
    if(!$local){    
        $cmd = "mv $from $to";
        // exec($cmd);
        if ($verbose) {
            print("---  ".$cmd."\n");
        }
    }

     if ($verbose) {
         print("\n======================= OUTPUT : \n");
     }

     return $logs;    
}



function print_usage($local = false){
    $types = '';
    if($local && file_exists("data/resource_type_list.txt")){
        $types .=  file_get_contents("data/resource_type_list.txt");
    }
    else{     
        $tmp_types = DB::queryFirstColumn("SELECT resource_type.name as resourceType from resource_type where properties is not null and public_id_prefix is not null and validator_mandatory is true order by name");
        if ($tmp_types){
            $types .= implode(", ",$tmp_types); 
        }
    }
    fwrite(STDOUT,"usage: php ".basename(__FILE__)." -f <filepath> -u <user_id> [-o <output_format>] [--validate] [-v] [-h]".PHP_EOL);
      
    fwrite(STDOUT,"  -f <filepath>        : path of the zipfile to import. File format is .zip".PHP_EOL);
    fwrite(STDOUT,"  -u <user_id>         : User ID (email or EduID) of user. Optional if validation mode".PHP_EOL);
    fwrite(STDOUT,"  -o <output_format>   : json or tsv. Default: json".PHP_EOL);
    fwrite(STDOUT,"  --validate           : validate. No insertion. Just validate your dataset".PHP_EOL);
    fwrite(STDOUT,"  -v                   : verbose".PHP_EOL);
    fwrite(STDOUT,"  -h                   : show this help".PHP_EOL);
    fwrite(STDOUT,"Zip file should contain a manifest file to do matching between name of the file and name of the resource (manifest.tsv) and one file by required ressources : $types. Study should be complete.".PHP_EOL);
    return true;
}
