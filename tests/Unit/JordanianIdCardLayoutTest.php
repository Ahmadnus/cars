<?php

namespace Tests\Unit;

use App\Services\IdCards\IdCardReading;
use App\Services\IdCards\JordanianIdCardLayout;
use Tests\Support\OcrSpaceSamples;
use Tests\TestCase;

/**
 * Reading fields off OCR'd words.
 *
 * The free reader returns text and coordinates; everything that decides which
 * word is the applicant's national number happens here, so this is where the
 * feature is either safe or dangerous. The first test is the one that matters:
 * a Jordanian card prints two national numbers and two names, and taking the
 * wrong one of each would file the applicant under their mother's identity with
 * nobody having typed a thing.
 */
class JordanianIdCardLayoutTest extends TestCase
{
    /** @param list<array{0: string, 1: int, 2: int, 3: int}> $lines */
    protected function read(array $lines): IdCardReading
    {
        return IdCardReading::fromModel(JordanianIdCardLayout::fromChunks(
            array_map(static fn (array $line) => [
                'text' => $line[0],
                'left' => $line[1],
                'top' => $line[2],
                'width' => $line[3],
                'height' => 46,
            ], $lines),
        ));
    }

    public function test_it_reads_a_card_the_service_read_cleanly(): void
    {
        $reading = $this->read(OcrSpaceSamples::jordanianCard());

        $this->assertSame('رامي سامر محمود الحديد', $reading->fullName);
        $this->assertSame('9981234567', $reading->nationalId);
        $this->assertSame('1998-04-11', $reading->birthDate);
        $this->assertSame('male', $reading->gender);
        $this->assertSame('عمان', $reading->city);
        $this->assertSame('high', $reading->confidence);
    }

    /** The two traps on the card, stated as what must never come out of it. */
    public function test_it_never_takes_the_mothers_identity(): void
    {
        $reading = $this->read(OcrSpaceSamples::jordanianCard());

        $this->assertNotSame('9752223334', $reading->nationalId, "the mother's national number");
        $this->assertNotSame('فاطمة علي أحمد', $reading->fullName, "the mother's name");
    }

    /** Of the three dates on a card, only one is a birth date. */
    public function test_it_takes_the_birth_date_and_not_the_issue_or_expiry(): void
    {
        $reading = $this->read(OcrSpaceSamples::jordanianCard());

        $this->assertSame('1998-04-11', $reading->birthDate);
    }

    /**
     * A label and its value merged into one chunk, which readers do with short
     * labels. The value has to be recovered from inside the label's own text.
     */
    public function test_it_reads_a_value_merged_into_its_label(): void
    {
        $reading = $this->read([
            ['الاسم رامي سامر محمود الحديد', 32, 358, 700],
            ['الرقم الوطني 9981234567', 108, 282, 700],
        ]);

        $this->assertSame('رامي سامر محمود الحديد', $reading->fullName);
        $this->assertSame('9981234567', $reading->nationalId);
    }

    /**
     * Labels lost to glare: the number is still taken, because only one
     * ten-digit number is left to take.
     */
    public function test_it_falls_back_to_the_one_number_on_the_card(): void
    {
        $reading = $this->read([
            ['المملكة الأردنية الهاشمية', 148, 40, 550],
            ['9981234567', 108, 282, 237],
            ['1998/04/11', 148, 593, 217],
            ['2034/02/19', 126, 910, 224],
        ]);

        $this->assertSame('9981234567', $reading->nationalId);
        $this->assertSame('1998-04-11', $reading->birthDate);

        // No label, no name: a line of Arabic is not evidence of whose name it is.
        $this->assertNull($reading->fullName);
        $this->assertSame('low', $reading->confidence);
    }

    /**
     * Two unlabelled numbers, and no way to tell the applicant's from their
     * mother's — so neither is used. This is the case the feature must get wrong
     * in the safe direction.
     */
    public function test_it_refuses_to_choose_between_two_unlabelled_numbers(): void
    {
        $reading = $this->read([
            ['9981234567', 108, 282, 237],
            ['9752223334', 25, 517, 235],
            ['1998/04/11', 148, 593, 217],
        ]);

        $this->assertNull($reading->nationalId);
        $this->assertSame('1998-04-11', $reading->birthDate);
    }

