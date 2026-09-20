<?php

namespace App\Exceptions\AI;

use RuntimeException;

/**
 * Thrown when the AI's response can't be parsed as the expected structured
 * output, or fails validation. Signals "don't save this" — never persist an
 * unvalidated LLM response.
 */
class InvalidAiResponseException extends RuntimeException
{
}
