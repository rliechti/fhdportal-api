<?php

/**
 * RabbitMQ Consumer — SDA File Manager (verbose)
 *
 * Same behavior as fetch_rabbitmq.php, but every decision point logs what it
 * saw and what it decided, to make debugging pipeline/queue issues easier.
 * Set MQ_VERBOSE=0 to silence the extra logDebug() output and fall back to
 * the same log volume as the non-verbose script.
 *
 * Queues consumed:
 *  - files.inbox                  : file uploaded / renamed / removed by the user
 *  - files.verified               : file verified by the SDA pipeline
 *  - files.completed              : file published with an accession ID
 *  - files.error                  : error reported by the SDA pipeline
 *  - dataset.event                : files are archived
 *  - swiss.doa.operation.response : doa response (download)
 *  - swiss.doa.error              : doa error (download)
 *
 * Action codes stored in the database:
 *  - CRE : resource created
 *  - MOD : resource modified / renamed
 *  - VER : verification successful
 *  - REG : file registered to a dataset
 *  - PUB : file published
 *  - DEL : file deleted or rejected
 */

require __DIR__ . '/include.php';
require __DIR__ . '/keycloak.php';

use Ramsey\Uuid\Uuid;
use PhpAmqpLib\Connection\AMQPConnectionConfig;
use PhpAmqpLib\Connection\AMQPConnectionFactory;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Exchange\AMQPExchangeType;
use PhpAmqpLib\Wire\AMQPTable;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

const VERBOSE = false;

/**
 * Checks whether a string is a valid UUID (versions 1-5).
 */
function checkUuid(string $string): bool
{
    return (bool) preg_match(
        '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-5][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$/',
        $string
    );
}

/**
 * Generates a UUID v4 and returns it as a string.
 */
function newUuid(): string
{
    return Uuid::uuid4()->toString();
}

/**
 * Prints a timestamped message to stdout.
 */
function logInfo(string $message): void
{
    echo '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
}

/**
 * Prints a timestamped debug message to stdout, only when VERBOSE is enabled.
 * Used for step-by-step tracing: what was received, what was decided, what
 * query ran and what it returned.
 */
function logDebug(string $message, array $context = []): void
{
    if (!VERBOSE) {
        return;
    }
    $suffix = $context ? ' ' . json_encode($context, JSON_UNESCAPED_SLASHES) : '';
    echo '[' . date('Y-m-d H:i:s') . '] DEBUG: ' . $message . $suffix . PHP_EOL;
}

/**
 * Prints a timestamped error message to stderr.
 */
function logError(string $message): void
{
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] ERROR: ' . $message . PHP_EOL);
}

/**
 * Renders an AMQP message's routing key, correlation id and body for tracing.
 */
function logIncomingMessage(string $queue, AMQPMessage $msg): void
{
    $correlationId = $msg->has('correlation_id') ? $msg->get('correlation_id') : null;
    $routingKey    = $msg->getRoutingKey();
    logDebug("<- [$queue] received message", [
        'routing_key'    => $routingKey,
        'correlation_id' => $correlationId,
        'body'           => $msg->body,
    ]);
}

// ---------------------------------------------------------------------------
// Database resource management
// ---------------------------------------------------------------------------

/**
 * Resolves the internal user ID from a Keycloak email address.
 * Creates the user row if it does not exist yet.
 *
 * @param  string   $email  User email address (Keycloak identifier)
 * @return int|null         Internal user ID, or null if not found
 */
function resolveUserId(string $email): ?int
{
    logDebug('resolveUserId: looking up Keycloak user', ['email' => $email]);
    $users = getKeyCloakUsers('', 'email=' . $email);
    $user  = array_shift($users);

    if (empty($user['username'])) {
        logDebug('resolveUserId: no Keycloak match, will fall back to email as external_id', ['email' => $email]);
    } else {
        logDebug('resolveUserId: Keycloak match found', ['email' => $email, 'username' => $user['username']]);
    }

    $userId = null;
    if (!empty($user['username'])) {
        $userId = DB::queryFirstField(
            'SELECT id FROM "user" WHERE external_id = %s',
            $user['username']
        );
        logDebug('resolveUserId: local user lookup by external_id', ['external_id' => $user['username'], 'user_id' => $userId]);
    }

    if (!$userId) {
        logDebug('resolveUserId: no local user row yet, inserting one', ['external_id' => $email]);
        DB::insert('user', ['external_id' => $email]);
        $userId = DB::insertId();
        logDebug('resolveUserId: inserted new user', ['user_id' => $userId]);
    }

    return $userId ?: null;
}

/**
 * Updates the status of an SdaFile resource identified by its filepath,
 * optionally merges new properties into the existing JSON blob, and writes
 * an action log entry.
 *
 * @param int    $userId         Internal ID of the owning user
 * @param string $filepath       File path inside the SDA
 * @param string $status         New status / action code (e.g. VER, DEL, PUB, REG)
 * @param array  $newProperties  Properties to merge into the JSON blob (optional)
 * @param string $comment        Free-text comment to store in the log (optional)
 */
