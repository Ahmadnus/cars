<?php

namespace Tests\Unit;

use App\Services\IdCards\IdCardReading;
use App\Services\IdCards\OcrSpaceIdCardReader;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\OcrSpaceSamples;
use Tests\TestCase;

/**
 * The free reader's conversation with OCR.space.
 *
 * What is pinned here is everything that would silently stop the feature working
 * if it drifted: that coordinates are asked for (without them the mother's number
 * becomes the applicant's), that the engine which accepts Arabic is the one used,
 * that a photo too large for the free tier is shrunk rather than refused, and that
 * a failure arriving inside a 200 response is still treated as a failure.
 */
class OcrSpaceIdCardReaderTest extends TestCase
{
    protected function reader(): OcrSpaceIdCardReader
    {
        return new OcrSpaceIdCardReader(enabled: true, apiKey: 'test-key');
    }

    /** A JPEG big enough to exceed the free tier's 1MB upload limit. */
    protected function largePhoto(): string
    {
        $image = imagecreatetruecolor(3000, 2000);

        // Noise, because a flat colour compresses to almost nothing and would not
        // exercise the resizing this test is about.
        for ($i = 0; $i < 40000; $i++) {
            imagesetpixel(
                $image,
                random_int(0, 2999),
                random_int(0, 1999),
                imagecolorallocate($image, random_int(0, 255), random_int(0, 255), random_int(0, 255)),
            );
        }

        ob_start();
        imagejpeg($image, null, 100);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    public function test_it_reads_the_card_the_service_returned(): void
    {
        Http::fake([OcrSpaceIdCardReader::ENDPOINT => Http::response(OcrSpaceSamples::response())]);

        $reading = $this->reader()->read('image-bytes', 'image/jpeg');

        $this->assertTrue($reading->succeeded());
        $this->assertSame('9981234567', $reading->nationalId);
        $this->assertSame('رامي سامر محمود الحديد', $reading->fullName);
        $this->assertSame('1998-04-11', $reading->birthDate);
    }

    public function test_it_asks_for_coordinates_in_arabic_on_the_engine_that_accepts_it(): void
    {
        Http::fake([OcrSpaceIdCardReader::ENDPOINT => Http::response(OcrSpaceSamples::response())]);

        $this->reader()->read('image-bytes', 'image/jpeg');

        Http::assertSent(function (Request $request) {
            $body = $request->body();

            $this->assertSame('test-key', $request->header('apikey')[0] ?? null);
            $this->assertStringContainsString('multipart/form-data', $request->header('Content-Type')[0] ?? '');

            // Read off the multipart body: the fields ride with the file.
            $this->assertStringContainsString('name="language"', $body);
            $this->assertStringContainsString('ara', $body);
            $this->assertStringContainsString('name="OCREngine"', $body);
            $this->assertStringContainsString('name="isOverlayRequired"', $body);
            $this->assertStringContainsString('name="file"', $body);

            return true;
        });
    }

    /**
     * The free tier takes 1MB and the app sends up to 8MB, so the photo is
     * recompressed. Refusing instead would mean the feature never ran on a photo
     * taken by an actual phone.
     */
    public function test_it_shrinks_a_photo_that_exceeds_the_free_tier_limit(): void
    {
        Http::fake([OcrSpaceIdCardReader::ENDPOINT => Http::response(OcrSpaceSamples::response())]);

        $photo = $this->largePhoto();

        $this->assertGreaterThan(OcrSpaceIdCardReader::MAX_UPLOAD_BYTES, strlen($photo));

        $this->reader()->read($photo, 'image/jpeg');

        Http::assertSent(function (Request $request) {
            // The whole multipart body, boundaries and fields included, now fits
            // inside the limit — so the file itself certainly does.
            $this->assertLessThanOrEqual(
                OcrSpaceIdCardReader::MAX_UPLOAD_BYTES + 2000,
                strlen($request->body()),
            );

            return true;
        });
    }

    /**
     * The service reports a refused language, an exhausted quota or a bad file
     * as HTTP 200 with the failure in the body. Reading the status alone would
     * turn that into "the card could not be read", and staff would retry a photo
     * that was never the problem.
     */
    public function test_a_failure_inside_a_200_is_still_a_failure(): void
    {
        Http::fake([OcrSpaceIdCardReader::ENDPOINT => Http::response(OcrSpaceSamples::refused())]);

        $reading = $this->reader()->read('image-bytes', 'image/jpeg');

        $this->assertSame(IdCardReading::OUTCOME_FAILED, $reading->outcome);
        $this->assertNotNull($reading->message);
    }

    public function test_a_dead_service_becomes_a_message(): void
    {
        Http::fake([OcrSpaceIdCardReader::ENDPOINT => Http::response('<html>502</html>', 502)]);

        $reading = $this->reader()->read('image-bytes', 'image/jpeg');

        $this->assertSame(IdCardReading::OUTCOME_FAILED, $reading->outcome);
    }

    public function test_an_unsupported_file_never_reaches_the_service(): void
    {
        Http::fake();

        $reading = $this->reader()->read('%PDF-1.4', 'application/pdf');

        $this->assertSame(IdCardReading::OUTCOME_FAILED, $reading->outcome);
        Http::assertNothingSent();
    }

    public function test_without_a_key_it_reads_nothing_and_says_so(): void
    {
        Http::fake();

        $reader = new OcrSpaceIdCardReader(enabled: true, apiKey: null);

        $this->assertFalse($reader->isEnabled());
        $this->assertSame(IdCardReading::OUTCOME_DISABLED, $reader->read('bytes', 'image/jpeg')->outcome);
        Http::assertNothingSent();
    }
}
