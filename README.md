# Mint Retry Middleware

This is a [Guzzle v6](http://guzzlephp.org) middleware, which retry requests with response status codes `503` or `429` by default, also can retry timeout requests.
Both options are configurable. When response provide a `Retry-After` header, this middleware has option to delay requests by provided time amount.

Features:

- Automatically retries HTTP requests when a server responds with a configurable statues (429 or 503 by default).
- Add retry delay based on the `Retry-After` header.
- Specify a maximum number of retry attempts.
- Optional callback when a retry occurs for eg. logging / custom actions.

## Require
```bash
"php": "~7.1"
"guzzlehttp/guzzle": "^6.0"
```

## Installation

``` bash
composer require mintsoftware/guzzle_retry_middleware
```

### Configurable options

| Option                                                                                | Type              | Default  | 
| ------------------------------------------------------------------------------------- | ----------------- | -------- | 
| `\MintSoftware\GuzzleRetry\RetryMiddleware::OPTIONS_RETRY_ENABLED`                    | boolean           | true     | 
| `\MintSoftware\GuzzleRetry\RetryMiddleware::OPTIONS_MAX_RETRY_ATTEMPTS`               | integer           | 10       |
| `\MintSoftware\GuzzleRetry\RetryMiddleware::OPTIONS_RETRY_AFTER_SECONDS`              | integer           | 1        | 
| `\MintSoftware\GuzzleRetry\RetryMiddleware::OPTIONS_RETRY_ONLY_IF_RETRY_AFTER_HEADER` | boolean           | false    | 
| `\MintSoftware\GuzzleRetry\RetryMiddleware::OPTIONS_RETRY_ON_STATUS`                  | array<int>        | 503, 429 | 
| `\MintSoftware\GuzzleRetry\RetryMiddleware::OPTIONS_CALLBACK`                         | callable          | null     | 
| `\MintSoftware\GuzzleRetry\RetryMiddleware::OPTIONS_RETRY_ON_TIMEOUT`                 | boolean           | false    | 
| `\MintSoftware\GuzzleRetry\RetryMiddleware::OPTIONS_RETRY_HEADER`                     | boolean           | false    |

## Tests

``` bash
composer test
```

## Contirubtion

Feel free to contribute
