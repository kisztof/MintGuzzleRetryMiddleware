<?php declare(strict_types=1);

namespace MintSoftware\GuzzleRetry;

use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use function GuzzleHttp\Promise\rejection_for;

/**
 * Class RetryMiddleware.
 */
class RetryMiddleware
{
    public const RETRY_HEADER = 'X-Retry-Counter';
    public const OPTIONS_RETRY_ENABLED = 'retry_enabled';
    public const OPTIONS_MAX_RETRY_ATTEMPTS = 'max_retry_attempts';
    public const OPTIONS_RETRY_ONLY_IF_RETRY_AFTER_HEADER = 'retry_only_if_retry_after_header';
    public const OPTIONS_RETRY_ON_STATUS = 'retry_on_statuses';
    public const OPTIONS_RETRY_HEADER = 'retry_header';
    public const OPTIONS_RETRY_ON_TIMEOUT = 'retry_on_timeout';
    public const OPTIONS_RETRY_AFTER_SECONDS = 'retry_after_seconds';
    public const OPTIONS_CALLBACK = 'callback';

    private const RETRY_AFTER = 'Retry-After';
    private const RETRY_COUNT = 'retry_count';
    private const HTTP_TOO_MANY_REQUESTS = 429;
    private const HTTP_SERVICE_UNAVAILABLE = 503;

    private $defaultOptions = [
        self::OPTIONS_RETRY_ENABLED => true,
        self::OPTIONS_MAX_RETRY_ATTEMPTS => 10,
        self::OPTIONS_RETRY_AFTER_SECONDS => 1,
        self::OPTIONS_RETRY_ONLY_IF_RETRY_AFTER_HEADER => false,
        self::OPTIONS_RETRY_ON_STATUS => [
            self::HTTP_TOO_MANY_REQUESTS,
            self::HTTP_SERVICE_UNAVAILABLE,
        ],
        self::OPTIONS_RETRY_ON_TIMEOUT => false,
        self::OPTIONS_CALLBACK => null,
    ];
    /**
     * @var callable
     */
    private $nextHandler;

    /**
     * @param array $options
     *
     * @return \Closure
     */
    public static function factory(array $options = []): \Closure
    {
        return function (callable $handler) use ($options) {
            return new static($handler, $options);
        };
    }

    /**
     * RetryMiddleware constructor.
     *
     * @param callable $nextHandler
     * @param array    $defaultOptions
     */
    public function __construct(callable $nextHandler, array $defaultOptions = [])
    {
        $this->nextHandler = $nextHandler;
        $this->defaultOptions = array_replace($this->defaultOptions, $defaultOptions);
    }

    /**
     * @param RequestInterface $request
     * @param array            $options
     *
     * @return PromiseInterface
     */
    public function __invoke(RequestInterface $request, array $options): PromiseInterface
    {
        $options = array_replace($this->defaultOptions, $options);
        $options[self::RETRY_COUNT] = $options[self::RETRY_COUNT] ?? 0;

        $next = $this->nextHandler;

        return $next($request, $options)
            ->then(
                $this->onFulfilled($request, $options),
                $this->onRejected($request, $options)
            );
    }

    /**
     * Retry on configurable response code.
     *
     * @param RequestInterface $request
     * @param array            $options
     *
     * @return \Closure
     */
    private function onFulfilled(RequestInterface $request, array $options): \Closure
    {
        return function (ResponseInterface $response) use ($request, $options) {
            if (true === $this->shouldRetryOnHttpResponse($options, $response)) {
                return $this->retry($request, $options, $response);
            }

            return $this->returnResponse($response, $options);
        };
    }

