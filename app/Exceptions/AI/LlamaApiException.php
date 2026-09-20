<?php

namespace App\Exceptions\AI;

use RuntimeException;

/**
 * Thrown when the Routeway/Llama API call fails (network error, timeout,
 * non-2xx response, or an unparseable response body).
 */
class LlamaApiException extends RuntimeException
{
}
