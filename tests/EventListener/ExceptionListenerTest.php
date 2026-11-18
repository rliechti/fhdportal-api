<?php

namespace App\Tests\EventListener;

use App\EventListener\ExceptionListener;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;

class ExceptionListenerTest extends TestCase
{
    private ?string $appEnv;

    protected function setUp(): void
    {
        $this->appEnv = $_ENV['APP_ENV'] ?? null;
        $_ENV['APP_ENV'] = 'prod';
    }

    protected function tearDown(): void
    {
        if ($this->appEnv === null) {
            unset($_ENV['APP_ENV']);
        } else {
            $_ENV['APP_ENV'] = $this->appEnv;
        }
    }

    private function answer(\Throwable $exception): array
    {
        $kernel = $this->createStub(HttpKernelInterface::class);
        $event = new ExceptionEvent($kernel, new Request(), HttpKernelInterface::MAIN_REQUEST, $exception);
        (new ExceptionListener(new NullLogger()))->onKernelException($event);
        $response = $event->getResponse();

        return ['status' => $response->getStatusCode(), 'body' => json_decode($response->getContent(), true)];
    }

    public function testAnApplicationExceptionWithAClientErrorCodeGivesThatStatus(): void
    {
        // new Exception("Unauthorized", 401) is how the services refuse: it used to be answered as a 500
        $answer = $this->answer(new \Exception('Unauthorized', 401));
        $this->assertSame(401, $answer['status']);
        $this->assertSame('Unauthorized', $answer['body']['error']);

        $this->assertSame(404, $this->answer(new \Exception('Unknown resource', 404))['status']);
        $this->assertSame(403, $this->answer(new \Exception('Forbidden', 403))['status']);
    }

    public function testAnHttpExceptionKeepsItsStatus(): void
    {
        $this->assertSame(403, $this->answer(new AccessDeniedHttpException('no'))['status']);
    }

    public function testAServerErrorNeverShowsItsMessage(): void
    {
        $answer = $this->answer(new \Exception('SQLSTATE[...] secret detail', 500));
        $this->assertSame(500, $answer['status']);
        $this->assertSame('An internal server error occurred', $answer['body']['error']);
        $this->assertArrayHasKey('correlation_id', $answer['body']);
        $this->assertStringNotContainsString('secret', json_encode($answer['body']));
    }

    public function testAnExceptionWithoutAClientErrorCodeIsAServerError(): void
    {
        $this->assertSame(500, $this->answer(new \Exception('no code'))['status']);
        $this->assertSame(500, $this->answer(new \Exception('too small', 399))['status']);
        $this->assertSame(500, $this->answer(new \Exception('a database code', 1062))['status']);
    }

    public function testOnlyAPlainExceptionGivesItsCodeAsStatus(): void
    {
        // a library or a subclass may use the code for something else (a SQLSTATE, the status of another server)
        $this->assertSame(500, $this->answer(new \RuntimeException('upstream said 404', 404))['status']);
        $this->assertSame(500, $this->answer(new \InvalidArgumentException('bad', 400))['status']);
        $this->assertSame(500, $this->answer(new \PDOException('duplicate'))['status']);
    }
}