function updateResourceStatus(
    int    $userId,
    string $filepath,
    string $status,
    array  $newProperties = [],
    string $comment = ''
): void {
    logInfo($filepath . ' => ' . $status);
    logDebug('updateResourceStatus: looking up resource(s)', [
        'user_id'  => $userId,
        'filepath' => $filepath,
        'status'   => $status,
    ]);

    $resources = DB::query(
        "SELECT
            resource.id,
            resource.properties,
            resource.properties ->> 'public_id' AS public_id
        FROM resource
        INNER JOIN resource_type
            ON resource.resource_type_id = resource_type.id
            AND resource_type.\"name\" = 'SdaFile'
        INNER JOIN resource_acl
            ON resource.id = resource_acl.resource_id
            AND resource_acl.user_id = %i
        WHERE COALESCE(resource.properties->>'filepath'::text, '') = %s",
        $userId,
        $filepath
    );

    if (!$resources) {
        logError('File ' . $filepath . ' is unknown');
        logDebug('updateResourceStatus: no matching resource found, aborting', ['filepath' => $filepath, 'user_id' => $userId]);
        return;
    }

    logDebug('updateResourceStatus: matched resource(s)', ['count' => count($resources), 'ids' => array_column($resources, 'id')]);

    foreach ($resources as $resource) {
        $jsonProperties = $resource['properties'];

        // Merge new properties into the existing JSON blob if provided
        if ($newProperties) {
            logDebug('updateResourceStatus: merging new properties', ['resource_id' => $resource['id'], 'new_properties' => $newProperties]);
            $props = json_decode($jsonProperties, true);
            foreach ($newProperties as $key => $value) {
                $props[$key] = $value;
            }
            $jsonProperties = json_encode($props);
        }

        DB::update('resource', ['status_type_id' => $status], 'id = %s', $resource['id']);
        logDebug('updateResourceStatus: status updated', ['resource_id' => $resource['id'], 'status' => $status]);

        if ($newProperties) {
            DB::update('resource', ['properties' => $jsonProperties], 'id = %s', $resource['id']);
            logDebug('updateResourceStatus: properties updated', ['resource_id' => $resource['id']]);
        }

        // Write the action log entry
        $log = [
            'id'             => newUuid(),
            'resource_id'    => $resource['id'],
            'user_id'        => $userId,
            'action_type_id' => $status,
            'properties'     => $jsonProperties,
        ];
        if ($comment) {
            $log['comment'] = $comment;
        }
        DB::insert('resource_log', $log);
        logDebug('updateResourceStatus: log entry written', ['resource_id' => $resource['id'], 'log_id' => $log['id'], 'comment' => $comment]);
    }
}

/**
 * Registers a resource that failed during the SDA pipeline.
 *
 * If the file is already known in the database its status is set to DEL with
 * the error message stored as a comment. Otherwise a new resource is created
 * directly in the "rejected" status.
 *
 * @param int   $userId  Internal user ID
 * @param array $data    Error event payload
 */
function registerErrorResource(int $userId, array $data): void
{
    logDebug('registerErrorResource: entry', ['user_id' => $userId, 'data' => $data]);

    if (empty($data['filepath'])) {
        logInfo('Unknown error => ' . ($data['error_message'] ?? '(no message)'));
        logDebug('registerErrorResource: no filepath in payload, cannot proceed');
        return;
    }

    $existingResources = DB::query(
        "SELECT resource.id
        FROM resource
        INNER JOIN resource_type
            ON resource.resource_type_id = resource_type.id
            AND resource_type.\"name\" = 'SdaFile'
        INNER JOIN resource_acl
            ON resource.id = resource_acl.resource_id
            AND resource_acl.user_id = %i
        WHERE COALESCE(resource.properties->>'filepath'::text, '') = %s",
        $userId,
        $data['filepath']
    );

    logDebug('registerErrorResource: existing resource lookup', ['filepath' => $data['filepath'], 'found' => count($existingResources)]);

    if ($existingResources) {
        // Resource already exists: mark it as deleted / rejected
        $errors = [];
        foreach($data as $k => $v){
            if (strpos($k,'error') !== FALSE || strpos($k,'reason') !== FALSE){
                $errors[] = $k.": ".$v;
            }
        }
        $error = implode(". ",$errors);
        logDebug('registerErrorResource: resource already known, marking DEL', ['error' => $error]);
        updateResourceStatus($userId, $data['filepath'], 'DEL', [], $error);
        return;
    }

    // Resource is unknown: create it directly in the "rejected" status
    $resourceProperties = [
        'filesize'            => isset($data['filesize']) ? (int) $data['filesize'] : -1,
        'title'               => basename($data['filepath']),
        'filepath'            => $data['filepath'],
        'file_last_modified'  => (int) strtotime($data['timestamp'] ?? ''),
        'encrypted_checksums' => [
            ['type' => 'sha256', 'value' => $data['expected_checksum'] ?? ''],
        ],
    ];
    logDebug('registerErrorResource: resource unknown, building new draft properties', $resourceProperties);

    // Validate against the SdaFile JSON schema before inserting
    $validator  = new JsonSchema\Validator();
    $schemaJson = DB::queryFirstField("SELECT properties FROM resource_type WHERE name = 'SdaFile'");
    $schema     = json_decode($schemaJson);
    $properties = json_decode(json_encode($resourceProperties));
    $validator->validate($properties, $schema->data_schema);

    if (!$validator->isValid()) {
        logInfo('Wrong sda-file schema => ' . $data['error_message']);
        echo "===================================================\n";
        foreach ($validator->getErrors() as $error) {
            printf("[%s] %s\n", $error['property'], $error['message']);
        }
        echo "===================================================\n";
        logDebug('registerErrorResource: schema validation failed, aborting insert');
        return;
    }

    logDebug('registerErrorResource: schema validation passed');

    $resourceId       = newUuid();
    $jsonProperties   = json_encode($properties);
    $rejectedStatusId = DB::queryFirstField("SELECT id FROM status_type WHERE name = 'rejected'");
    $resourceTypeId   = DB::queryFirstField("SELECT id FROM resource_type WHERE name = 'SdaFile'");

    DB::insert('resource', [
        'id'               => $resourceId,
        'properties'       => $jsonProperties,
        'resource_type_id' => $resourceTypeId,
        'status_type_id'   => $rejectedStatusId,
    ]);
    logDebug('registerErrorResource: resource inserted', ['resource_id' => $resourceId, 'status_type_id' => $rejectedStatusId]);

    DB::insert('resource_acl', [
        'resource_id' => $resourceId,
        'user_id'     => $userId,
        'role_id'     => 'OWN',
    ]);
    logDebug('registerErrorResource: ACL inserted', ['resource_id' => $resourceId, 'user_id' => $userId, 'role_id' => 'OWN']);

    DB::insert('resource_log', [
        'id'             => newUuid(),
        'resource_id'    => $resourceId,
        'user_id'        => $userId,
        'action_type_id' => 'DEL',
        'properties'     => $jsonProperties,
        'comment'        => $data['error_message'],
    ]);
    logDebug('registerErrorResource: log entry written', ['resource_id' => $resourceId]);

    logInfo('ERROR: ' . basename($data['filepath']) . ' => ' . $data['error_message']);
}

