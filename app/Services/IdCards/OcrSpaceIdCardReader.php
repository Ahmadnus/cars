<?php

namespace App\Services\IdCards;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Reads an ID card with OCR.space, the free option.
 *
 * It is OCR, not a model that understands a document: it hands back words and
 * where they sit, and `JordanianIdCardLayout` decides which word is the national
 * number. That division is deliberate — the fragile part is the layout reading,
 * and it is testable without a network.
 *
 * Three things about the free tier shape this class. Only engine 3 accepts
 * Arabic — the other two refuse the request outright — so the engine is not
 * really optional. Uploads are capped at 1MB, well under what a phone camera
 * produces, so the photo is recompressed here rather than refused. And the
 * engine-3 allowance is a few thousand reads a month, which is why a reading is
 * something staff ask for rather than something that happens on arrival.
 *
 * Nothing the card says is ever logged.
 */
class OcrSpaceIdCardReader implements IdCardReader
{
    public const ENDPOINT = 'https://api.ocr.space/parse/image';

    /** What the API accepts, and what a phone camera produces. */
    public const SUPPORTED = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    /** The free tier's ceiling is 1MB; this leaves room for the form around it. */
    public const MAX_UPLOAD_BYTES = 900_000;

    public function __construct(
        protected bool $enabled,
        protected ?string $apiKey,
        protected int $engine = 3,
        protected string $language = 'ara',
        protected int $timeout = 60,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->enabled && filled($this->apiKey);
    }

    public function read(string $bytes, string $mimeType): IdCardReading
    {
        if (! $this->isEnabled()) {
            return IdCardReading::disabled();
        }

        if (! in_array($mimeType, self::SUPPORTED, true)) {
            return IdCardReading::failed('نوع الملف غير مدعوم للقراءة. استخدم صورة JPG أو PNG.');
        }

        $upload = $this->fit($bytes);

        if ($upload === null) {
            return IdCardReading::failed('تعذّر تحضير الصورة للقراءة. حاول بصورة أصغر.');
        }

        try {
            $response = Http::withHeaders(['apikey' => $this->apiKey])
                ->timeout($this->timeout)
                // `attach` makes the request multipart, so the fields below ride
                // along with the file rather than as a JSON body.
                ->attach('file', $upload, 'id-card.jpg')
                ->post(self::ENDPOINT, [
                    'language' => $this->language,
                    'OCREngine' => (string) $this->engine,
                    // Coordinates. Without them the labels cannot be paired with
                    // their values, and the mother's national number becomes the
                    // applicant's.
                    'isOverlayRequired' => 'true',
                    'detectOrientation' => 'true',
                    'scale' => 'true',
                ]);
        } catch (ConnectionException) {
            return IdCardReading::failed('تعذّر الاتصال بخدمة قراءة الهوية.');
        } catch (\Throwable $e) {
            report($e);

            return IdCardReading::failed('تعذّرت قراءة الهوية حالياً. أدخل البيانات يدوياً.');
        }

        if ($response->failed()) {
            Log::warning('[id-reader] رفضت خدمة OCR الطلب.', ['status' => $response->status()]);

            return IdCardReading::failed('تعذّرت قراءة الهوية حالياً. أدخل البيانات يدوياً.');
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            return IdCardReading::failed('جاء رد غير متوقع من خدمة القراءة.');
        }

        /*
         | The service answers 200 with the failure inside the body — a rejected
         | language, a quota that ran out — so the body is what decides.
         */
        if (($payload['IsErroredOnProcessing'] ?? false) || (int) ($payload['OCRExitCode'] ?? 0) > 2) {
            Log::warning('[id-reader] أبلغت خدمة OCR عن خطأ.', [
                'exit_code' => $payload['OCRExitCode'] ?? null,
                'error' => $this->errorOf($payload),
            ]);

            return IdCardReading::failed('تعذّرت قراءة الهوية حالياً. أدخل البيانات يدوياً.');
        }

        $result = $payload['ParsedResults'][0] ?? [];
        $chunks = $this->chunks($result);

        $reading = IdCardReading::fromModel(
            $chunks !== []
                ? JordanianIdCardLayout::fromChunks($chunks)
                : JordanianIdCardLayout::fromText((string) ($result['ParsedText'] ?? '')),
        );

        Log::info('[id-reader] تمت قراءة صورة هوية.', [
            'provider' => 'ocr.space',
            'outcome' => $reading->outcome,
            'confidence' => $reading->confidence,
            // Keys only. The values are the applicant's identity details.
            'filled' => array_keys($reading->fields()),
            'unclear' => $reading->unclear,
        ]);

        return $reading;
    }

