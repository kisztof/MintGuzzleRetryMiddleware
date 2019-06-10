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
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Class RetryMiddlewareTest.
 */
class RetryMiddlewareTest extends TestCase
{
    public function testInstantiation()
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
    public function testRetry(Response $response)
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

        $this->assertEquals(SymfonyResponse::HTTP_OK, $response->getStatusCode());
    }

    /**
     * @return array
     */
    public function providerForRetryOccursWhenStatusCodeMatches(): array
    {
        return [
            [new Response(SymfonyResponse::HTTP_TOO_MANY_REQUESTS, []), true],
            [new Response(SymfonyResponse::HTTP_SERVICE_UNAVAILABLE, []), true],
            [new Response(SymfonyResponse::HTTP_OK, [], 'OK'), false],
        ];
    }

    /**
     * @dataProvider retriesFailAfterSpecifiedAttemptsProvider
     *
     * @param array $responses
     */
    public function testRetriesFailAfterSpecifiedAttempts(array $responses)
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
        $response = new Response(SymfonyResponse::HTTP_TOO_MANY_REQUESTS);
        $connectException = new ConnectException(
            'Connect Timeout',
            new Request(SymfonyRequest::METHOD_GET, '/'),
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
     */
    public function testRetriesPassAfterSpecifiedAttempts(array $responses, int $retryAssert)
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

        $response = $client->request(SymfonyRequest::METHOD_GET, '/');
        $this->assertEquals($retryAssert, $count);
        $this->assertEquals(SymfonyResponse::HTTP_OK, $response->getStatusCode());
    }

    /**
     * @return array
     */
    public function retriesPassAfterSpecifiedAttemptsProvider(): array
    {
        $response = new Response(SymfonyResponse::HTTP_TOO_MANY_REQUESTS);
        $connectException = new ConnectException(
            '',
            new Request(SymfonyRequest::METHOD_GET, '/'),
            null,
            ['errno' => 28]
        );

        return [
            ['responses' => array_merge(array_fill(0, 2, $response), [new Response(200, [])]), 'retry' => 2],
            ['responses' => array_merge(array_fill(0, 3, $connectException), [new Response(200, [])]), 'retry' => 3],
        ];
    }

    public function testRetryAfterHeader()
    {
        $retryAfter = 2;
        $responses = [
            new Response(SymfonyResponse::HTTP_TOO_MANY_REQUESTS, ['Retry-After' => $retryAfter]),
            new Response(SymfonyResponse::HTTP_OK, []),
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
    public function testRetryDisabled(array $responses)
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
        $client->request(SymfonyRequest::METHOD_GET, '/');
    }

    public function retryDisabledProvider()
    {
        return [
            [
                [
                    new Response(SymfonyResponse::HTTP_TOO_MANY_REQUESTS, []),
                    new Response(SymfonyResponse::HTTP_OK, []),
                ],
            ],
            [
                [
                    new ConnectException(
                        '',
                        new Request(SymfonyRequest::METHOD_GET, '/'),
                        null,
                        ['errno' => 28]
                    ),
                    new Response(SymfonyResponse::HTTP_OK, []),
                ],
            ],
        ];
    }

    /**
     * Test Retry After Seconds option.
     */
    public function testRetryAfterSeconds()
    {
        $responses = [
            new Response(SymfonyResponse::HTTP_TOO_MANY_REQUESTS, []),
            new Response(SymfonyResponse::HTTP_OK, []),
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

        $client->request(SymfonyRequest::METHOD_GET, '/');
        $this->assertEquals(2, $delayed);
    }

    /**
     * @dataProvider forcedRetryAfterOption
     *
     * @param array $responses
     */
    public function testForcedRetryAfterOption(array $responses)
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
        $client->request(SymfonyRequest::METHOD_GET, '/');
    }

    public function forcedRetryAfterOption()
    {
        return [
            [
                [
                    new Response(SymfonyResponse::HTTP_TOO_MANY_REQUESTS, []),
                    new Response(SymfonyResponse::HTTP_OK, []),
                ],
            ],
            [
                [
                    new ConnectException(
                        '',
                        new Request(SymfonyRequest::METHOD_GET, '/'),
                        null,
                        ['errno' => 28]
                    ),
                    new Response(SymfonyResponse::HTTP_OK, []),
                ],
            ],
            [
                [
                    new ConnectException(
                        '',
                        new Request(SymfonyRequest::METHOD_GET, '/'),
                        null,
                        ['errno' => 28]
                    ),
                    new Response(SymfonyResponse::HTTP_OK, []),
                ],
            ],
            [
                [
                    new BadResponseException(
                        '',
                        new Request(SymfonyRequest::METHOD_GET, '/'),
                        new Response(SymfonyResponse::HTTP_TOO_MANY_REQUESTS, [])
                    ),
                    new Response(SymfonyResponse::HTTP_OK, []),
                ],
            ],
            [
                [
                    new BadResponseException(
                        '',
                        new Request(SymfonyRequest::METHOD_GET, '/'),
                        null
                    ),
                    new Response(SymfonyResponse::HTTP_OK, []),
                ],
            ],
        ];
    }

    public function testAddRetryHeader()
    {
        $responses = [
            new BadResponseException(
                '',
                new Request(SymfonyRequest::METHOD_GET, '/'),
                new Response(SymfonyResponse::HTTP_TOO_MANY_REQUESTS, [])
            ),
            new Response(SymfonyResponse::HTTP_OK, []),
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

        $response = $client->request(SymfonyRequest::METHOD_GET, '/');
        $this->assertArrayHasKey(RetryMiddleware::RETRY_HEADER, $response->getHeaders());
    }

    public function testShouldNotRetryWhileConnectionErrorNot()
    {
        $responses = [
            new ConnectException(
                '',
                new Request(SymfonyRequest::METHOD_GET, '/'),
                null,
                ['errno' => 1]
            ),
            new Response(SymfonyResponse::HTTP_OK, []),
        ];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(RetryMiddleware::factory());
        $client = new Client(['handler' => $stack]);

        $this->expectException(GuzzleException::class);
        $client->request(SymfonyRequest::METHOD_GET, '/');
    }
}