// ---------------------------------------------------------------------------
// RabbitMQ connection
// ---------------------------------------------------------------------------

logDebug('Connecting to RabbitMQ', [
    'host'  => $_ENV['MQ_HOST'],
    'port'  => $_ENV['MQ_PORT'],
    'user'  => $_ENV['MQ_CONSUMER_USER'],
    'vhost' => $_ENV['MQ_CONSUMER_PWD'],
]);

$config = new AMQPConnectionConfig();
$config->setHost($_ENV['MQ_HOST']);
$config->setPort($_ENV['MQ_PORT']);
$config->setUser($_ENV['MQ_CONSUMER_USER']);
$config->setPassword($_ENV['MQ_CONSUMER_PWD']);
$config->setVhost($_ENV['MQ_VHOST']);

// Enable TLS for any host other than localhost
$isSecure = ($_ENV['MQ_HOST'] !== 'localhost');
$config->setIsSecure($isSecure);
$config->setSslVerify(false);
logDebug('TLS configuration', ['is_secure' => $isSecure, 'ssl_verify' => false]);

$connection = (new AMQPConnectionFactory())->create($config);
$channel    = $connection->channel();
logInfo('Connected to RabbitMQ at ' . $_ENV['MQ_HOST'] . ':' . $_ENV['MQ_PORT'] . ' (vhost=' . $_ENV['MQ_VHOST'] . ')');

$mq_exchange = $_ENV['MQ_EXCHANGE'];

echo " [*] Waiting for messages. To exit press CTRL+C\n";

// ---------------------------------------------------------------------------
// Queue callbacks
// ---------------------------------------------------------------------------

$callbacks = [];

/**
 * Queue: files.inbox
 *
 * Handles operations triggered by the user from the SDA inbox:
 *  - upload : registers the file in the database and triggers ingestion
 *  - rename : updates the filepath in the database
 *  - remove : marks the file as deleted
 */
