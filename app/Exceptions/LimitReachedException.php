<?php

namespace App\Exceptions;

/** A customer's own spend limit stopped this entry (recorded for harm monitoring once the transaction has rolled back). */
class LimitReachedException extends EntryException
{
    public function __construct(string $message, public readonly string $period)
    {
        parent::__construct($message);
    }
}