    /**
     * Retry on connection problems or configurable response code.
     *
     * @param RequestInterface $request
     * @param array            $options
     *
     * @return \Closure
     */
    private function onRejected(RequestInterface $request, array $options): \Closure
    {
        return function ($reason) use ($request, $options) {
            if ($reason instanceof BadResponseException) {
                if (true === $this->shouldRetryOnHttpResponse($options, $reason->getResponse())) {
                    return $this->retry($request, $options, $reason->getResponse());
                }
            }

            if ($reason instanceof ConnectException) {
                if (true === $this->shouldRetryOnConnectionException($reason, $options)) {
                    return $this->retry($request, $options);
                }
            }

            return rejection_for($reason);
        };
    }

    /**
     * When timeout or connection problem occurred.
     *
     * @param ConnectException $exception
     * @param array            $options
     *
     * @return bool
     */
    private function shouldRetryOnConnectionException(ConnectException $exception, array $options): bool
    {
        if (!$options[self::OPTIONS_RETRY_ENABLED]) {
            return false;
        }

        //max retry
        if (0 === $this->remainingRetires($options)) {
            return false;
        }

        //timeout
        if (isset($exception->getHandlerContext()['errno']) && 28 === $exception->getHandlerContext()['errno']) {
            return true === $options[self::OPTIONS_RETRY_ON_TIMEOUT];
        }

        return false;
    }

    /**
     * Should retry on configured HTTP Response codes.
     *
     * @param array                  $options
     * @param ResponseInterface|null $response
     *
     * @return bool
     */
    private function shouldRetryOnHttpResponse(array $options, ?ResponseInterface $response): bool
    {
        $statuses = \array_map('\intval', $options[self::OPTIONS_RETRY_ON_STATUS]);

        if (false === $options[self::OPTIONS_RETRY_ENABLED]) {
            return false;
        }

        // max retry
        if (0 === $this->remainingRetires($options)) {
            return false;
        }

        if (null === $response) {
            return false;
        }

        if ($options[self::OPTIONS_RETRY_ONLY_IF_RETRY_AFTER_HEADER] && !$response->hasHeader(self::RETRY_AFTER)) {
            return false;
        }

        return \in_array($response->getStatusCode(), $statuses, true);
    }

    /**
     * Returns remaining retry attempts.
     *
     * @param array $options
     *
     * @return int
     */
    private function remainingRetires(array $options): int
    {
        $count = (int) $options[self::RETRY_COUNT] ?? 0;
        $max = (int) $options[self::OPTIONS_MAX_RETRY_ATTEMPTS] ?? 0;

        return \max($max - $count, 0);
    }

    /**
     * Return seconds to delay.
     *
     * @param array                  $options
     * @param ResponseInterface|null $response
     *
     * @return int
     */
    private function delayAfter(array $options, ?ResponseInterface $response = null): int
    {
        //If Response support Retry-After header
        if ($response && $response->hasHeader(self::RETRY_AFTER)) {
            return (int) \trim($response->getHeader(self::RETRY_AFTER)[0]);
        }

        return (int) $options[self::OPTIONS_RETRY_AFTER_SECONDS];
    }

    /**
     * Return Response or Response with Retry Header depends on option.
     *
     * @param ResponseInterface $response
     * @param array             $options
     *
     * @return ResponseInterface
     */
    private function returnResponse(ResponseInterface $response, array $options): ResponseInterface
    {
        if (0 === $options[self::RETRY_COUNT] || !isset($options[self::OPTIONS_RETRY_HEADER])) {
            return $response;
        }

        return $response->withHeader($options[self::OPTIONS_RETRY_HEADER], $options[self::RETRY_COUNT]);
    }

    /**
     * @param RequestInterface       $request
     * @param array                  $options
     * @param ResponseInterface|null $response
     *
     * @return PromiseInterface
     */
    private function retry(RequestInterface $request, array $options, ResponseInterface $response = null): PromiseInterface
    {
        $options[self::RETRY_COUNT] += 1;
        $delay = $this->delayAfter($options, $response);

        if (\is_callable($options[self::OPTIONS_CALLBACK])) {
            \call_user_func($options[self::OPTIONS_CALLBACK], (float) $delay, $options, $request, $response);
        }

        \sleep($delay);

        return $this($request, $options);
    }
}
