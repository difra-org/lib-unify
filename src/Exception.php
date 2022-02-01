<?php

declare(strict_types=1);

namespace Difra\Unify;

use Throwable;
use Difra\Unify\Stubs\LogStub;

class Exception extends \Exception
{
    public function __construct($message = "", $code = 0, Throwable $previous = null, mixed $context = null, ?LogStub $log = null)
    {
        parent::__construct($message, $code, $previous);
        static::log(
            log: $log,
            message: $message,
            context: $context,
            exception: $this
        );
    }

    public static function log(?LogStub $log = null, string $message = '', mixed $context = null, \Exception $exception = null): void
    {
        $log?->write($message, context: $context, exception: $exception);
    }
}