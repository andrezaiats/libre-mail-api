<?php

namespace LibreMailApi\Campaign;

/**
 * One message could not be sent. `transient` = the server was unreachable, timed out,
 * refused authentication or answered 4xx: the message was NOT accepted and may be retried.
 * Otherwise (5xx / invalid recipient) the delivery is final and marked failed.
 */
class SendFailed extends \RuntimeException
{
    public bool $transient = false;

    public static function from(string $message, bool $transient, ?\Throwable $previous = null): self
    {
        $e = new self($message, 0, $previous);
        $e->transient = $transient;
        return $e;
    }
}
