<?php

/**
 * RabbitMQ Consumer — SDA File Manager
 *
 * This script listens to several RabbitMQ queues and keeps the SDA
 * (Sensitive Data Archive) file states in sync with the database.
 *
 * Queues consumed:
 *  - files.inbox     : file uploaded / renamed / removed by the user
 *  - files.verified  : file verified by the SDA pipeline
 *  - files.completed : file published with an accession ID
 *  - files.error     : error reported by the SDA pipeline
 *
 * Action codes stored in the database:
 *  - CRE : resource created
 *  - MOD : resource modified / renamed
 *  - VER : verification successful
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
 * Prints a timestamped error message to stderr.
 */
function logError(string $message): void
{
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] ERROR: ' . $message . PHP_EOL);
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
    $users = getKeyCloakUsers('', 'email=' . $email);
    $user  = array_shift($users);

    $userId = null;
    if (!empty($user['username'])) {
        $userId = DB::queryFirstField(
            'SELECT id FROM "user" WHERE external_id = %s',
            $user['username']
        );
    }

    if (!$userId) {
        DB::insert('user', ['external_id' => $email]);
        $userId = DB::insertId();
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
 * @param string $status         New status / action code (e.g. VER, DEL, PUB)
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
        return;
    }

    foreach ($resources as $resource) {
        $jsonProperties = $resource['properties'];

        // Merge new properties into the existing JSON blob if provided
        if ($newProperties) {
            $props = json_decode($jsonProperties, true);
            foreach ($newProperties as $key => $value) {
                $props[$key] = $value;
            }
            $jsonProperties = json_encode($props);
        }

        DB::update('resource', ['status_type_id' => $status], 'id = %s', $resource['id']);

        if ($newProperties) {
            DB::update('resource', ['properties' => $jsonProperties], 'id = %s', $resource['id']);
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
    if (empty($data['filepath'])) {
        logInfo('Unknown error => ' . ($data['error_message'] ?? '(no message)'));
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

    if ($existingResources) {
        // Resource already exists: mark it as deleted / rejected
        updateResourceStatus($userId, $data['filepath'], 'DEL', [], $data['error_message']);
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
        return;
    }

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

    DB::insert('resource_acl', [
        'resource_id' => $resourceId,
        'user_id'     => $userId,
        'role_id'     => 'OWN',
    ]);

    DB::insert('resource_log', [
        'id'             => newUuid(),
        'resource_id'    => $resourceId,
        'user_id'        => $userId,
        'action_type_id' => 'DEL',
        'properties'     => $jsonProperties,
        'comment'        => $data['error_message'],
    ]);

    logInfo('ERROR: ' . basename($data['filepath']) . ' => ' . $data['error_message']);
}

// ---------------------------------------------------------------------------
// RabbitMQ connection
// ---------------------------------------------------------------------------

$config = new AMQPConnectionConfig();
$config->setHost($_ENV['MQ_HOST']);
$config->setPort($_ENV['MQ_PORT']);
$config->setUser($_ENV['MQ_USER']);
$config->setPassword($_ENV['MQ_PWD']);
$config->setVhost($_ENV['MQ_VHOST']);

// Enable TLS for any host other than localhost
$isSecure = ($_ENV['MQ_HOST'] !== 'localhost');
$config->setIsSecure($isSecure);
$config->setSslVerify(false);

$connection = (new AMQPConnectionFactory())->create($config);
$channel    = $connection->channel();

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
    $data          = json_decode($msg->body, true);
    $correlationId = $msg->get('correlation_id');
    $userId        = resolveUserId($data['user']);

    if (!$userId) {
        logError('Cannot resolve user: ' . $data['user']);
        $msg->ack();
        return;
    }

    switch ($data['operation']) {

        // -------------------------------------------------------------------
        case 'upload':
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
                break;
            }

            // Look for a duplicate by checksum (identical re-upload or rename)
            $checksums = array_filter(
                array_column($data['encrypted_checksums'], 'value')
            );

            $existingResource = null;
            if ($checksums) {
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

            if ($existingResource && $existingResource['filepath'] === $data['filepath']) {
                // Same checksum and same path: already exists, nothing to do
                logError('Already exists');
                break;
            }

            $actionTypeId = 'CRE';
            $resourceId   = null;

            if ($existingResource) {
                // Same checksum but different path: treat as a rename
                $resourceId            = $existingResource['id'];
                $properties->public_id = $existingResource['public_id'];
                $actionTypeId          = 'MOD';
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

                $roleId = DB::queryFirstField("SELECT id FROM \"role\" WHERE name = 'owner'");
                if ($roleId) {
                    DB::insert('resource_acl', [
                        'resource_id' => $resourceRow['id'],
                        'user_id'     => $userId,
                        'role_id'     => $roleId,
                    ]);
                }
            } else {
                // Existing resource (rename): update it in place
                DB::update('resource', $resourceRow, 'id = %s', $resourceId);
            }

            DB::insert('resource_log', [
                'id'             => newUuid(),
                'resource_id'    => $resourceRow['id'],
                'user_id'        => $userId,
                'action_type_id' => $actionTypeId,
                'properties'     => $jsonProperties,
            ]);

            // Store the correlation so we can match the pipeline response later
            DB::delete('rmq_correlation', 'correlation_id = %s', $correlationId);
            DB::insert('rmq_correlation', [
                'correlation_id' => $correlationId,
                'resource_id'    => $resourceRow['id'],
            ]);

            // Forward the ingestion request to the SDA pipeline
            $ingestPayload = json_encode([
                'type'                => 'ingest',
                'user'                => $data['user'],
                'filepath'            => $properties->filepath,
                'encrypted_checksums' => $properties->encrypted_checksums,
            ]);

            $channel->basic_publish(
                new AMQPMessage($ingestPayload, [
                    'correlation_id' => $correlationId,
                    'delivery_mode'  => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                ]),
                $mq_exchange,
                'ingest'
            );
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
                break;
            }

            // Patch only the filepath field in the JSONB column
            DB::query(
                "UPDATE resource
                SET properties = properties::jsonb || '{\"filepath\":\"" . $data['filepath'] . "\"}'
                WHERE id = %s",
                $resource['id']
            );

            $props             = json_decode($resource['properties'], true);
            $props['filepath'] = $data['filepath'];

            DB::insert('resource_log', [
                'id'             => newUuid(),
                'resource_id'    => $resource['id'],
                'user_id'        => $userId,
                'action_type_id' => 'MOD',
                'properties'     => json_encode($props),
            ]);
            break;

        // -------------------------------------------------------------------
        case 'remove':
            if (empty($data['filepath'])) {
                logError('Cannot remove file: no filepath provided');
                break;
            }
            logInfo('REMOVE ' . $data['filepath']);
            updateResourceStatus($userId, $data['filepath'], 'DEL', [], 'deleted by user');
            break;

        default:
            logError('Unknown operation: ' . ($data['operation'] ?? '(none)'));
    }

    $msg->ack();
};

