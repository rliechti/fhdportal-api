<?php

namespace App\Service\RabbitMq;

interface RabbitMqInterface
{
    public function sendMessage(array $msg, string $routing_key, ?string $correlation_id = null): string;

    public function mapSDAfiles(array $sdafiles): void;

    public function releaseDataset(string $dataset_id, string $email, string $timestamp): void;

    public function permissionDataset(array $params): array;
}
