<?php

namespace App\Exceptions\AI;

use RuntimeException;

/**
 * Thrown when an AI task that must be grounded in uploaded learning material
 * has nothing to work from (no modules, or no readable text in them). The
 * message is safe to show to the user.
 */
class NoSourceContentException extends RuntimeException
{
}
