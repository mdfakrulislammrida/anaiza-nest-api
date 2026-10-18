<?php

namespace App\Support\Courier;

use RuntimeException;

/**
 * A courier call that did not give us what we needed. The message is safe to show in the admin: it never holds a key, a
 * token or a request body. `kind` says what to do about it.
 */
class CourierException extends RuntimeException
{
    /** The keys or login were refused: fix them. Never retried (Steadfast locks the client out after ten of these in five minutes). */
    public const AUTH = 'auth';

    /** The courier could not be reached, or did not answer in time. */
    public const NETWORK = 'network';

    /** The courier is busy or down (429, 5xx). */
    public const UNAVAILABLE = 'unavailable';

    /** The courier read the request and said no (a bad field, a refused order). */
    public const REJECTED = 'rejected';

    /** Steadfast refuses an invoice it already has: the earlier request got through. */
    public const DUPLICATE = 'duplicate';

    /** An answer we could not read. */
    public const UNEXPECTED = 'unexpected';

    public function __construct(string $message, public readonly string $kind = self::UNEXPECTED)
    {
        parent::__construct($message);
    }

    /** Whether trying the same call again later can help (an outage, not a refusal). */
    public function isTemporary(): bool
    {
        return in_array($this->kind, [self::NETWORK, self::UNAVAILABLE], true);
    }
}