$callbacks['inbox'] = function (AMQPMessage $msg) use ($channel, $mq_exchange): void {
    logIncomingMessage('inbox', $msg);

    $data          = json_decode($msg->body, true);
    $correlationId = $msg->has('correlation_id') ? $msg->get('correlation_id') : null;

    logDebug('inbox: dispatching operation', ['operation' => $data['operation'] ?? '(none)', 'user' => $data['user'] ?? '(none)']);

    $userId        = resolveUserId($data['user']);

    if (!$userId) {
        logError('Cannot resolve user: ' . $data['user']);
        $msg->ack();
        return;
    }

    logDebug('inbox: resolved user', ['email' => $data['user'], 'user_id' => $userId]);

    switch ($data['operation']) {

        // -------------------------------------------------------------------
        case 'upload':
            logDebug('inbox/upload: building resource properties', ['filepath' => $data['filepath']]);
            $resourceProperties = [
                'filesize'            => isset($data['filesize']) ? (int) $data['filesize'] : -1,
                'title'               => basename($data['filepath']),
                'filepath'            => $data['filepath'],
                'file_last_modified'  => (int) $data['file_last_modified'],
                'encrypted_checksums' => $data['encrypted_checksums'],
            ];
            // Validate the payload against the SdaFile JSON schema
            $validator  = new JsonSchema\Validator();
            $schemaJson = DB::queryFirstField("SELECT properties FROM resource_type WHERE name = 'SdaFile'");
            $schema     = json_decode($schemaJson);
            $properties = json_decode(json_encode($resourceProperties));
            $validator->validate($properties, $schema->data_schema);

            if (!$validator->isValid()) {
                $errors = '';
                foreach ($validator->getErrors() as $error) {
                    $errors .= '[' . $error['property'] . ']: ' . $error['message'] . '; ';
                    print("\t" . $error['property'] . "\t" . $error['message']);
                }
                logError(rtrim($errors, '; '));
                logDebug('inbox/upload: schema validation failed, dropping message', ['errors' => rtrim($errors, '; ')]);
                break;
            }

            logDebug('inbox/upload: schema validation passed');

            // Look for a duplicate by checksum (identical re-upload or rename)
            $checksums = array_filter(
                array_column($data['encrypted_checksums'], 'value')
            );

            $existingResource = null;
            if ($checksums) {
                logDebug('inbox/upload: looking for existing resource by checksum', [
                    'checksums'      => array_values($checksums),
                    'correlation_id' => $correlationId,
                ]);
                if ($correlationId){
                    $existingResource = DB::queryFirstRow(
                        "SELECT
                            resource.id,
                            COALESCE(resource.properties->>'filepath'::text, '') AS filepath,
                            resource.properties ->> 'public_id' AS public_id
                        FROM resource
                        INNER JOIN resource_type
                            ON resource.resource_type_id = resource_type.id
                            AND resource_type.\"name\" = 'SdaFile'
                        INNER JOIN resource_acl
                            ON resource.id = resource_acl.resource_id
                            AND resource_acl.user_id = %i
                        INNER JOIN rmq_correlation on rmq_correlation.resource_id = resource.id and rmq_correlation.correlation_id = %s
                        WHERE COALESCE(resource.properties->'encrypted_checksums'->>'value'::text, '') IN %ls",
                        $userId,
                        $correlationId,
                        array_values($checksums)
                    );
                }
                else {
                    $existingResource = DB::queryFirstRow(
                        "SELECT
                            resource.id,
                            COALESCE(resource.properties->>'filepath'::text, '') AS filepath,
                            resource.properties ->> 'public_id' AS public_id
                        FROM resource
                        INNER JOIN resource_type
                            ON resource.resource_type_id = resource_type.id
                            AND resource_type.\"name\" = 'SdaFile'
                        INNER JOIN resource_acl
                            ON resource.id = resource_acl.resource_id
                            AND resource_acl.user_id = %i
                        WHERE COALESCE(resource.properties->'encrypted_checksums'->>'value'::text, '') IN %ls",
                        $userId,
                        array_values($checksums)
                    );
                }
                logDebug('inbox/upload: existing resource lookup result', ['found' => (bool) $existingResource, 'resource' => $existingResource]);
            }

            if ($existingResource && $existingResource['filepath'] === $data['filepath']) {
                // Same checksum and same path: already exists, nothing to do
                logError('Already exists');
                logDebug('inbox/upload: identical checksum and filepath, no-op');
                break;
            }

            $actionTypeId = 'CRE';
            $resourceId   = null;

            if ($existingResource) {
                // Same checksum but different path: treat as a rename
                $resourceId            = $existingResource['id'];
                $properties->public_id = $existingResource['public_id'];
                $actionTypeId          = 'MOD';
                logDebug('inbox/upload: same checksum, different filepath -> treating as rename', [
                    'resource_id' => $resourceId,
                    'old_filepath' => $existingResource['filepath'],
                    'new_filepath' => $data['filepath'],
                ]);
            } else {
                logDebug('inbox/upload: no matching checksum -> creating new resource');
            }

            $jsonProperties = json_encode($properties);
            $resourceTypeId = DB::queryFirstField("SELECT id FROM resource_type WHERE name = 'SdaFile'");
            $draftStatusId  = DB::queryFirstField("SELECT id FROM status_type WHERE name = 'draft'");

            $resourceRow = [
                'id'               => $resourceId,
                'properties'       => $jsonProperties,
                'resource_type_id' => $resourceTypeId,
                'status_type_id'   => $draftStatusId,
            ];

            if (!$resourceId) {
                // Brand-new resource: insert it and assign the owner ACL
                $resourceRow['id'] = newUuid();
                DB::insert('resource', $resourceRow);
                logDebug('inbox/upload: inserted new resource', ['resource_id' => $resourceRow['id']]);

                $roleId = DB::queryFirstField("SELECT id FROM \"role\" WHERE name = 'owner'");
                if ($roleId) {
                    DB::insert('resource_acl', [
                        'resource_id' => $resourceRow['id'],
                        'user_id'     => $userId,
                        'role_id'     => $roleId,
                    ]);
                    logDebug('inbox/upload: owner ACL granted', ['resource_id' => $resourceRow['id'], 'user_id' => $userId, 'role_id' => $roleId]);
                } else {
                    logDebug('inbox/upload: no "owner" role found, ACL not created');
                }
            } else {
                // Existing resource (rename): update it in place
                DB::update('resource', $resourceRow, 'id = %s', $resourceId);
                logDebug('inbox/upload: updated existing resource in place', ['resource_id' => $resourceId]);
            }

            DB::insert('resource_log', [
                'id'             => newUuid(),
                'resource_id'    => $resourceRow['id'],
                'user_id'        => $userId,
                'action_type_id' => $actionTypeId,
                'properties'     => $jsonProperties,
            ]);
            logDebug('inbox/upload: log entry written', ['resource_id' => $resourceRow['id'], 'action_type_id' => $actionTypeId]);

            // Store the correlation so we can match the pipeline response later
            if ($correlationId){
                DB::delete('rmq_correlation', 'correlation_id = %s', $correlationId);
                DB::insert('rmq_correlation', [
                    'correlation_id' => $correlationId,
                    'resource_id'    => $resourceRow['id'],
                ]);
                logDebug('inbox/upload: correlation stored', ['correlation_id' => $correlationId, 'resource_id' => $resourceRow['id']]);

                // Forward the ingestion request to the SDA pipeline
                $ingestPayload = json_encode([
                    'type'                => 'ingest',
                    'user'                => $data['user'],
                    'filepath'            => $properties->filepath,
                    'encrypted_checksums' => $properties->encrypted_checksums,
                ]);

                logDebug('inbox/upload: publishing ingest request', ['routing_key' => 'ingest', 'correlation_id' => $correlationId, 'payload' => $ingestPayload]);

                $channel->basic_publish(
                    new AMQPMessage($ingestPayload, [
                        'correlation_id' => $correlationId,
                        'delivery_mode'  => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                    ]),
                    $mq_exchange,
                    'ingest'
                );
            } else {
                logDebug('inbox/upload: no correlation_id, not forwarding to ingest pipeline');
            }

            break;

        // -------------------------------------------------------------------
        case 'rename':
            if (empty($data['oldpath'])) {
                logError('Cannot rename file with no name (oldpath)');
                break;
            }
            if (empty($data['filepath'])) {
                logError('Cannot rename file: no new filepath provided');
                break;
            }
            logInfo("Rename ".$data['oldpath']." to ".$data['filepath']);
            logDebug('inbox/rename: looking up resource by oldpath', ['oldpath' => $data['oldpath'], 'user_id' => $userId]);
            $resource = DB::queryFirstRow(
                "SELECT resource.id, resource.properties, resource.properties ->> 'public_id' AS public_id
                FROM resource
                INNER JOIN resource_type
                    ON resource.resource_type_id = resource_type.id
                    AND resource_type.\"name\" = 'SdaFile'
                INNER JOIN resource_acl
                    ON resource.id = resource_acl.resource_id
                    AND resource_acl.user_id = %i
                WHERE COALESCE(resource.properties->>'filepath'::text, '') = %s",
                $userId,
                $data['oldpath']
            );

            if (!$resource) {
                logError('Resource is unknown for rename: ' . $data['oldpath']);
                logDebug('inbox/rename: no resource matched oldpath, aborting');
                break;
            }

            logDebug('inbox/rename: resource matched', ['resource_id' => $resource['id']]);

            // Patch only the filepath field in the JSONB column
            DB::query(
                "UPDATE resource
                SET properties = properties::jsonb || '{\"filepath\":\"" . $data['filepath'] . "\"}'
                WHERE id = %s",
                $resource['id']
            );
            logDebug('inbox/rename: filepath patched in properties', ['resource_id' => $resource['id'], 'new_filepath' => $data['filepath']]);

            $props             = json_decode($resource['properties'], true);
            $props['filepath'] = $data['filepath'];

            DB::insert('resource_log', [
                'id'             => newUuid(),
                'resource_id'    => $resource['id'],
                'user_id'        => $userId,
                'action_type_id' => 'MOD',
                'properties'     => json_encode($props),
            ]);
            logDebug('inbox/rename: log entry written', ['resource_id' => $resource['id']]);
            break;

        // -------------------------------------------------------------------
        case 'remove':
            if (empty($data['filepath'])) {
                logError('Cannot remove file: no filepath provided');
                break;
            }
            logInfo($data['filepath']." => remove");
            logDebug('inbox/remove: marking resource deleted', ['filepath' => $data['filepath'], 'user_id' => $userId]);
            updateResourceStatus($userId, $data['filepath'], 'DEL', [], 'deleted by user');
            break;

        default:
            logError('Unknown operation: ' . ($data['operation'] ?? '(none)'));
    }

    $msg->ack();
    logDebug('inbox: message acked');
};

