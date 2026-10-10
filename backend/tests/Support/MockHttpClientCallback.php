<?php

namespace App\Tests\Support;

use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Test env only (config/packages/framework.yaml when@test) — every outgoing HTTP request made
 * through `http_client` is answered from a static FIFO queue instead of the network, and
 * recorded. An unexpected request (empty queue) fails loudly instead of reaching a real server.
 */
final class MockHttpClientCallback
{
    /** @var list<MockResponse|\Throwable> */
    private static array $queue = [];

    /** @var list<array{method: string, url: string, options: array}> */
    public static array $requests = [];

    public static function reset(): void
    {
        self::$queue = [];
        self::$requests = [];
    }

    public static function push(MockResponse|\Throwable $response): void
    {
        self::$queue[] = $response;
    }

    public function __invoke(string $method, string $url, array $options = []): ResponseInterface
    {
        self::$requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

        $next = array_shift(self::$queue);
        if ($next === null) {
            throw new \LogicException(sprintf('Unexpected outgoing HTTP request in test: %s %s', $method, $url));
        }
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }
}
