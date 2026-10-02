<?php

namespace App\Services\IdCards;

/**
 * Turns OCR'd words on a Jordanian ID into fields.
 *
 * An OCR service hands back text, not answers, and the text arrives in an order
 * that cannot be trusted: on a real card the reader returned two labels and then
 * their two values, so "the value is the next line" picks the mother's national
 * number as the applicant's. What can be trusted is where the words sit. The card
 * is a two-column form in Arabic — label on the right, value to its left on the
 * same row — so every field here is found by looking left of its own label, and
 * only accepting something shaped like the field it is looking for.
 *
 * The shape test is what makes it safe. A date is only taken for the birth date
 * if it parses as a date; a national number only if it is ten digits. When the
 * row next to a label holds nothing of the right shape, the row below is tried,
 * and when that fails too the field is left empty — which is the whole policy of
 * this feature: an empty box gets typed into, a wrong one gets saved.
 */
final class JordanianIdCardLayout
{
    /**
     * Read the fields off positioned words.
     *
     * @param  list<array{text: string, left: int, top: int, width: int, height: int}>  $chunks
     * @return array<string, string>
     */
    public static function fromChunks(array $chunks): array
    {
        $chunks = array_values(array_filter($chunks, fn (array $c) => trim($c['text']) !== ''));

        if ($chunks === []) {
            return self::nothing();
        }

        // Found first and then excluded from the applicant's own number: a
        // Jordanian card prints the mother's national number too, and the two are
        // indistinguishable by shape alone.
        $mothersNumber = self::valueFor($chunks, ['الرقم الوطني للام'], [], 'nationalId');

        $nationalId = self::valueFor($chunks, ['الرقم الوطني'], ['للام', 'الام'], 'nationalId');
        $name = self::valueFor($chunks, ['الاسم'], ['الام', 'الاب'], 'name');
        $birthDate = self::valueFor($chunks, ['تاريخ الميلاد', 'الميلاد'], ['الاصدار', 'الانتهاء'], 'date');
        $city = self::valueFor($chunks, ['مكان الولاده', 'محل الولاده', 'مكان الميلاد'], [], 'place');
        $gender = self::valueFor($chunks, ['الجنس'], [], 'gender');

        $labelled = array_filter([$nationalId, $name, $birthDate, $city, $gender]) !== [];

        /*
         | Fallbacks, for a card whose labels the reader lost — glare across the
         | top of a laminated card does exactly that. Each one refuses to guess
         | between two candidates: the number is taken only when one unassigned
         | ten-digit number is left on the card, and the date only when one
         | reading is a plausible birth date.
         */
        $nationalId ??= self::loneNationalNumber($chunks, $mothersNumber);
        $birthDate ??= self::earliestPlausibleDate($chunks);
        $gender ??= self::genderAnywhere($chunks);

        $text = implode(' ', array_column($chunks, 'text'));

        return [
            'document_type' => self::documentType($text, $labelled, $nationalId, $birthDate),
            'full_name' => $name ?? '',
            'national_id' => $nationalId ?? '',
            'birth_date' => $birthDate ?? '',
            'gender' => $gender ?? '',
            'city' => $city ?? '',
            // The front of a Jordanian card carries no address.
            'address' => '',
            'confidence' => self::confidence($labelled, $name, $nationalId),
        ];
    }

    /**
     * The same reading from plain text, when no coordinates came back.
     *
     * Deliberately poorer: without geometry a label can only be paired with what
     * follows it, which is the pairing the card breaks. So this takes the fields
     * that do not need pairing — a lone national number, one plausible birth
     * date, a stated gender — and leaves the name alone rather than risk filing
     * the applicant under their mother's name.
     *
     * @return array<string, string>
     */
    public static function fromText(string $text): array
    {
        $chunks = [];
        $top = 0;

        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = trim($line);

            if ($line !== '') {
                // One row each, far apart: no two lines share a row, so the
                // geometric reader finds no pairs and only the fallbacks fire.
                $chunks[] = ['text' => $line, 'left' => 0, 'top' => $top += 1000, 'width' => 100, 'height' => 20];
            }
        }