    /**
     * The positioned words, flattened to one chunk per line.
     *
     * Engine 3 returns each line as a single word carrying the whole line's text
     * and box, which is exactly the unit the layout reader wants; a service that
     * split them per word is handled by taking the line's extent.
     *
     * @param  array<string, mixed>  $result
     * @return list<array{text: string, left: int, top: int, width: int, height: int}>
     */
    protected function chunks(array $result): array
    {
        $lines = $result['TextOverlay']['Lines'] ?? null;

        if (! is_array($lines)) {
            return [];
        }

        $chunks = [];

        foreach ($lines as $line) {
            $words = is_array($line['Words'] ?? null) ? $line['Words'] : [];

            if ($words === []) {
                continue;
            }

            $text = trim(implode(' ', array_map(static fn ($word) => (string) ($word['WordText'] ?? ''), $words)));

            if ($text === '') {
                continue;
            }

            $lefts = array_map(static fn ($word) => (int) ($word['Left'] ?? 0), $words);
            $rights = array_map(
                static fn ($word) => (int) ($word['Left'] ?? 0) + (int) ($word['Width'] ?? 0),
                $words,
            );

            $chunks[] = [
                'text' => $text,
                'left' => min($lefts),
                'top' => (int) ($line['MinTop'] ?? ($words[0]['Top'] ?? 0)),
                'width' => max(1, max($rights) - min($lefts)),
                'height' => max(1, (int) ($line['MaxHeight'] ?? ($words[0]['Height'] ?? 20))),
            ];
        }

        return $chunks;
    }

    /**
     * The photo, within the free tier's upload limit.
     *
     * Re-encoded rather than refused: the limit is 1MB and the app sends up to
     * 8MB, so a refusal here would mean the feature never ran on a real photo.
     * Shrunk by quality first and then by size, because an ID is read from its
     * text and 1400px is more than enough to resolve a ten-digit number.
     */
    protected function fit(string $bytes): ?string
    {
        if (strlen($bytes) <= self::MAX_UPLOAD_BYTES) {
            return $bytes;
        }

        if (! function_exists('imagecreatefromstring')) {
            // Without GD there is nothing to shrink with; say so rather than
            // send a file the service will reject.
            Log::warning('[id-reader] الصورة أكبر من حد الخدمة ولا توجد GD لتصغيرها.');

            return null;
        }

        $image = @imagecreatefromstring($bytes);

        if ($image === false) {
            return null;
        }

        foreach ([1600, 1400, 1100, 900] as $width) {
            $scaled = imagescale($image, $width);

            if ($scaled === false) {
                continue;
            }

            foreach ([80, 65, 50] as $quality) {
                ob_start();
                imagejpeg($scaled, null, $quality);
                $encoded = (string) ob_get_clean();

                if ($encoded !== '' && strlen($encoded) <= self::MAX_UPLOAD_BYTES) {
                    imagedestroy($scaled);
                    imagedestroy($image);

                    return $encoded;
                }
            }

            imagedestroy($scaled);
        }

        imagedestroy($image);

        return null;
    }

    /** @param array<string, mixed> $payload */
    protected function errorOf(array $payload): ?string
    {
        $error = $payload['ErrorMessage'] ?? $payload['ParsedResults'][0]['ErrorMessage'] ?? null;

        if (is_array($error)) {
            $error = implode(' | ', array_map(static fn ($line) => (string) $line, $error));
        }

        return is_string($error) && $error !== '' ? $error : null;
    }
}
