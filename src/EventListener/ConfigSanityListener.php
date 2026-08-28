<?php

namespace App\EventListener;

use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * Fails fast at boot (on the first request) rather than silently allowing a
 * misconfigured CORS_ALLOW_ORIGIN to reach production. nelmio_cors.yaml treats
 * it as a regex, so an unanchored or wildcard value (e.g. '.*', 'fega\.swiss'
 * without ^$) would match far more origins than intended - nothing previously
 * validated it (security audit M-14).
 */
class ConfigSanityListener
{
    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || ($_ENV['APP_ENV'] ?? 'prod') !== 'prod') {
            return;
        }

        $origin = $_ENV['CORS_ALLOW_ORIGIN'] ?? '';
        if ($origin === '' || $origin === '.*' || $origin === '*') {
            throw new \RuntimeException('CORS_ALLOW_ORIGIN must be an explicit, anchored regex in production - refusing to serve requests');
        }
        if (!str_starts_with($origin, '^') || !str_ends_with($origin, '$')) {
            throw new \RuntimeException('CORS_ALLOW_ORIGIN must be anchored with ^ and $: ' . $origin);
        }
    }
}
