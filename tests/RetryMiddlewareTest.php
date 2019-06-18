<?php declare(strict_types=1);

namespace MintSoftware\GuzzleRetry;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * Class RetryMiddlewareTest.
 */
class RetryMiddlewareTest extends TestCase
{
    private const HTTP_OK = 200;
    private const HTTP_TOO_MANY_REQUESTS = 429;
    private const HTTP_SERVICE_UNAVAILABLE = 503;
    private const METHOD_GET = 'GET';

    public function testInstantiation(): void
    {
        $handler = new MockHandler();
        $obj = new RetryMiddleware($handler);
        $this->assertInstanceOf(RetryMiddleware::class, $obj);
    }

    /**
     * @dataProvider providerForRetryOccursWhenStatusCodeMatches
     *
     * @param Response $response
     */
    public function testRetry(Response $response): void
    {
        $stack = HandlerStack::create(
            new MockHandler(
                [
                    $response,
                    new Response(200, []),
                ]
            )
        );

        $stack->push(RetryMiddleware::factory());

        $client = new Client(['handler' => $stack]);
        $response = $client->get('/');

        $this->assertEquals(self::HTTP_OK, $response->getStatusCode());
    }

    /**
     * @return array
     */
    public function providerForRetryOccursWhenStatusCodeMatches(): array
    {
        return [
            [new Response(self::HTTP_TOO_MANY_REQUESTS, []), true],
            [new Response(self::HTTP_SERVICE_UNAVAILABLE, []), true],
            [new Response(self::HTTP_OK, [], 'OK'), false],
        ];
    }

    /**
     * @dataProvider retriesFailAfterSpecifiedAttemptsProvider
     *
     * @param array $responses
     */
    public function testRetriesFailAfterSpecifiedAttempts(array $responses): void
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $count = 0;
        $retry = RetryMiddleware::factory(
            [
                RetryMiddleware::OPTIONS_MAX_RETRY_ATTEMPTS => 3,
                RetryMiddleware::OPTIONS_RETRY_ON_TIMEOUT => true,
                RetryMiddleware::OPTIONS_CALLBACK => function (float $delay, array $options) use (&$count) {
                    $count = $options['retry_count'];
                },
            ]
        );
        $stack->push($retry);
        $client = new Client(['handler' => $stack]);

