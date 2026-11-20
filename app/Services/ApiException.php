<?php

namespace App\Services;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/** An error returned to an installation as {"error": code, "message": …}. Expected, so not written to the error log. */
class ApiException extends RuntimeException
{
    public function __construct(public string $errorCode, string $message, public int $status = 400)
    {
        parent::__construct($message);
    }

    public function report(): bool
    {
        return true; // handled: the installation receives the message; the event log keeps the history
    }

    public function render(): JsonResponse
    {
        return response()->json(['error' => $this->errorCode, 'message' => $this->getMessage()], $this->status);
    }
}