/**
 * Queue: files.error
 *
 * Handles errors reported by the SDA pipeline. Two cases:
 *  1. The correlation ID is known: the resource is found directly and rejected.
 *  2. No valid correlation ID: fall back to filepath-based lookup.
 */
$callbacks['error'] = function (AMQPMessage $msg): void {
    logIncomingMessage('error', $msg);

    $data          = json_decode($msg->body, true);
    $correlationId = $msg->has('correlation_id') ? $msg->get('correlation_id') : null;

    if ($correlationId && checkUuid($correlationId)) {
        logDebug('error: correlation_id looks like a valid UUID, resolving via rmq_correlation', ['correlation_id' => $correlationId]);
        // Try to resolve the resource via the correlation table
        $resource = DB::queryFirstRow(
            'SELECT resource.id, resource.properties
            FROM rmq_correlation
            INNER JOIN resource ON rmq_correlation.resource_id = resource.id
            WHERE correlation_id = %s',
            $correlationId
        );
        $errors = [];
        foreach($data as $k => $v){
            if (strpos($k,'error') !== FALSE || strpos($k,'reason') !== FALSE){
                $errors[] = $k.": ".$v;
            }
        }
        $error = implode(". ",$errors);
        logDebug('error: extracted error/reason fields', ['errors' => $errors]);
        if ($resource) {
            logDebug('error: resource resolved via correlation, marking DEL', ['resource_id' => $resource['id']]);
            // Resource found by correlation: reject it directly
            DB::update('resource', ['status_type_id' => 'DEL'], 'id = %s', $resource['id']);
            DB::insert('resource_log', [
                'id'             => newUuid(),
                'resource_id'    => $resource['id'],
                'user_id'        => null,
                'action_type_id' => 'DEL',
                'properties'     => $resource['properties'],
                'comment'        => $error
            ]);
            logDebug('error: log entry written', ['resource_id' => $resource['id']]);
        } elseif (count($errors)) {
            logDebug('error: no resource for correlation_id, falling back to user + filepath lookup');
            // Unknown correlation: fall back to user + filepath
            $userId = resolveUserIdSafe($data);
            if ($userId) {
                registerErrorResource($userId, $data);
            } else {
                logDebug('error: could not resolve user, dropping');
            }
        }
    } elseif (!empty($data['filepath'])) {
        logDebug('error: no valid correlation_id, handling by filepath', ['filepath' => $data['filepath']]);
        // No valid correlation ID: handle by filepath
        $userId = resolveUserIdSafe($data);
        logInfo('ERROR: ' . $data['filepath']);

        if (!empty($data['reason'])) {
            logDebug('error: payload has a reason, marking DEL', ['reason' => $data['reason']]);
            updateResourceStatus($userId, $data['filepath'], 'DEL', [], $data['reason']);
        } elseif (!empty($data['error_message'])) {
            logDebug('error: payload has error_message, registering error resource', ['error_message' => $data['error_message']]);
            registerErrorResource($userId, $data);
        }
    } else {
        logDebug('error: message has neither a valid correlation_id nor a filepath, ignoring');
    }

    $msg->ack();
    logDebug('error: message acked');
};

