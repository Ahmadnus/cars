<?php

namespace App\Services\IdCards;

/**
 * Something that can read an applicant's details off a photo of their ID.
 *
 * A seam rather than a class, for the same reason the notification channels have
 * one: the provider is a decision the center can change — or not make at all.
 * With nothing configured the log reader is bound, every screen still renders,
 * and the only difference is that it does not offer to fill the form.
 */
interface IdCardReader
{
    /** Whether a reading will actually be attempted. */
    public function isEnabled(): bool;

    /**
     * Read one image.
     *
     * @param  string  $bytes  The image itself, as read from disk or the upload.
     * @param  string  $mimeType  Sniffed from the bytes by the caller, never the
     *                            name the client sent.
     */
    public function read(string $bytes, string $mimeType): IdCardReading;
}
