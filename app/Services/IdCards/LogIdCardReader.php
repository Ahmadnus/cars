<?php

namespace App\Services\IdCards;

use Illuminate\Support\Facades\Log;

/**
 * The reader bound when no provider is configured.
 *
 * It reads nothing and says so. Keeping it rather than binding null is what lets
 * every caller treat the feature as present-but-off: the screens render, the
 * buttons are hidden by `isEnabled()`, and an install with no key behaves like
 * one whose provider is having a bad day — which is a path worth exercising
 * anyway.
 */
class LogIdCardReader implements IdCardReader
{
    public function isEnabled(): bool
    {
        return false;
    }

    public function read(string $bytes, string $mimeType): IdCardReading
    {
        Log::info('[id-reader] طُلبت قراءة هوية بدون مزوّد مُهيّأ.', [
            'mime_type' => $mimeType,
            'size_bytes' => strlen($bytes),
        ]);

        return IdCardReading::disabled();
    }
}
