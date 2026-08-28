<?php

namespace App\EventListener;

use App\Service\Auth\Keycloak;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * No rate limiting existed anywhere in the application (security audit M-2):
 * neither symfony/rate-limiter nor any Apache-level limit (mod_ratelimit/mod_qos)
 * was configured, and several endpoints are individually expensive enough that
 * this matters (validate/bundle forks a 512MB PHP process for up to 300s; pubmeds
 * makes an outbound HTTP call with no rate limiting of its own).
 *
 * Routes are matched by name against the same #[Route(name: ...)] identifiers
 * used throughout the controllers, so this stays correct as paths change.
 */
class RateLimitListener
{
    private const EXPENSIVE_ROUTES = [
        'validate_bundle',
        'validate_data',
        'get_pubmeds',
        'get_all_users',
        'download_submissions',
        'get_dacs',
    ];

    public function __construct(
        private RateLimiterFactory $anonymousLimiter,
        private RateLimiterFactory $authenticatedLimiter,
        private RateLimiterFactory $expensiveLimiter,
        private Keycloak $auth,
    ) {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $routeName = $request->attributes->get('_route');
        if ($routeName === null) {
            return;
        }

        $isAuthenticated = !$this->auth->isGuest();
        $key = $isAuthenticated ? ('user_' . $this->auth->getDetails()['id']) : ('ip_' . $request->getClientIp());

        $limiters = [$isAuthenticated ? $this->authenticatedLimiter : $this->anonymousLimiter];
        if (in_array($routeName, self::EXPENSIVE_ROUTES, true)) {
            $limiters[] = $this->expensiveLimiter;
        }

        foreach ($limiters as $factory) {
            $limit = $factory->create($key)->consume(1);
            if (!$limit->isAccepted()) {
                $response = new JsonResponse(
                    ['message' => 'Too many requests'],
                    429
                );
                // Retry-After is delta-seconds, not a raw timestamp (RFC 9110 §10.2.3) -
                // sending getTimestamp() directly told clients to wait until the year 2026+X.
                $retryAfterSeconds = max(1, $limit->getRetryAfter()->getTimestamp() - time());
                $response->headers->set('Retry-After', (string) $retryAfterSeconds);
                $event->setResponse($response);
                return;
            }
        }
    }
}