    /** A photo of something else reads as nothing, and says so. */
    public function test_a_photo_that_is_not_a_card_reads_as_nothing(): void
    {
        $reading = $this->read([
            ['فاتورة كهرباء', 40, 40, 300],
            ['المبلغ 24 دينار', 40, 120, 300],
        ]);

        $this->assertFalse($reading->succeeded());
        $this->assertSame([], $reading->fields());
    }

    /**
     * Without coordinates the reader is deliberately poorer: it takes what needs
     * no pairing — a date, a stated gender — and gives up on both the name and
     * the number, because the card carries two of each and line order is what
     * picks the mother's.
     */
    public function test_plain_text_gives_up_the_name_rather_than_guess(): void
    {
        $text = implode("\n", [
            'الرقم الوطني',
            '9981234567',
            'الاسم',
            'رامي سامر محمود الحديد',
            'اسم الأم',
            'فاطمة علي أحمد',
            'الرقم الوطني للأم',
            '9752223334',
            'تاريخ الميلاد',
            '1998/04/11',
            'الجنس',
            'ذكر',
        ]);

        $reading = IdCardReading::fromModel(JordanianIdCardLayout::fromText($text));

        $this->assertNull($reading->fullName);
        $this->assertSame('1998-04-11', $reading->birthDate);
        $this->assertSame('male', $reading->gender);

        /*
         | And the number goes too. Without coordinates there is nothing tying
         | either number to its label, so the card shows two ten-digit numbers and
         | no way to tell them apart — which is exactly when this must give up.
         | The fields that survive are the ones no pairing was needed for.
         */
        $this->assertNull($reading->nationalId);
    }

    /**
     * A card prints the name twice — Arabic and Latin transliteration — and which
     * one the reader puts nearest the label is chance. The Arabic is the spelling
     * the centre files and reads out, so it wins wherever it sits.
     */
    public function test_it_prefers_the_arabic_name_over_the_latin_one(): void
    {
        $reading = $this->read([
            ['الاسم', 593, 358, 104],
            ['RAMI SAMER MAHMOUD ALHADID', 32, 358, 446],
            ['رامي سامر محمود الحديد', 32, 410, 446],
        ]);

        $this->assertSame('رامي سامر محمود الحديد', $reading->fullName);
    }

    /** Both spellings in one chunk: the Latin half is dropped, not stored. */
    public function test_it_keeps_only_the_arabic_half_of_a_mixed_name(): void
    {
        $reading = $this->read([
            ['الاسم', 593, 358, 104],
            ['رامي سامر محمود الحديد RAMI SAMER MAHMOUD', 32, 358, 700],
            ['مكان الولاده', 488, 593, 209],
            ['عمان Amman', 275, 593, 183],
        ]);

        $this->assertSame('رامي سامر محمود الحديد', $reading->fullName);
        $this->assertSame('عمان', $reading->city);
    }

    /** A card with no Arabic at all still yields its name rather than nothing. */
    public function test_a_latin_only_card_still_gives_a_name(): void
    {
        $reading = $this->read([
            ['الاسم', 593, 358, 104],
            ['RAMI SAMER MAHMOUD ALHADID', 32, 358, 446],
        ]);

        $this->assertSame('RAMI SAMER MAHMOUD ALHADID', $reading->fullName);
    }

    /** Arabic-Indic numerals, which is how a card may print the number. */
    public function test_it_reads_arabic_numerals(): void
    {
        $reading = $this->read([
            ['الرقم الوطني', 474, 282, 224],
            ['٩٩٨١٢٣٤٥٦٧', 108, 282, 237],
            ['تاريخ الميلاد', 571, 517, 126],
            ['١٩٩٨/٠٤/١١', 148, 593, 217],
        ]);

        $this->assertSame('9981234567', $reading->nationalId);
        $this->assertSame('1998-04-11', $reading->birthDate);
    }

    /** A female card, since the gender words are two different fallbacks. */
    public function test_it_reads_a_female_card(): void
    {
        $reading = $this->read([
            ['الجنس', 582, 746, 115],
            ['أنثى', 409, 759, 61],
        ]);

        $this->assertSame('female', $reading->gender);
    }
}
