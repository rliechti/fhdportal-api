<?php

require __DIR__ . '/include.php';
$validator = new JsonSchema\Validator();
$json_schemas = DB::queryFirstField("SELECT properties from resource_type where id = 3");



if (!$json_schemas) {
    throw new Exception("Unknown schemas", 500);
}
$schemas = json_decode($json_schemas);
if (!isset($schemas->data_schema)) {
    throw new Exception("Unknown data_schema", 500);
}

$json = '{"title":"IMMUcan non-commercial use","url":"\\/policies\\/create","dac_id":"8d43fe50-5104-4eaf-942c-a92cee2a988b","description":"A policy to enable use of fake IMMUcan data for non-commercial use."}';
$policy = json_decode($json);

// Validate the data against the schema

$json = '{"title":"IMMUcan non-commercial use","url":"\\/policies\\/create","dac_id":"8d43fe50-5104-4eaf-942c-a92cee2a988b","description":"A policy to enable use of fake IMMUcan data for non-commercial use."}';
$schema = '{"type":"object","title":"Policy","$schema":"http:\\/\\/json-schema.org\\/draft-07\\/schema#","required":["title"],"properties":{"url":{"type":"string","description":"URL of the Policy"},"title":{"type":"string","description":"Name of the Policy"},"dac_id":{"type":"string","format":"uuid"},"public_id":{"type":"string","pattern":"^CHFEGAP[0-9]{11}","description":"Policy public_id"},"description":{"type":"string","description":"The policy content"}}}';
$policy = json_decode($json);
$test = json_decode($schema);
$validator->validate($policy, $test);
if ($validator->isValid()) {
    echo "The supplied JSON validates against the schema.\n";
} else {
    echo "JSON does not validate. Violations:\n";
    foreach ($validator->getErrors() as $error) {
        printf("[%s] %s\n", $error['property'], $error['message']);
    }
}
