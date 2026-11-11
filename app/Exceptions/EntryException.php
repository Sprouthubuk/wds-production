<?php

namespace App\Exceptions;

use RuntimeException;

/** A customer-facing reason an entry can't be accepted. Rendered as 422 JSON. */
class EntryException extends RuntimeException
{
    public function render()
    {
        return response()->json(['message' => $this->getMessage()], 422);
    }
}