/**
 * Queue: files.error
 *
 * Handles errors reported by the SDA pipeline. Two cases:
 *  1. The correlation ID is known: the resource is found directly and rejected.
 *  2. No valid correlation ID: fall back to filepath-based lookup.
 */
$callbacks['error'] = function (AMQPMessage $msg): void {
    $data          = json_decode($msg->body, true);
    $correlationId = $msg->get('correlation_id');

    if ($correlationId && checkUuid($correlationId)) {
        // Try to resolve the resource via the correlation table
        $resource = DB::queryFirstRow(
            'SELECT resource.id, resource.properties
            FROM rmq_correlation
            INNER JOIN resource ON rmq_correlation.resource_id = resource.id
            WHERE correlation_id = %s',
            $correlationId
        );

        if ($resource) {
            // Resource found by correlation: reject it directly
            DB::update('resource', ['status_type_id' => 'DEL'], 'id = %s', $resource['id']);
            DB::insert('resource_log', [
                'id'             => newUuid(),
                'resource_id'    => $resource['id'],
                'user_id'        => null,
                'action_type_id' => 'DEL',
                'properties'     => $resource['properties'],
            ]);
        } elseif (!empty($data['error_message'])) {
            // Unknown correlation: fall back to user + filepath
            $userId = resolveUserIdSafe($data);
            if ($userId) {
                registerErrorResource($userId, $data);
            }
        }
    } elseif (!empty($data['filepath'])) {
        // No valid correlation ID: handle by filepath
        $userId = resolveUserIdSafe($data);
        logInfo('ERROR: ' . $data['filepath']);

        if (!empty($data['reason'])) {
            updateResourceStatus($userId, $data['filepath'], 'DEL', [], $data['reason']);
        } elseif (!empty($data['error_message'])) {
            registerErrorResource($userId, $data);
        }
    }

    $msg->ack();
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
    $data          = json_decode($msg->body, true);
    $correlationId = $msg->get('correlation_id');

    $userId = resolveUserId($data['user']);
    if (!$userId) {
        throw new \Exception('Error: unknown user: ' . $data['user']);
    }

    // Match on both filepath AND correlation_id to avoid ambiguity
    // when the same file is uploaded more than once
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

    updateResourceStatus($userId, $data['filepath'], 'VER', [
        'decrypted_checksums' => $data['decrypted_checksums'],
    ]);

    $msg->ack();
};