/**
 * Queue: files.verified
 *
 * The SDA pipeline successfully verified and decrypted the file.
 * Updates the status to VER and stores the decrypted checksums.
 *
 * Note: sending the accession message is disabled here; it is handled
 * by the dataset submission flow instead.
 */
$callbacks['verified'] = function (AMQPMessage $msg) use ($channel, $mq_exchange): void {
    logIncomingMessage('verified', $msg);

    $data          = json_decode($msg->body, true);
    $correlationId = $msg->has('correlation_id') ? $msg->get('correlation_id') : null;

    $userId = resolveUserId($data['user']);
    if (!$userId) {
        throw new \Exception('Error: unknown user: ' . $data['user']);
    }
    logDebug('verified: resolved user', ['email' => $data['user'], 'user_id' => $userId]);

    if (!$correlationId){
        logInfo('Error: No Correlation_id for filepath ' . $data['filepath']);
        $msg->ack();
        return;
    }
    // Match on both filepath AND correlation_id to avoid ambiguity
    // when the same file is uploaded more than once
    logDebug('verified: matching resource by filepath + correlation_id', ['filepath' => $data['filepath'], 'correlation_id' => $correlationId]);
    $resourceId = DB::queryFirstField(
        "SELECT resource.id
        FROM resource
        INNER JOIN rmq_correlation ON resource.id = rmq_correlation.resource_id
        WHERE resource.properties->>'filepath'::text = %s
          AND rmq_correlation.correlation_id::text = %s",
        $data['filepath'],
        $correlationId
    );

    if (!$resourceId) {
        logInfo('Error: correlation_id ' . $correlationId . ' and filepath ' . $data['filepath'] . ' do not match');
        $msg->ack();
        return;
    }

    logDebug('verified: resource matched, updating to VER', ['resource_id' => $resourceId]);

    updateResourceStatus($userId, $data['filepath'], 'VER', [
        'decrypted_checksums' => $data['decrypted_checksums'],
    ]);
    $msg->ack();
    logDebug('verified: message acked');
};

/**
 * Queue: files.completed
 *
 * The file has been published with an accession ID (e.g. EGAF...).
 * Updates the status to REG after verifying that filepath and public_id match.
 */
$callbacks['completed'] = function (AMQPMessage $msg) use ($channel, $mq_exchange): void {
    logIncomingMessage('completed', $msg);

    $data = json_decode($msg->body, true);

    $userId = resolveUserId($data['user']);
    if (!$userId) {
        throw new \Exception('Error: unknown user: ' . $data['user']);
    }
    logDebug('completed: resolved user', ['email' => $data['user'], 'user_id' => $userId]);

    // Ensure filepath and accession ID both point to the same resource
    logDebug('completed: matching resource by filepath + accession_id', [
        'filepath'     => $data['filepath'],
        'accession_id' => $data['accession_id'],
    ]);
    $resource = DB::queryFirstRow(
        "SELECT resource.id
        FROM resource
        INNER JOIN resource_type
            ON resource.resource_type_id = resource_type.id
            AND resource_type.\"name\" = 'SdaFile'
        INNER JOIN resource_acl
            ON resource.id = resource_acl.resource_id
            AND resource_acl.user_id = %i
        WHERE COALESCE(resource.properties->>'filepath'::text, '') = %s
          AND resource.properties ->> 'public_id' LIKE %ss",
        $userId,
        $data['filepath'],
        $data['accession_id']
    );

    if ($resource) {
        logDebug('completed: resource matched, updating to REG (registered)', ['resource_id' => $resource['id']]);
        updateResourceStatus($userId, $data['filepath'], 'REG');

        // check if all files are published => map dataset
        logDebug('completed: checking whether the parent dataset is fully published', ['accession_id' => $data['accession_id']]);

        $datasetId = DB::queryFirstField("SELECT sdafile_study_dataset_view.dataset_public_id from sdafile_study_dataset_view where sdafile_study_dataset_view.sdafile_public_id = %s and sdafile_study_dataset_view.dataset_id is not null;",$data['accession_id']);
        if ($datasetId){
            logDebug('completed: file belongs to dataset', ['dataset_id' => $datasetId]);
            $nbFilesPending = DB::queryFirstField("SELECT
            	count(sdafile_study_dataset_view.sdafile_id) AS nb_pending
            FROM
            	sdafile_study_dataset_view
            WHERE
            	dataset_public_id = %s
            	AND sdafile_study_dataset_view.status_type_id <> 'REG'
            	AND sdafile_study_dataset_view.status_type_id <> 'PUB'
            	AND sdafile_study_dataset_view.status_type_id <> 'DEL'
            	AND sdafile_study_dataset_view.status_type_id <> 'RES';",
                $datasetId
            );
            logDebug('completed: pending files remaining in dataset', ['dataset_id' => $datasetId, 'nb_pending' => $nbFilesPending]);
            if (intval($nbFilesPending) == 0){
                $accessionIds = DB::queryFirstColumn("SELECT sdafile_study_dataset_view.sdafile_public_id from sdafile_study_dataset_view where sdafile_study_dataset_view.dataset_public_id = %s and sdafile_study_dataset_view.status_type_id in ('PUB','REG');",$datasetId);
                $mappingPayload = json_encode(array(
                    "type" => "mapping",
                    "dataset_id" => $datasetId,
                    "accession_ids" => $accessionIds
                ));
                // $this->sendMessage($msg, 'dataset.mapping');

                logDebug('completed: dataset fully registered, publishing mapping message', [
                    'routing_key' => 'dataset.mapping',
                    'dataset_id'  => $datasetId,
                    'file_count'  => count($accessionIds),
                ]);

                $channel->basic_publish(
                    new AMQPMessage($mappingPayload, [
                        'correlation_id' => uniqid(),
                        'delivery_mode'  => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                    ]),
                    $mq_exchange,
                    'dataset.mapping'
                );
                logInfo("Mapping ".(count($accessionIds))." files to Dataset ".$datasetId);
            } else {
                logDebug('completed: dataset still has pending files, not mapping yet');
            }
        }
        else{
            logError("ERROR: no dataset for file: ".$data['accession_id']);
        }

    } else {
        logInfo('ERROR: ' . $data['filepath'] . " doesn't match with public_id " . $data['accession_id']);
    }
    $msg->ack();
    logDebug('completed: message acked');
};

