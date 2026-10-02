<?php

namespace Tests\Unit;

use App\Services\IdCards\IdCardReading;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * What a reading is willing to believe.
 *
 * This is where the feature is either safe or dangerous, so it is tested on its
 * own: a field that is kept is one a receptionist will see already filled in and
 * is therefore unlikely to re-check against the card. Every case below is a way a
 * card can be misread, and the expected outcome is almost always "drop it" —
 * an empty box gets typed into, a wrong one gets saved.
 *
 * On the framework's TestCase rather than PHPUnit's bare one, and without a
 * database: the length a national number has to be is configuration, so the
 * class reads it from config and the test needs the app booted to answer.
 */
class IdCardReadingTest extends TestCase
{
    /** @param array<string, mixed> $fields */
    protected function reading(array $fields = []): IdCardReading
    {
        return IdCardReading::fromModel(array_merge([
            'document_type' => 'jordanian_id',
            'full_name' => 'رامي سامر محمود الحديد',
            'national_id' => '9981234567',
            'birth_date' => '1998-04-11',
            'gender' => 'male',
            'city' => 'عمّان',
            'address' => '',
            'confidence' => 'high',
        ], $fields));
    }

    public function test_it_keeps_a_card_it_could_read(): void
    {
        $reading = $this->reading();

        $this->assertTrue($reading->succeeded());
        $this->assertSame('رامي سامر محمود الحديد', $reading->fullName);
        $this->assertSame('9981234567', $reading->nationalId);
        $this->assertSame('1998-04-11', $reading->birthDate);
        $this->assertSame('male', $reading->gender);

        // An empty string is how the model says "not on the card"; it must not
        // reach a form as an answer.
        $this->assertNull($reading->address);
        $this->assertArrayNotHasKey('address', $reading->fields());
    }

    /**
     * Arabic-Indic numerals are what the card prints, and what the licence
     * paperwork cannot be filed with.
     */
    public function test_it_promotes_arabic_numerals(): void
    {
        $reading = $this->reading([
            'national_id' => '٩٩٨١٢٣٤٥٦٧',
            'birth_date' => '١٩٩٨-٠٤-١١',
        ]);

        $this->assertSame('9981234567', $reading->nationalId);
        $this->assertSame('1998-04-11', $reading->birthDate);
    }

    /**
     * A national number is ten digits or it is nothing.
     *
     * The case that matters most: nine digits looks like a national number, fits
     * the column, and passes the form's own validation. Keeping it would put a
     * wrong number on a licence application with nobody having typed it.
     */
    #[DataProvider('unusableNationalNumbers')]
    public function test_it_drops_a_national_number_of_the_wrong_length(string $value): void
    {
        $reading = $this->reading(['national_id' => $value]);

        $this->assertNull($reading->nationalId);
        $this->assertContains('national_id', $reading->unclear);
    }

    /** @return array<string, array{string}> */
    public static function unusableNationalNumbers(): array
    {
        return [
            'one digit short' => ['998123456'],
            'one digit long' => ['99812345678'],
            'a serial number' => ['12'],
        ];
    }

    /** Separators and stray spaces are the camera's, not the card's. */
    public function test_it_reads_a_number_printed_with_separators(): void
    {
        $this->assertSame('9981234567', $this->reading(['national_id' => '998 123 4567'])->nationalId);
        $this->assertSame('9981234567', $this->reading(['national_id' => '9981-234-567'])->nationalId);
    }

    /**
     * A card carries three dates and only one of them is a birth date.
     *
     * When the reader picks up an issue or expiry date instead, the result is a
     * date that is either in the future or belongs to a toddler — both are
     * refused rather than stored for someone to notice later.
     */
    #[DataProvider('implausibleBirthDates')]
    public function test_it_drops_an_implausible_birth_date(string $value): void
    {
        $reading = $this->reading(['birth_date' => $value]);

        $this->assertNull($reading->birthDate);
        $this->assertContains('birth_date', $reading->unclear);
    }

    /** @return array<string, array{string}> */
    public static function implausibleBirthDates(): array
    {
        return [
            'an expiry date' => ['2031-04-11'],
            'a child' => ['2021-04-11'],
            'before photography' => ['1890-01-01'],
            'not a date' => ['11 نيسان'],
        ];
    }

    public function test_it_reads_a_date_printed_day_first(): void
    {
        $this->assertSame('1998-04-11', $this->reading(['birth_date' => '11/04/1998'])->birthDate);
    }

    public function test_it_keeps_only_the_two_genders_the_forms_offer(): void
    {
        $this->assertSame('female', $this->reading(['gender' => 'female'])->gender);
        $this->assertNull($this->reading(['gender' => 'أنثى'])->gender);
        $this->assertNull($this->reading(['gender' => ''])->gender);
    }

    /** A name too short to be one is a label the reader mistook for a value. */
    public function test_it_drops_a_name_that_is_not_one(): void
    {
        $this->assertNull($this->reading(['full_name' => 'ا'])->fullName);
        $this->assertNull($this->reading(['full_name' => '   '])->fullName);
    }

    public function test_a_photo_that_is_not_an_id_reads_as_empty_with_a_reason(): void
    {
        $reading = IdCardReading::fromModel([
            'document_type' => 'not_an_identity_document',
            'full_name' => '',
            'national_id' => '',
            'birth_date' => '',
            'gender' => '',
            'city' => '',
            'address' => '',
            'confidence' => 'low',
        ]);

        $this->assertFalse($reading->succeeded());
        $this->assertSame(IdCardReading::OUTCOME_EMPTY, $reading->outcome);
        $this->assertSame([], $reading->fields());
        $this->assertStringContainsString('لا تبدو صورة هوية', (string) $reading->message);
    }

    /** A reading survives being stored on the request and read back. */
    public function test_it_round_trips_through_storage(): void
    {
        $stored = $this->reading()->toArray();
        $restored = IdCardReading::fromArray($stored);

        $this->assertSame($stored, $restored->toArray());
        $this->assertTrue($restored->succeeded());
    }
}
