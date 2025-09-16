<?php

require __DIR__ . '/include.php';
DB::debugMode(true);
$resources = DB::query("SELECT id, properties from resource_20250121");
foreach ($resources as $r) {
    DB::update("resource", array("properties" => $r['properties']), "id = %s", $r['id']);
}
// exit;
function updatePublicId($public_id)
{
    if (preg_match("/[^\d]+(\d+)$/", $public_id, $matches)) {
        $resource = DB::queryFirstRow("SELECT
		resource.id,
		resource.properties,
		resource_type.public_id_prefix
	FROM
		resource
		inner join resource_type on resource.resource_type_id = resource_type.id
	WHERE
		resource.properties ->> 'public_id' = %s", $public_id);
        return $resource['public_id_prefix'].str_pad(+$matches[1], 11, '0', STR_PAD_LEFT);
    }
    return null;
}

$updates = array();

$resources = DB::query("SELECT
	resource.id,
	resource.properties,
	resource_type_id,
	resource_type.public_id_prefix
FROM
	resource inner join resource_type on resource.resource_type_id = resource_type.id");
foreach ($resources as $r) {
    $props = json_decode($r['properties'], true);
    // print_r($props);
    foreach ($props as $k => $v) {
        if (strpos($k, 'public_id') !== false) {
            if (is_array($v)) {
                foreach ($v as $idx => $d) {
                    $new_public_id = updatePublicId($d);
                    $updates[$d] = $new_public_id;
                    $props[$k][$idx] = $new_public_id;
                }
            } else {
                $new_public_id = updatePublicId($v);
                $updates[$v] = $new_public_id;
                if ($k !== 'public_id') {
                    $props[$k] = $new_public_id;
                }
            }
        }
    }
    $properties = json_encode($props);
    DB::update("resource", array("properties" => $properties), "id = %s", $r['id']);
}
foreach ($updates as $old => $new) {
    DB::query("UPDATE resource SET properties = jsonb_set(properties, '{public_id}', '\"".$new."\"', false) WHERE properties ->> 'public_id' = %s", $old);
    DB::query("UPDATE resource SET properties = jsonb_set(properties, '{title}', '\"".$new."\"', false) WHERE properties ->> 'title' = %s", $old);
}
