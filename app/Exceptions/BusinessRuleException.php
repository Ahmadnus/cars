<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * A business rule refused the operation.
 *
 * Carries a user-facing Arabic message, so services can reject invalid work
 * without knowing whether they were called from a controller or the API. The
 * message is safe to display; nothing internal leaks through it.
 */
class BusinessRuleException extends RuntimeException
{
    /** @param array<string, string[]> $errors field-scoped messages, for form redisplay */
    public function __construct(
        string $message,
        protected array $errors = [],
        protected int $status = 422,
    ) {
        parent::__construct($message);
    }

    public static function make(string $message, array $errors = []): self
    {
        return new self($message, $errors);
    }

    /** @return array<string, string[]> */
    public function errors(): array
    {
        return $this->errors;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json([
                'success' => false,
                'message' => $this->getMessage(),
                'errors' => (object) $this->errors,
            ], $this->status);
        }

        return back()
            ->withInput()
            ->withErrors($this->errors ?: ['error' => $this->getMessage()])
            ->with('toast', ['type' => 'error', 'message' => $this->getMessage()]);
    }
}
