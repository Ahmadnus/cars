<?php

namespace Tests\Support;

use App\Services\IdCards\IdCardReader;
use App\Services\IdCards\IdCardReading;

/**
 * A reader that returns what the test asks it to.
 *
 * The provider is the one part of this feature a test must not exercise: a real
 * reading costs money, needs a key, and would make the suite's result depend on
 * how well a photograph scanned. Everything either side of it — the throttles,
 * the permissions, what reaches the trainee's file — is what the tests are for,
 * and this is the seam that lets them get at it.
 */
class FakeIdCardReader implements IdCardReader
{
    /** @var list<string> Mime types it was asked to read, in order. */
    public array $calls = [];

    /** @param array<string, mixed> $fields What the model would have returned. */
    public function __construct(
        protected array $fields = [],
        protected bool $enabled = true,
    ) {
    }

    /** A reader holding a plausible Jordanian card. */
    public static function withCard(array $overrides = []): self
    {
        return new self(array_merge([
            'document_type' => 'jordanian_id',
            'full_name' => 'رامي سامر محمود الحديد',
            'national_id' => '9981234567',
            'birth_date' => '1998-04-11',
            'gender' => 'male',
            'city' => 'عمّان',
            'address' => 'عمّان - تلاع العلي',
            'confidence' => 'high',
        ], $overrides));
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function read(string $bytes, string $mimeType): IdCardReading
    {
        $this->calls[] = $mimeType;

        // Switched off answers the way the real readers do, rather than as an
        // unreadable card: the two are different states and the screens say
        // different things about them.
        return $this->enabled
            ? IdCardReading::fromModel($this->fields)
            : IdCardReading::disabled();
    }
}