/**
 * Queue: files.completed
 *
 * The file has been published with an accession ID (e.g. EGAF...).
 * Updates the status to PUB after verifying that filepath and public_id match.
 */
$callbacks['completed'] = function (AMQPMessage $msg): void {
    $data = json_decode($msg->body, true);

    $userId = resolveUserId($data['user']);
    if (!$userId) {
        throw new \Exception('Error: unknown user: ' . $data['user']);
    }

    // Ensure filepath and accession ID both point to the same resource
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
        updateResourceStatus($userId, $data['filepath'], 'PUB');
    } else {
        logInfo('ERROR: ' . $data['filepath'] . " doesn't match with public_id " . $data['accession_id']);
    }

    $msg->ack();
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
        return null;
    }

    $users = getKeyCloakUsers('', 'email=' . $data['user']);
    if (!$users || !count($users)) {
        throw new \Exception('Error: unknown user: ' . $data['user']);
    }

    $user = array_shift($users);
    if (empty($user['username'])) {
        return null;
    }

    return DB::queryFirstField(
        'SELECT id FROM "user" WHERE external_id = %s',
        $user['username']
    ) ?: null;
}

// ---------------------------------------------------------------------------
// Exchange and queue declaration
// ---------------------------------------------------------------------------

/*
 * TOPIC exchange with a dead-letter exchange as fallback.
 * Unrouted or expired messages are forwarded to "localega.dead".
 */
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
    'files.completed' => 'completed',
    'files.error'     => 'error',
    'files.inbox'     => 'inbox',
    'files.verified'  => 'verified',
];

$deadLetterArgs = new AMQPTable(['x-dead-letter-exchange' => 'localega.dead']);

foreach ($routingKeys as $routingKey => $queue) {
    $channel->queue_declare($queue, false, true, false, false, false, $deadLetterArgs);
    $channel->queue_bind($queue, $mq_exchange, $routingKey, false, $deadLetterArgs);
}

// Register consumers (multiple routing keys may share the same callback)
foreach (array_unique(array_values($routingKeys)) as $queue) {
    $channel->basic_consume($queue, '', false, false, false, false, $callbacks[$queue]);
}

// ---------------------------------------------------------------------------
// Main loop
// ---------------------------------------------------------------------------

try {
    $channel->consume();
} catch (\Throwable $exception) {
    logInfo($exception->getMessage());
}

$channel->close();
$connection->close();
