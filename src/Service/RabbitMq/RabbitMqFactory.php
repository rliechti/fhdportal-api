<?php

namespace App\Service\RabbitMq;

use MeekroDB;

/**
 * Factory that selects the appropriate RabbitMQ implementation based on the
 * RABBITMQ_ENABLED environment variable.
 *
 * Set RABBITMQ_ENABLED=false in your environment to disable RabbitMQ.
 * All calls will then be silently swallowed by NullRabbitMq.
 *
 * When RABBITMQ_ENABLED is not set or is set to anything other than 'false', the
 * real RabbitMq implementation is used (default behaviour).
 */
class RabbitMqFactory
{
    public static function create(string $enabled, MeekroDB $db): RabbitMqInterface
    {
        if (strtolower(trim($enabled)) === 'false') {
            return new NullRabbitMq();
        }

        return new RabbitMq($db);
    }
}