/**
 * Queue: files.completed
 *
 * The file has been published with an accession ID (e.g. EGAF...).
 * Updates the status to REG after verifying that filepath and public_id match.
 */
$callbacks['dataset'] = function (AMQPMessage $msg) use ($channel, $mq_exchange): void {
    logIncomingMessage('dataset', $msg);

    $data = json_decode($msg->body, true);
    
    $type = $data['type'];
    if ($type === 'registered'){
        foreach($data['accession_ids'] as $accession_id){
            // Ensure filepath and accession ID both point to the same resource
            logDebug('dataset.event: matching resource by filepath + accession_id', [
                'dataset_id'     => $data['dataset_id'],
                'accession_id' => $accession_id
            ]); 
            $sdaFile = DB::queryFirstRow("SELECT properties->>'filepath' as filepath, sdafile_view.creator_id as user_id from sdafile_view where sdafile_view.public_id = %s and sdafile_view.study_id is not null;",$accession_id);           
            if ($sdaFile) {
                logDebug('completed: resource matched, updating to PUB', ['public_id' => $accession_id]);                                
                updateResourceStatus($sdaFile['user_id'], $sdaFile['filepath'], 'PUB');                    
            }            
        }
    }
    else if ($type === 'released'){
        // Ensure filepath and accession ID both point to the same resource
        logDebug('dataset.event: dataset released', [
            'dataset_id'     => $data['dataset_id']
        ]); 
        $dataset = DB::queryFirstRow("SELECT id,properties from dataset_view where public_id = %s",$data['dataset_id']);
        if (!$dataset){
            logError("No id for dataset ".$data['dataset_id']);
        }
        else{
            DB::update('resource', ['status_type_id' => 'PUB'], 'id = %s', $dataset['id']);
            // Write the action log entry
            $log = [
                'id'             => newUuid(),
                'resource_id'    => $dataset['id'],
                'action_type_id' => 'REL',
                'properties'     => $dataset['properties'],
            ];
            DB::insert('resource_log', $log);                
        }
    }
    else if ($type === 'deprecated'){
        // Ensure filepath and accession ID both point to the same resource
        logDebug('dataset.event: dataset deprecated', [
            'dataset_id'     => $data['dataset_id']
        ]); 
        $dataset = DB::queryFirstRow("SELECT id,properties from dataset_view where public_id = %s",$data['dataset_id']);
        if (!$dataset){
            logError("No id for dataset ".$data['dataset_id']);
        }
        else{
            DB::update('resource', ['status_type_id' => 'DEL'], 'id = %s', $dataset['id']);
            // Write the action log entry
            $log = [
                'id'             => newUuid(),
                'resource_id'    => $dataset['id'],
                'action_type_id' => 'DEL',
                'properties'     => $dataset['properties'],
            ];
            DB::insert('resource_log', $log);                
        }
    }
    $msg->ack();
    logDebug('dataset.event: finished');
};




/**
 * Queue: swiss_download_response
 *
 * A `swiss_download_request` has been issued with a correlation_id = dataset_request_id
 * Updates the properties of the dataset_request
 */
$callbacks['swiss_download_response'] = function (AMQPMessage $msg): void {
    logIncomingMessage('swiss_download_response', $msg);

    $data = json_decode($msg->body, true);
    $datasetRequestId = $msg->has('correlation_id') ? $msg->get('correlation_id') : null;
    // Ensure filepath and accession ID both point to the same resource
    logDebug('swiss_download_response: looking up approved dataset request', ['dataset_request_id' => $datasetRequestId]);
    $datasetRequest = DB::queryFirstField(
        "SELECT dataset_requests.properties
        FROM dataset_requests
        WHERE dataset_requests.id = %s and status_type = 'APR'",
        $datasetRequestId
    );
    $errorProperties = array(
          "errorType",
          "errorMessage",
          "timestamp",
          "user",
          "datasetName"
    );

    if ($datasetRequest) {
        $properties = json_decode($datasetRequest,true);
        foreach($errorProperties as $p){
            if (isset($properties[$p])){
                unset($properties[$p]);
            }
        }
        foreach($data as $k => $v){
            $properties[$k] = $v;
        }
        $json = json_encode($properties);
        DB::update("dataset_requests",array("properties" => $json),"id = %s",$datasetRequestId);
        logInfo("Request: ".$datasetRequestId." properties updated");
        logDebug('swiss_download_response: properties merged', ['dataset_request_id' => $datasetRequestId, 'merged_keys' => array_keys($data)]);
    } else {
        logInfo('ERROR: ' . $datasetRequestId . " is not a valid and approved download request");
    }

    $msg->ack();
    logDebug('swiss_download_response: message acked');
};

