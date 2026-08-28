<?php
namespace App\EventListener;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Psr\Log\LoggerInterface;

class ExceptionListener
{
    private $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function onKernelException(ExceptionEvent $event)
    {
        $exception = $event->getThrowable();

        // Return sanitized error to user
        $statusCode = $exception instanceof HttpExceptionInterface
            ? $exception->getStatusCode()
            : 500;

        $limitCode = ($_ENV['APP_ENV'] !== 'dev') ? 500 : 5000;
        $payload = ['code' => $statusCode];

        if ($statusCode < $limitCode) {
            // Client errors: the message was authored to be shown.
            $payload['error'] = $exception->getMessage();
            $this->logger->error('Exception occurred', [
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString()
            ]);
        } else {
            // Server errors: never echo internal detail (DB errors, file paths, ...) to
            // the client. Log it in full under a correlation id the client can quote to
            // support instead (security audit H-8).
            $correlationId = bin2hex(random_bytes(8));
            $payload['error'] = 'An internal server error occurred';
            $payload['correlation_id'] = $correlationId;
            $this->logger->error('Exception occurred', [
                'correlation_id' => $correlationId,
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString()
            ]);
        }

        $response = new JsonResponse($payload, $statusCode);

        $event->setResponse($response);
    }
}