        return self::fromChunks($chunks);
    }

    // ------------------------------------------------------------- the search

    /**
     * The value belonging to a label.
     *
     * Three places are tried, in the order they are worth trusting: the rest of
     * the label's own chunk (readers often merge a short label with its value),
     * then the same row to its left, then the row below. The first candidate of
     * the right shape wins.
     *
     * @param  list<array{text: string, left: int, top: int, width: int, height: int}>  $chunks
     * @param  list<string>  $labels  Matched after canonicalising, so hamza and
     *                                diacritics the reader dropped still match.
     * @param  list<string>  $unless  Words that make a label the wrong one —
     *                                "الرقم الوطني للأم" is not the applicant's.
     */
    private static function valueFor(array $chunks, array $labels, array $unless, string $shape): ?string
    {
        foreach ($chunks as $index => $label) {
            if (! self::isLabel($label['text'], $labels, $unless)) {
                continue;
            }

            if ($value = self::shaped(self::afterLabel($label['text'], $labels), $shape)) {
                return $value;
            }

            $tolerance = max(12, (int) round($label['height'] * 0.6));

            // To the left on the same row: the card is a right-to-left form, so
            // that is where a value sits.
            $sameRow = self::candidates(
                $chunks,
                $index,
                fn (array $c) => abs(self::centreY($c) - self::centreY($label)) <= $tolerance
                    && $c['left'] < $label['left'],
            );

            // Nearest first: on a row carrying two labels and one value, the
            // nearer chunk is the one that belongs to this label.
            usort($sameRow, fn (array $a, array $b) => $b['left'] <=> $a['left']);

            foreach ($sameRow as $candidate) {
                if ($value = self::shaped($candidate['text'], $shape)) {
                    return $value;
                }
            }

            $below = self::candidates(
                $chunks,
                $index,
                fn (array $c) => self::centreY($c) > self::centreY($label)
                    && self::centreY($c) - self::centreY($label) <= $label['height'] * 2.2,
            );

            usort($below, fn (array $a, array $b) => self::centreY($a) <=> self::centreY($b));

            foreach ($below as $candidate) {
                if ($value = self::shaped($candidate['text'], $shape)) {
                    return $value;
                }
            }
        }

        return null;
    }

    /**
     * @param  list<array{text: string, left: int, top: int, width: int, height: int}>  $chunks
     * @return list<array{text: string, left: int, top: int, width: int, height: int}>
     */
    private static function candidates(array $chunks, int $skip, callable $where): array
    {
        $found = [];

        foreach ($chunks as $index => $chunk) {
            if ($index !== $skip && $where($chunk)) {
                $found[] = $chunk;
            }
        }

        return $found;
    }

    private static function centreY(array $chunk): float
    {
        return $chunk['top'] + ($chunk['height'] / 2);
    }

    /** @param list<string> $labels */
    private static function isLabel(string $text, array $labels, array $unless): bool
    {
        $canon = self::canon($text);

        foreach ($unless as $word) {
            if (str_contains($canon, self::canon($word))) {
                return false;
            }
        }

        foreach ($labels as $label) {
            if (str_contains($canon, self::canon($label))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whatever the label chunk holds besides the label itself.
     *
     * @param  list<string>  $labels
     */
    private static function afterLabel(string $text, array $labels): string
    {
        $words = preg_split('/\s+/u', trim($text)) ?: [];
        $canonical = array_map(static fn (string $word) => self::canon($word), $words);

        foreach ($labels as $label) {
            $needle = array_values(array_filter(array_map(
                static fn (string $word) => self::canon($word),
                preg_split('/\s+/u', trim($label)) ?: [],
            )));

            if ($needle === []) {
                continue;
            }

            /*
             | Matched word by word rather than by character offset: dropping
             | diacritics shortens the text, so an offset found in the canonical
             | form would not point at the same place in the original — and it is
             | the original that gets stored.
             */
            for ($at = 0; $at + count($needle) <= count($words); $at++) {
                if (array_slice($canonical, $at, count($needle)) === $needle) {
                    return trim(implode(' ', array_slice($words, $at + count($needle))));
                }
            }
        }

        return '';
    }

    // ------------------------------------------------------------ the shapes

    private static function shaped(string $text, string $shape): ?string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');

        if ($text === '') {
            return null;
        }

        return match ($shape) {
            'nationalId' => self::asNationalNumber($text),
            'date' => self::asDate($text),
            'gender' => self::asGender($text),
            'name' => self::asName($text),
            'place' => self::asPlace($text),
            default => null,
        };
    }

    /** Ten digits and nothing else of substance. */
    private static function asNationalNumber(string $text): ?string
    {
        $digits = preg_replace('/\D+/u', '', self::westernDigits($text)) ?? '';

        return strlen($digits) === 10 ? $digits : null;
    }

    private static function asDate(string $text): ?string
    {
        $text = self::westernDigits($text);

        return preg_match('~\b(\d{4}[/\-.]\d{1,2}[/\-.]\d{1,2}|\d{1,2}[/\-.]\d{1,2}[/\-.]\d{4})\b~', $text, $m) === 1
            ? $m[1]
            : null;
    }

    private static function asGender(string $text): ?string
    {
        $canon = self::canon($text);

        return match (true) {
            str_contains($canon, 'ذكر') => 'male',
            str_contains($canon, 'انثي'), str_contains($canon, 'انثى') => 'female',
            default => null,
        };
    }

    /**
     * Something that can be a person's name.
     *
     * Two words at least — a single word next to "الاسم" is far more often a
     * second label the reader placed badly than it is a name — and no digits,
     * which rules out the numbers that share the card's rows.
     */
    private static function asName(string $text): ?string
    {
        if (preg_match('/\d/u', self::westernDigits($text)) === 1) {
            return null;
        }

        $words = preg_split('/\s+/u', $text) ?: [];

        return count($words) >= 2 && mb_strlen($text) >= 6 ? $text : null;
    }

    /** A place: one or two words, no digits. */
    private static function asPlace(string $text): ?string
    {
        if (preg_match('/\d/u', self::westernDigits($text)) === 1) {
            return null;
        }

        $words = preg_split('/\s+/u', $text) ?: [];

        return count($words) <= 3 && mb_strlen($text) >= 3 ? $text : null;
    }

    // ---------------------------------------------------------- the fallbacks

    /**
     * A national number only when there is no doubt left.
     *
     * With the mother's number already identified and set aside, one remaining
     * ten-digit number on the card is the applicant's. Two, and there is no way
     * to tell which — so neither is used.
     *
     * @param  list<array{text: string, left: int, top: int, width: int, height: int}>  $chunks
     */
    private static function loneNationalNumber(array $chunks, ?string $exclude): ?string
    {
        $found = [];

        foreach ($chunks as $chunk) {
            $number = self::asNationalNumber($chunk['text']);

            if ($number !== null && $number !== $exclude) {
                $found[$number] = true;
            }
        }

        return count($found) === 1 ? (string) array_key_first($found) : null;
    }

    /**
     * The earliest date that could be a birth date.
     *
     * A card carries three dates; issue and expiry are both recent or future, so
     * they fail the age test that `IdCardReading` applies anyway. Taking the
     * earliest plausible one is what distinguishes a birth date from an issue
     * date for a young applicant.
     *
     * @param  list<array{text: string, left: int, top: int, width: int, height: int}>  $chunks
     */
    private static function earliestPlausibleDate(array $chunks): ?string
    {
        $best = null;
        $bestYear = null;

        foreach ($chunks as $chunk) {
            $date = self::asDate($chunk['text']);

            if ($date === null) {
                continue;
            }

            if (preg_match('/(\d{4})/', $date, $m) !== 1) {
                continue;
            }

            $year = (int) $m[1];
            $age = (int) date('Y') - $year;

            if ($age < 15 || $age > 100) {
                continue;
            }

            if ($bestYear === null || $year < $bestYear) {
                $best = $date;
                $bestYear = $year;
            }
        }

        return $best;
    }

    /** @param list<array{text: string, left: int, top: int, width: int, height: int}> $chunks */
    private static function genderAnywhere(array $chunks): ?string
    {
        foreach ($chunks as $chunk) {
            if ($gender = self::asGender($chunk['text'])) {
                return $gender;
            }
        }

        return null;
    }

    // -------------------------------------------------------------- the verdict

    private static function documentType(string $text, bool $labelled, ?string $id, ?string $date): string
    {
        if ($labelled || $id !== null || $date !== null) {
            return 'jordanian_id';
        }

        return self::canon($text) === '' ? 'not_an_identity_document' : 'other_national_id';
    }

    /**
     * How much of this came from the card saying so.
     *
     * `high` only when the two fields that matter were found beside their own
     * labels. A reading assembled from fallbacks is marked `low`, which the queue
     * shows as "صورة غير واضحة" so a reviewer knows to check it against the photo.
     */
    private static function confidence(bool $labelled, ?string $name, ?string $id): string
    {
        return match (true) {
            $name !== null && $id !== null => 'high',
            $labelled => 'medium',
            default => 'low',
        };
    }

    // --------------------------------------------------------- normalisation

    /**
     * A form of the text fit for comparing labels, never for storing.
     *
     * Readers drop hamza and diacritics and confuse the ya and ta forms, so
     * "تاريخ الميلاد" can come back a dozen ways. Removing the diacritics
     * shortens the text, which is why nothing here is used to locate a position
     * in the original — matching is done word by word instead.
     */
    private static function canon(string $text): string
    {
        $text = strtr($text, [
            'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا',
            'ى' => 'ي', 'ئ' => 'ي', 'ؤ' => 'و', 'ة' => 'ه',
        ]);

        // Diacritics and the decorative tatweel, which carry no meaning here.
        $text = preg_replace('/[\x{0610}-\x{061A}\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06ED}\x{0640}]/u', '', $text) ?? $text;

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    private static function westernDigits(string $text): string
    {
        return strtr($text, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]);
    }

    /** @return array<string, string> */
    private static function nothing(): array
    {
        return [
            'document_type' => 'not_an_identity_document',
            'full_name' => '',
            'national_id' => '',
            'birth_date' => '',
            'gender' => '',
            'city' => '',
            'address' => '',
            'confidence' => 'low',
        ];
    }
}
