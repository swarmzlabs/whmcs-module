<?php
/**
 * Swarmz WHMCS Module - Exceptions
 *
 * @copyright Swarmz Labs Ltd.
 * @license MIT
 */

namespace WHMCS\Module\Server\Swarmz;

if (!defined('WHMCS')) {
    die('You cannot access this file directly.');
}

/**
 * Base exception for all Swarmz API failures.
 */
class SwarmzException extends \Exception
{
    /** @var int HTTP status code from the swarmz API (0 if transport error). */
    protected $statusCode = 0;

    /** @var string Machine-readable error code from the swarmz API (e.g. "tenant_not_found"). */
    protected $errorCode = '';

    /** @var array Decoded response body. */
    protected $responseBody = [];

    public function __construct($message = '', $statusCode = 0, $errorCode = '', array $responseBody = [], \Throwable $previous = null)
    {
        parent::__construct($message, $statusCode, $previous);
        $this->statusCode = $statusCode;
        $this->errorCode = $errorCode;
        $this->responseBody = $responseBody;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getResponseBody(): array
    {
        return $this->responseBody;
    }
}

/**
 * Configuration is missing or invalid (e.g. no API key set on the server).
 */
class SwarmzConfigException extends SwarmzException {}

/**
 * Transport failure (cURL error, DNS, timeout, non-JSON body).
 */
class SwarmzTransportException extends SwarmzException {}

/**
 * The swarmz API returned a 4xx/5xx with a structured error body.
 */
class SwarmzApiException extends SwarmzException {}