/**
 * Queue: swiss_download_error
 *
 * A `swiss_download_request` has been issued with a correlation_id = dataset_request_id
 * Updates the properties of the dataset_request
 */
$callbacks['swiss_download_error'] = function (AMQPMessage $msg): void {
    logIncomingMessage('swiss_download_error', $msg);

    $data = json_decode($msg->body, true);
    $datasetRequestId = $msg->has('correlation_id') ? $msg->get('correlation_id') : null;
    // Ensure filepath and accession ID both point to the same resource
    logDebug('swiss_download_error: looking up dataset request', ['dataset_request_id' => $datasetRequestId]);
    $datasetRequest = DB::queryFirstField(
        "SELECT dataset_requests.properties
        FROM dataset_requests
        WHERE dataset_requests.id = %s",
        $datasetRequestId
    );
    $doaProperties = array(
        "type",
        "bucketName",
        "endpoint",
        "accessKey",
        "secretKey",
        "sessionToken",
        "stsTokenExpiration",
    );
    if ($datasetRequest) {
        $properties = json_decode($datasetRequest,true);
        foreach($doaProperties as $p){
            if (isset($properties[$p])){
                unset($properties[$p]);
            }
        }
        foreach($data as $k => $v){
            if (isset($properties[$k]) && strpos($properties[$k],$v) != false && $properties[$k]){
                $v = $properties[$k]." | ".$v;
            }
            $properties[$k] = $v;
        }
        $json = json_encode($properties);
        DB::update("dataset_requests",array("properties" => $json),"id = %s",$datasetRequestId);
        logInfo("ERROR: ".$datasetRequestId.". ".$data['errorType']??"".": ".$data['errorMessage']??"");
        logDebug('swiss_download_error: properties merged (secrets stripped)', ['dataset_request_id' => $datasetRequestId]);
    } else {
        logInfo('ERROR: ' . $datasetRequestId . " is not a valid and approved download request");
    }

    $msg->ack();
    logDebug('swiss_download_error: message acked');
};



// ---------------------------------------------------------------------------
// Internal helper: safe user resolution (used in the error handler)
// ---------------------------------------------------------------------------

/**
 * Resolves a user ID from $data['user'] without throwing an exception.
 * Used in the error handler where the user field may be absent.
 *
 * @param array $data  Message payload (should contain 'user' if available)
 * @return int|null
 */
function resolveUserIdSafe(array $data): ?int
{
    if (empty($data['user'])) {
        logDebug('resolveUserIdSafe: no user field in payload');
        return null;
    }

    $users = getKeyCloakUsers('', 'email=' . $data['user']);
    if (!$users || !count($users)) {
        throw new \Exception('Error: unknown user: ' . $data['user']);
    }

    $user = array_shift($users);
    if (empty($user['username'])) {
        logDebug('resolveUserIdSafe: Keycloak user has no username', ['email' => $data['user']]);
        return null;
    }

    $userId = DB::queryFirstField(
        'SELECT id FROM "user" WHERE external_id = %s',
        $user['username']
    ) ?: null;
    logDebug('resolveUserIdSafe: resolved', ['email' => $data['user'], 'username' => $user['username'], 'user_id' => $userId]);

    return $userId;
}

// ---------------------------------------------------------------------------
// Exchange and queue declaration
// ---------------------------------------------------------------------------

/*
 * TOPIC exchange with a dead-letter exchange as fallback.
 * Unrouted or expired messages are forwarded to "localega.dead".
 */
logDebug('Declaring exchange', ['exchange' => $mq_exchange, 'type' => AMQPExchangeType::TOPIC]);
$channel->exchange_declare(
    $mq_exchange,
    AMQPExchangeType::TOPIC,
    false, // passive
    true,  // durable
    false, // auto-delete
    false, // internal
    false, // no-wait
    new AMQPTable(['alternate-exchange' => 'localega.dead'])
);

/*
 * Routing key to queue name mapping.
 * Each queue is durable and has its own dead-letter exchange.
 */
$routingKeys = [
    'files.completed'              => 'completed',
    'files.error'                  => 'error',
    'files.inbox'                  => 'inbox',
    'files.verified'               => 'verified',
    'dataset.event'                => 'dataset',
    'swiss.doa.operation.response' => 'swiss_download_response',
    'swiss.doa.error'              => 'swiss_download_error'
];


foreach ($routingKeys as $routingKey => $queue) {
    logDebug('Declaring queue and binding routing key', ['queue' => $queue, 'routing_key' => $routingKey]);
    $deadLetterArgs = new AMQPTable(['x-dead-letter-exchange' => 'localega.dead']);
    $channel->queue_declare($queue, false, true, false, false, false, $deadLetterArgs);
    $channel->queue_bind($queue, $mq_exchange, $routingKey, false, $deadLetterArgs);
}

// Register consumers (multiple routing keys may share the same callback)
foreach (array_unique(array_values($routingKeys)) as $queue) {
    logDebug('Registering consumer', ['queue' => $queue]);
    $channel->basic_consume($queue, '', false, false, false, false, $callbacks[$queue]);
}

logInfo('All queues declared and consumers registered, entering main loop');

// ---------------------------------------------------------------------------
// Main loop
// ---------------------------------------------------------------------------

try {
    $channel->consume();
} catch (\Throwable $exception) {
    logInfo($exception->getMessage());
    logDebug('Main loop terminated by exception', ['exception_class' => get_class($exception), 'message' => $exception->getMessage()]);
}

$channel->close();
$connection->close();
logInfo('Channel and connection closed, exiting');
