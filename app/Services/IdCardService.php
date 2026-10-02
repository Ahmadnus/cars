<?php

namespace App\Services;

use App\Models\Document;
use App\Models\RegistrationRequest;
use App\Services\IdCards\IdCardReader;
use App\Services\IdCards\IdCardReading;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * One entry point for "read this ID photo and tell me what it says".
 *
 * Three screens ask for it — an applicant attaching a photo in the app, a
 * receptionist opening a request in the queue, a receptionist registering someone
 * standing at the desk — and all three ask the same question of the same reader,
 * so a card is read the same way wherever it enters the system.
 *
 * Wrapping the reader rather than calling it directly is what keeps the callers
 * free of "is this configured" checks, and it is where a reading is cached: an
 * application's card is read once and kept on the row, not re-read every time the
 * queue is refreshed.
 */
class IdCardService
{
    public function __construct(protected IdCardReader $reader)
    {
    }

    public function isEnabled(): bool
    {
        return $this->reader->isEnabled();
    }

    /**
     * Read a photo still in the request, before anything has been stored.
     *
     * The type is sniffed from the bytes, never taken from the name the client
     * sent — the same rule DocumentService applies before storing one.
     */
    public function readUpload(UploadedFile $file): IdCardReading
    {
        if (! $file->isValid()) {
            return IdCardReading::failed('الملف المرفوع غير صالح.');
        }

        if ($file->getSize() > DocumentService::MAX_BYTES) {
            return IdCardReading::failed('حجم الصورة يتجاوز الحد المسموح (8 ميجابايت).');
        }

        $bytes = @file_get_contents($file->getRealPath());

        if ($bytes === false) {
            return IdCardReading::failed('تعذّر قراءة الملف المرفوع.');
        }

        return $this->reader->read($bytes, (string) $file->getMimeType());
    }

    /**
     * A reading asked for by someone with no account yet.
     *
     * The same read, behind a cap on how many of them the center will pay for in
     * a day. Reaching the cap reads as a reader that is briefly unavailable,
     * which is what it is from the applicant's side, and leaves the form working:
     * they type their details in as they would have before this existed.
     *
     * Counted by asking, not by succeeding — the request is what costs money.
     */
    public function readPublicUpload(UploadedFile $file): IdCardReading
    {
        $limit = (int) config('id_reader.daily_limit', 0);

        if ($limit > 0 && $this->publicReadingsToday() > $limit) {
            Log::warning('[id-reader] بلغت القراءات العامة الحدّ اليومي.', ['limit' => $limit]);

            return IdCardReading::failed(
                'قراءة الهوية غير متاحة حالياً. أدخل بياناتك يدوياً وأرفق الصورة كما هي.',
            );
        }

        return $this->readUpload($file);
    }

    /**
     * Today's count, incremented atomically.
     *
     * `add` then `increment` rather than read-then-write: two applicants
     * submitting at the same moment must not both read the same number and both
     * be allowed through at the cap.
     */
    protected function publicReadingsToday(): int
    {
        $key = 'id_reader:public_readings:'.now()->toDateString();

        // A little past midnight, so the last reading of the day does not expire
        // the counter early for the first of the next.
        Cache::add($key, 0, now()->endOfDay()->addHour());

        return (int) Cache::increment($key);
    }

    /** Read a photo already stored against a record. */
    public function readDocument(Document $document): IdCardReading
    {
        $disk = Storage::disk($document->disk);

        if (! $disk->exists($document->path)) {
            return IdCardReading::failed('الملف غير موجود على الخادم.');
        }

        $bytes = $disk->get($document->path);

        if ($bytes === null || $bytes === '') {
            return IdCardReading::failed('تعذّر قراءة الملف من القرص.');
        }

        return $this->reader->read($bytes, $this->sniff($bytes, $document));
    }

    /**
     * The reading for a join request.
     *
     * Kept on the row once it has run, for two reasons: the queue page is
     * reloaded and polled constantly, and every reading costs money and a few
     * seconds of someone's attention. `$refresh` is the escape hatch for a staff
     * member who re-reads a card after the applicant sends a better photo.
     */
    public function forRegistration(RegistrationRequest $request, bool $refresh = false): IdCardReading
    {
        if (! $refresh && is_array($request->id_scan) && $request->id_scan !== []) {
            return IdCardReading::fromArray($request->id_scan);
        }

        $photo = $request->idPhoto();

        if (! $photo) {
            return IdCardReading::failed('لا توجد صورة هوية مرفقة بهذا الطلب.');
        }

        $reading = $this->readDocument($photo);

        /*
         | Only an answer is stored. A provider outage or a disabled reader is a
         | state of the server, not a fact about this application — cache it and
         | the request would carry "could not be read" for good, and the button
         | that would try again is the one that reads the cache.
         */
        if (in_array($reading->outcome, [IdCardReading::OUTCOME_READ, IdCardReading::OUTCOME_EMPTY], true)) {
            $request->forceFill([
                'id_scan' => $reading->toArray(),
                'id_scanned_at' => now(),
            ])->save();
        }

        return $reading;
    }

    /**
     * The real type of a stored file.
     *
     * `mime_type` on the row is what the uploader's browser claimed, so it is the
     * fallback rather than the answer: a file stored months ago under a wrong
     * content type should still be read if it is in fact a JPEG.
     */
    protected function sniff(string $bytes, Document $document): string
    {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);

            if ($finfo !== false) {
                $detected = finfo_buffer($finfo, $bytes);
                finfo_close($finfo);

                if (is_string($detected) && $detected !== '' && $detected !== 'application/octet-stream') {
                    return $detected;
                }
            }
        }

        return (string) $document->mime_type;
    }
}
