<?php

namespace App\Service\RabbitMq;

/**
 * No-op RabbitMQ implementation used when RabbitMQ is disabled.
 */
class NullRabbitMq implements RabbitMqInterface
{
    public function sendMessage(array $msg, string $routing_key, ?string $correlation_id = null): string
    {
        return '';
    }

    public function mapSDAfiles(array $sdafiles): void
    {
        // No-op: RabbitMQ is disabled
    }

    public function releaseDataset(string $dataset_id, string $email, string $timestamp): void
    {
        // No-op: RabbitMQ is disabled
    }

    public function requestDownload(array $params): array
    {
        return [
            'status'    => 'success',
            'message'   => 'RabbitMQ is disabled',
            'exit_code' => 200,
        ];
    }

    public function revokeDownload(array $params): array
    {
        return [
            'status'    => 'success',
            'message'   => 'RabbitMQ is disabled',
            'exit_code' => 200,
        ];
    }

    public function refreshRequestTokens(string $correlation_id, string $email): void
    {
        // No-op: RabbitMQ is disabled
    }

}