        try {
            $client->request('GET', '/');
        } catch (TransferException $e) {
            $this->assertEquals(3, $count);
        }
    }

    /**
     * @return array
     */
    public function retriesFailAfterSpecifiedAttemptsProvider(): array
    {
        $response = new Response(self::HTTP_TOO_MANY_REQUESTS);
        $connectException = new ConnectException(
            'Connect Timeout',
            new Request(self::METHOD_GET, '/'),
            null,
            ['errno' => 28]
        );

        return [
            [array_fill(0, 4, $response)],
            [array_fill(0, 4, $connectException)],
        ];
    }

    /**
     * @dataProvider retriesPassAfterSpecifiedAttemptsProvider
     *
     * @param array $responses
     * @param int   $retryAssert
     */
    public function testRetriesPassAfterSpecifiedAttempts(array $responses, int $retryAssert): void
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $count = 0;
        $retry = RetryMiddleware::factory(
            [
                RetryMiddleware::OPTIONS_MAX_RETRY_ATTEMPTS => 10,
                RetryMiddleware::OPTIONS_RETRY_ON_TIMEOUT => true,
                RetryMiddleware::OPTIONS_CALLBACK => function (float $delay, array $options) use (&$count) {
                    $count = $options['retry_count'];
                },
            ]
        );
        $stack->push($retry);
        $client = new Client(['handler' => $stack]);

        $response = $client->request(self::METHOD_GET, '/');
        $this->assertEquals($retryAssert, $count);
        $this->assertEquals(self::HTTP_OK, $response->getStatusCode());
    }

    /**
     * @return array
     */
    public function retriesPassAfterSpecifiedAttemptsProvider(): array
    {
        $response = new Response(self::HTTP_TOO_MANY_REQUESTS);
        $connectException = new ConnectException(
            '',
            new Request(self::METHOD_GET, '/'),
            null,
            ['errno' => 28]
        );

        return [
            ['responses' => array_merge(array_fill(0, 2, $response), [new Response(200, [])]), 'retry' => 2],
            ['responses' => array_merge(array_fill(0, 3, $connectException), [new Response(200, [])]), 'retry' => 3],
        ];
    }

    public function testRetryAfterHeader(): void
    {
        $retryAfter = 2;
        $responses = [
            new Response(self::HTTP_TOO_MANY_REQUESTS, ['Retry-After' => $retryAfter]),
            new Response(self::HTTP_OK, []),
        ];

        $stack = HandlerStack::create(new MockHandler($responses));
        $delayed = 0;
        $stack->push(
            RetryMiddleware::factory(
                [
                    RetryMiddleware::OPTIONS_CALLBACK => function (float $delay) use (&$delayed) {
                        $delayed = $delay;
                    },
                ]
            )
        );

        $client = new Client(['handler' => $stack]);
        $client->request('GET', '/');
        $this->assertTrue($delayed > 1 && $delayed < 5);
    }

    /**
     * @dataProvider retryDisabledProvider
     *
     * @param array $responses
     */
    public function testRetryDisabled(array $responses): void
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(
            RetryMiddleware::factory(
                [
                    RetryMiddleware::OPTIONS_RETRY_ENABLED => false,
                ]
            )
        );

        $client = new Client(['handler' => $stack]);

        $this->expectException(GuzzleException::class);
        $client->request(self::METHOD_GET, '/');
    }

    /**
     * @return array
     */
    public function retryDisabledProvider(): array
    {
        return [
            [
                [
                    new Response(self::HTTP_TOO_MANY_REQUESTS, []),
                    new Response(self::HTTP_OK, []),
                ],
            ],
            [
                [
                    new ConnectException(
                        '',
                        new Request(self::METHOD_GET, '/'),
                        null,
                        ['errno' => 28]
                    ),
                    new Response(self::HTTP_OK, []),
                ],
            ],
        ];
    }

    /**
     * Test Retry After Seconds option.
     */
    public function testRetryAfterSeconds(): void
    {
        $responses = [
            new Response(self::HTTP_TOO_MANY_REQUESTS, []),
            new Response(self::HTTP_OK, []),
        ];

        $stack = HandlerStack::create(new MockHandler($responses));
        $delayed = 0;
        $stack->push(
            RetryMiddleware::factory(
                [
                    RetryMiddleware::OPTIONS_RETRY_AFTER_SECONDS => 2,
                    RetryMiddleware::OPTIONS_CALLBACK => function (float $delay) use (&$delayed) {
                        $delayed = $delay;
                    },
                ]
            )
        );

        $client = new Client(['handler' => $stack]);

        $client->request(self::METHOD_GET, '/');
        $this->assertEquals(2, $delayed);
    }

    /**
     * @dataProvider forcedRetryAfterOption
     *
     * @param array $responses
     */
    public function testForcedRetryAfterOption(array $responses): void
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(
            RetryMiddleware::factory(
                [
                    RetryMiddleware::OPTIONS_RETRY_ONLY_IF_RETRY_AFTER_HEADER => true,
                ]
            )
        );

        $client = new Client(['handler' => $stack]);

        $this->expectException(GuzzleException::class);
        $client->request(self::METHOD_GET, '/');
    }

    /**
     * @return array
     */
    public function forcedRetryAfterOption(): array
    {
        return [
            [
                [
                    new Response(self::HTTP_TOO_MANY_REQUESTS, []),
                    new Response(self::HTTP_OK, []),
                ],
            ],
            [
                [
                    new ConnectException(
                        '',
                        new Request(self::METHOD_GET, '/'),
                        null,
                        ['errno' => 28]
                    ),
                    new Response(self::HTTP_OK, []),
                ],
            ],
            [
                [
                    new ConnectException(
                        '',
                        new Request(self::METHOD_GET, '/'),
                        null,
                        ['errno' => 28]
                    ),
                    new Response(self::HTTP_OK, []),
                ],
            ],
            [
                [
                    new BadResponseException(
                        '',
                        new Request(self::METHOD_GET, '/'),
                        new Response(self::HTTP_TOO_MANY_REQUESTS, [])
                    ),
                    new Response(self::HTTP_OK, []),
                ],
            ],
            [
                [
                    new BadResponseException(
                        '',
                        new Request(self::METHOD_GET, '/'),
                        null
                    ),
                    new Response(self::HTTP_OK, []),
                ],
            ],
        ];
    }

    public function testAddRetryHeader(): void
    {
        $responses = [
            new BadResponseException(
                '',
                new Request(self::METHOD_GET, '/'),
                new Response(self::HTTP_TOO_MANY_REQUESTS, [])
            ),
            new Response(self::HTTP_OK, []),
        ];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(
            RetryMiddleware::factory(
                [
                    RetryMiddleware::OPTIONS_RETRY_HEADER => RetryMiddleware::RETRY_HEADER,
                ]
            )
        );

        $client = new Client(['handler' => $stack]);

        $response = $client->request(self::METHOD_GET, '/');
        $this->assertArrayHasKey(RetryMiddleware::RETRY_HEADER, $response->getHeaders());
    }

    public function testShouldNotRetryWhileConnectionErrorNot(): void
    {
        $responses = [
            new ConnectException(
                '',
                new Request(self::METHOD_GET, '/'),
                null,
                ['errno' => 1]
            ),
            new Response(self::HTTP_OK, []),
        ];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(RetryMiddleware::factory());
        $client = new Client(['handler' => $stack]);

        $this->expectException(GuzzleException::class);
        $client->request(self::METHOD_GET, '/');
    }
}
