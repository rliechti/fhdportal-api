<?php
function uuidv4()
{
  $data = random_bytes(16);

  $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // set version to 0100
  $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // set bits 6-7 to 10
    
  return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}
require __DIR__ . '/include.php';
$relationship_rule_id = 16;
$runs = DB::query("SELECT * from resource where resource_type_id = 11");
foreach($runs as $r){
    $props = json_decode($r['properties'],true);
    $file_ids = $props['sdafile_public_ids'];
    foreach($file_ids as $file_id){
        $f = DB::queryFirstRow("SELECT * from resource where properties->>'public_id' = %s",$file_id);
        if ($f['id']){
            $relationship = array(
                "id" => uuidv4(),
                "relationship_rule_id"=> 16,
                "domain_resource_id"=> $f['id'],
                "predicate_id"=> 1,
                "range_resource_id"=> $r['id'],
                "sequence_number"=> 1,
                "is_active"=> true
            );
            $id = DB::queryFirstField("SELECT id from relationship where domain_resource_id = %s_domain_resource_id and range_resource_id = %s_range_resource_id",$relationship);
            if (!$id){
                DB::insert("relationship",$relationship);                
            }
        }
    }
}
?>