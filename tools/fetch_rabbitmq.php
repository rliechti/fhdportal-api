<?php

require __DIR__ . '/include.php';
require __DIR__ . '/keycloak.php';
use Ramsey\Uuid\Uuid;

function updateResourceStatus($user_id, $filepath, $status, $comment = "")
{
    echo $filepath." => ".$status.PHP_EOL;
    $dbresources = DB::query("SELECT
        resource.id,
        resource.properties,
        resource.properties ->> 'public_id' as public_id
    FROM
        resource
        INNER JOIN resource_type ON resource.resource_type_id = resource_type.id
        AND resource_type.\"name\" = 'SdaFile'
        inner join resource_acl on resource.id = resource_acl.resource_id and resource_acl.user_id = %i
    where coalesce(resource.properties->>'filepath'::text,'') = %s
    ", $user_id, $filepath);
    if (!$dbresources) {
        fwrite(STDERR, "Error: file ".$filepath." is unknown".PHP_EOL);
    }
    foreach ($dbresources as $dbresource) {
        DB::update("resource", array("status_type_id" => $status), "id = %s", $dbresource['id']);
        $uuid = Uuid::uuid4();
        $log_id = $uuid->toString();
        $log = array(
            "id" => $log_id,
            "resource_id" => $dbresource['id'],
            "user_id" => $user_id,
            "action_type_id" => $status,
            "properties" => json_encode($dbresource['properties'])
        );
        if ($comment) {
            $log['comment'] = $comment;
        }
        DB::insert("resource_log", $log);
    }
}

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Connection\AMQPConnectionConfig;
use PhpAmqpLib\Connection\AMQPConnectionFactory;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Exchange\AMQPExchangeType;
use PhpAmqpLib\Wire\AMQPTable;

$mq_host        = $_SERVER['MQ_HOST'];
$mq_port        = $_SERVER['MQ_PORT'];
$mq_user        = $_SERVER['MQ_USER'];
$mq_pwd         = $_SERVER['MQ_PWD'];
$mq_vhost       = $_SERVER['MQ_VHOST'];
$mq_exchange    = $_SERVER['MQ_EXCHANGE'];
$mq_routing_key = $_SERVER['MQ_ROUTING_KEY'];
$config = new AMQPConnectionConfig();
$config->setHost($mq_host);
$config->setPort($mq_port);
$config->setUser($mq_user);
$config->setPassword($mq_pwd);
$config->setVhost($mq_vhost);
$isSecure = $mq_host != 'localhost';
$config->setIsSecure($isSecure);
$config->setSslVerify(false);
$factory = new AMQPConnectionFactory();
$connection = $factory->create($config);
$channel = $connection->channel();

echo " [*] Waiting for messages. To exit press CTRL+C\n";

$callbacks = array();

$callbacks['inbox'] = function ($msg) {
    global $channel;
    global $mq_exchange;
    $correlation_id = $msg->get("correlation_id");
    $data = json_decode($msg->body, true);
    $users = getKeyCloakUsers('', 'email='.$data['user']);
    $user = array_shift($users);
    $user_id = null;
    // DB::debugMode(true);
    if ($user['username']) {
        $user_id = DB::queryFirstField("SELECT id from \"user\" where external_id = %s", $user['username']);
    }
    if (!$user_id) {
        DB::insert("user", array("external_id" => $data['user']));
        $user_id = DB::insertId();
    }
    $role_id = DB::queryFirstField("SELECT id from \"role\" where name = 'owner'");
    if ($data['operation'] == 'upload') {
        $resourceProperties = array(
            "filesize" => isset($data['filesize']) ? +$data['filesize'] : -1,
            "title" => basename($data['filepath']),
            "filepath" => $data['filepath'],
            "file_last_modified" => +$data['file_last_modified'],
            "encrypted_checksums" => $data['encrypted_checksums']
        );
        $validator = new JsonSchema\Validator();
        $schema_json = DB::queryFirstField("SELECT properties from resource_type where name = 'SdaFile'");
        $schema = json_decode($schema_json);
        $properties = json_decode(json_encode($resourceProperties));
        $validator->validate($properties, $schema->data_schema);
        if ($validator->isValid()) {
            $ret = array('action_type_id' => null,'public_id' => null);
            $resource = array(
                "id" => null,
                "properties" => json_encode($properties),
                "resource_type_id" => DB::queryFirstField("SELECT id from resource_type where name = 'SdaFile'"),
                "status_type_id" => DB::queryFirstField("SELECT id from status_type where name = 'draft'")
            );
            $action_type_id = 'CRE';
            $checksums = array();
            foreach ($data['encrypted_checksums'] as $chs) {
                if ($chs['value']) {
                    $checksums[] = $chs['value'];
                }
            }
            $dbresource = null;
            if ($checksums !== []) {
                $dbresource = DB::queryFirstRow("SELECT
                    resource.id,
                    coalesce(resource.properties->>'filepath'::text,'') as filepath,
                    resource.properties ->> 'public_id' as public_id
                FROM
                    resource
                    INNER JOIN resource_type ON resource.resource_type_id = resource_type.id
                    AND resource_type.\"name\" = 'SdaFile'
                    inner join resource_acl on resource.id = resource_acl.resource_id and resource_acl.user_id = %i
                where coalesce(resource.properties->'encrypted_checksums'->>'value'::text,'') in %ls
                ", $user_id, $checksums);
            }
            if ($dbresource && $dbresource['filepath'] == $data['filepath']) {
                fwrite(STDERR, "Already exists".PHP_EOL);
                return;
            } elseif ($dbresource) { //UPDATE (RENAME)
                // TODO RENAME
                $resource['id'] = $dbresource['id'];
                $properties->public_id = $dbresource['public_id'];
                $resource['properties'] = json_encode($properties);
                $action_type_id = 'MOD';
            }
            if (!$resource['id']) {
                $uuid = Uuid::uuid4();
                $resource['id'] = $uuid->toString();
                DB::insert('resource', $resource);
                if ($role_id) {
                    $acl = array( "resource_id" => $resource['id'], "user_id" => $user_id, "role_id" => $role_id );
                    DB::insert('resource_acl', $acl);
                }
            } else {
                DB::update('resource', $resource, "id = %s", $resource['id']);
            }
            $uuid = Uuid::uuid4();
            $log_id = $uuid->toString();
            $log = array(
                "id" => $log_id,
                "resource_id" => $resource['id'],
                "user_id" => $user_id,
                "action_type_id" => $action_type_id,
                "properties" => $resource['properties']
            );
            DB::insert("resource_log", $log);
            DB::delete("rmq_correlation","correlation_id = %s",$correlation_id);
            DB::insert("rmq_correlation",array(
                "correlation_id" => $correlation_id,
                "resource_id" => $resource['id']
            ));
            echo "START INGEST".PHP_EOL;
            
            //START INGEST PROCESS //
            $ingest_msg = array(
                   "type" => "ingest",
                   "user" => $data['user'],
                   "filepath" => $properties->filepath,
                    "encrypted_checksums" => $properties->encrypted_checksums
            );            
            // fwrite(STDOUT,json_encode($ingest_msg).PHP_EOL);
            $ingest_msg = new AMQPMessage(
                json_encode($ingest_msg),
                array(
                    "correlation_id" => $correlation_id,
                    'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT
                )
            );
            $channel->basic_publish($ingest_msg, $mq_exchange ,'ingest');
            
            // fwrite(STDOUT,"SENT to INGEST QUEUE".PHP_EOL);
            
        } else {
            $content = '';
            print "\tJSON does not validate. Violations:\t";
            foreach ($validator->getErrors() as $error) {
                $content .= "[".$error['property']."]:". $error['message']."; ";
                print("\t". $error['property']."\t". $error['message']);
            }
            fwrite(STDERR, substr($content, 0, -2).PHP_EOL);
        }
    } elseif ($data['operation'] == 'rename') {
        if (!isset($data['oldpath']) || !$data['oldpath']) {
            // throw new Exception("Error. Cannot rename file with no name (oldpath)", 1);
            fwrite(STDERR, "Cannot rename file with no name (oldpath)".PHP_EOL);
        } elseif (!isset($data['filepath']) || !$data['filepath']) {
            fwrite(STDERR, "Cannot rename file: no new filepath provided".PHP_EOL);
        } else {
            $dbresource = DB::queryFirstRow("SELECT
                resource.id,
                resource.properties,
                resource.properties ->> 'public_id' as public_id
            FROM
                resource
                INNER JOIN resource_type ON resource.resource_type_id = resource_type.id
                AND resource_type.\"name\" = 'SdaFile'
                inner join resource_acl on resource.id = resource_acl.resource_id and resource_acl.user_id = %i
            where coalesce(resource.properties->>'filepath'::text,'') = %s
            ", $user_id, $data['oldpath']);
            if (!$dbresource) {
                fwrite(STDERR, "Error: resource is unknown".PHP_EOL);
            } else {
                $properties = json_decode($dbresource['properties'], true);
                $properties['filepath'] = $data['filepath'];
                DB::query("UPDATE resource set properties = properties::jsonb || '{\"filepath\":\"".$data['filepath']."\"}' where id = %s", $dbresource['id']);
                $uuid = Uuid::uuid4();
                $log_id = $uuid->toString();
                $log = array(
                    "id" => $log_id,
                    "resource_id" => $dbresource['id'],
                    "user_id" => $user_id,
                    "action_type_id" => "MOD",
                    "properties" => json_encode($properties)
                );
                DB::insert("resource_log", $log);
            }
        }
    } elseif ($data['operation'] == 'remove') {
        if (!isset($data['filepath']) || !$data['filepath']) {
            fwrite(STDERR, "Cannot remove file: no new filepath provided".PHP_EOL);
        } else {
            echo "REMOVE ".$data['filepath'].PHP_EOL;
            updateResourceStatus($user_id, $data['filepath'], "DEL", "deleted by user");
        }
    }
    $msg->ack();
};

$callbacks['error'] = function ($msg) {
    $data = json_decode($msg->body, true);
    $users = getKeyCloakUsers('', 'email='.$data['user']);
    if (!$users || !count($users)) {
        throw new Exception("Error: unknown user: ".$data['user'], 1);
    }
    $user = array_shift($users);
    $user_id = null;
    // DB::debugMode(true);
    if ($user['username']) {
        $user_id = DB::queryFirstField("SELECT id from \"user\" where external_id = %s", $user['username']);
    }
    if (!$user_id) {
        throw new Exception("Error: unknown user: ".$data['user'], 1);
    }
    echo "ERROR:  ".$data['filepath'].PHP_EOL;
    updateResourceStatus($user_id, $data['filepath'], "DEL", $data['reason']);
    $msg->ack();
};

$callbacks['verified'] = function ($msg) {
    global $channel;
    global $mq_exchange;
    $correlation_id = $msg->get("correlation_id");
    $data = json_decode($msg->body, true);
    $users = getKeyCloakUsers('', 'email='.$data['user']);
    if (!$users || !count($users)) {
        throw new Exception("Error: unknown user: ".$data['user'], 1);
    }
    $user = array_shift($users);
    $user_id = null;
    // DB::debugMode(true);
    if ($user['username']) {
        $user_id = DB::queryFirstField("SELECT id from \"user\" where external_id = %s", $user['username']);
    }
    if (!$user_id) {
        DB::insert("user", array("external_id" => $data['user']));
        $user_id = DB::insertId();
    }
    $resource_id = DB::queryFirstField("SELECT resource.id from resource inner join rmq_correlation on resource.id = rmq_correlation.resource_id where resource.properties->>'filepath'::text = %s and rmq_correlation.correlation_id::text = %s;",$data['filepath'],$correlation_id);
    if ($resource_id){
        $accession_id = DB::queryFirstField("SELECT public_id from sdafile_view where id = %s",$resource_id);
        // HACK TO REMOVE INSTANCE PREFIX FROM EGA ID //
        // TODO REPLACE BY .env constant
        $accession_id = str_replace("CHFEGAF","EGAF",$accession_id);
        
        updateResourceStatus($user_id, $data['filepath'], 'VER');        
        //START ACCESSION PROCESS //
        $accession_msg = array(
               "type" => "accession",
               "user" => $data['user'],
               "filepath" => $data['filepath'],
               "accession_id" => $accession_id,
               "decrypted_checksums" => $data['decrypted_checksums']
        );            
        // fwrite(STDOUT,json_encode($accession_msg).PHP_EOL);
        $accession_msg = new AMQPMessage(
            json_encode($accession_msg),
            array(
                "correlation_id" => $correlation_id,
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT
            )
        );
        $channel->basic_publish($accession_msg, $mq_exchange ,'accession');
        // fwrite(STDOUT,"SENT to ACCESSION QUEUE".PHP_EOL);
        
    }
    else{
        echo "Error: correlation_id: ".$correlation_id." and filepath: ".$data['filepath']." do not match";
    }

    $msg->ack();
};

$callbacks['completed'] = function ($msg) {
    $data = json_decode($msg->body, true);
    $users = getKeyCloakUsers('', 'email='.$data['user']);
    if (!$users || !count($users)) {
        throw new Exception("Error: unknown user: ".$data['user'], 1);
    }
    $user = array_shift($users);
    $user_id = null;
    // DB::debugMode(true);
    if ($user['username']) {
        $user_id = DB::queryFirstField("SELECT id from \"user\" where external_id = %s", $user['username']);
    }
    if (!$user_id) {
        DB::insert("user", array("external_id" => $data['user']));
        $user_id = DB::insertId();
    }
    updateResourceStatus($user_id, $data['filepath'], 'PUB');
    $msg->ack();
};


/*
$exchange : string
$type : string
$passive : bool = false
$durable : bool = false
$auto_delete : bool = true
$internal : bool = false
$nowait : bool = false
$arguments : AMQPTable|array<string|int, mixed> = array()
$ticket : int|null = null
*/

$channel->exchange_declare($mq_exchange, AMQPExchangeType::TOPIC, false, true, false, false, false, new AMQPTable(array("alternate-exchange" => "localega.dead")));
$routing_keys = array(
    "files.completed" => "completed",
    "files.error" => "error",
    "files.inbox" => "inbox",
    "files.verified" => "verified"
);
foreach ($routing_keys as $routing_key => $queue) {
    /*
    $queue : string = ''
    $passive : bool = false
    $durable : bool = false
    $exclusive : bool = false
    $auto_delete : bool = true
    $nowait : bool = false
    $arguments : array<string|int, mixed>|AMQPTable = array()
    $ticket : int|null = null
    */
    $channel->queue_declare($queue, false, true, false, false, false, new AMQPTable(array("x-dead-letter-exchange" => "localega.dead")));
    $channel->queue_bind($queue, $mq_exchange, $routing_key, false, new AMQPTable(array("x-dead-letter-exchange" => "localega.dead")));
}
$queues = array_unique(array_values($routing_keys));
foreach ($queues as $queue) {
    $channel->basic_consume($queue, '', false, false, false, false, $callbacks[$queue]);
}

try {
    $channel->consume();
} catch (\Throwable $exception) {
    echo $exception->getMessage();
}

//
// while ($channel->callbacks) {
//     $channel->wait();
// }
// $queues = array("inbox","ingest");
// foreach ($queues as $queue) {
//     $channel->queue_declare($queue, false, false, false, false);
//     $channel->basic_consume($queue, '', false, true, false, false, $callbacks[$queue]);
// }


while ($channel->callbacks) {
    $channel->wait();
}

$channel->close();
$connection->close();
