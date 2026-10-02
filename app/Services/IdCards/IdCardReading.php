<?php

namespace App\Services\IdCards;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * What a reader made of one ID photo.
 *
 * Everything the model returns passes through here before anyone sees it, and
 * the rule the class enforces is the same for every field: a value is kept only
 * when it is the shape the field can actually be, otherwise it is dropped and
 * said to be unreadable. A half-read national number is worse than an empty box
 * — the empty box gets typed in, the half-read one gets saved, because it looks
 * like someone already checked it.
 *
 * Immutable, and serialisable both ways: the same object is returned to the app
 * over the API and stored on the request row so the card is read once.
 */
final class IdCardReading
{
    /** Nothing was read, because no reader is configured. */
    public const OUTCOME_DISABLED = 'disabled';

    /** The reader ran and returned at least one field. */
    public const OUTCOME_READ = 'read';

    /** The reader ran, the image was not a readable ID. */
    public const OUTCOME_EMPTY = 'empty';

    /** The reader could not run — provider error, timeout, unsupported file. */
    public const OUTCOME_FAILED = 'failed';

    /** The fields a reading can fill, in the order a form asks for them. */
    public const FIELDS = ['full_name', 'national_id', 'birth_date', 'gender', 'city', 'address'];

    private function __construct(
        public readonly string $outcome,
        public readonly ?string $fullName = null,
        public readonly ?string $nationalId = null,
        public readonly ?string $birthDate = null,
        public readonly ?string $gender = null,
        public readonly ?string $city = null,
        public readonly ?string $address = null,
        public readonly ?string $documentType = null,
        public readonly ?string $confidence = null,
        public readonly ?string $message = null,
        /** @var list<string> Fields the reader saw but could not read cleanly. */
        public readonly array $unclear = [],
        public readonly ?Carbon $readAt = null,
    ) {
    }

    public static function disabled(): self
    {
        return new self(
            outcome: self::OUTCOME_DISABLED,
            message: 'قراءة الهوية غير مفعّلة على هذا الخادم.',
        );
    }

    public static function failed(string $message): self
    {
        return new self(outcome: self::OUTCOME_FAILED, message: $message);
    }

    /**
     * Build a reading from what the model returned.
     *
     * Each field is normalised independently, so one unreadable line on a
     * creased card costs that field and not the whole reading.
     *
     * @param  array<string, mixed>  $fields
     */
    public static function fromModel(array $fields): self
    {
        $type = self::text($fields['document_type'] ?? null, 40);
        $unclear = [];

        $name = self::name($fields['full_name'] ?? null);
        $nationalId = self::nationalId($fields['national_id'] ?? null, $unclear);
        $birthDate = self::birthDate($fields['birth_date'] ?? null, $unclear);
        $gender = self::gender($fields['gender'] ?? null);
        $city = self::text($fields['city'] ?? null, 120);
        $address = self::text($fields['address'] ?? null, 255);

        $read = [$name, $nationalId, $birthDate, $gender, $city, $address];
        $anything = array_filter($read, static fn (?string $value) => $value !== null) !== [];

        return new self(
            outcome: $anything ? self::OUTCOME_READ : self::OUTCOME_EMPTY,
            fullName: $name,
            nationalId: $nationalId,
            birthDate: $birthDate,
            gender: $gender,
            city: $city,
            address: $address,
            documentType: $type,
            confidence: self::confidence($fields['confidence'] ?? null),
            message: $anything
                ? null
                : ($type === 'not_an_identity_document'
                    ? 'الصورة لا تبدو صورة هوية. أرفق صورة واضحة لوجه الهوية.'
                    : 'لم نتمكّن من قراءة بيانات الهوية من الصورة.'),
            unclear: array_values(array_unique($unclear)),
            readAt: Carbon::now(),
        );
    }

    /**
     * Rebuild a reading stored on a record, so the card is read once and not
     * once per page view.
     *
     * @param  array<string, mixed>  $stored
     */
    public static function fromArray(array $stored): self
    {
        $readAt = isset($stored['read_at']) ? Carbon::parse((string) $stored['read_at']) : null;

        /** @var list<string> $unclear */
        $unclear = array_values(array_filter(
            (array) ($stored['unclear'] ?? []),
            static fn ($value) => is_string($value),
        ));

        return new self(
            outcome: (string) ($stored['outcome'] ?? self::OUTCOME_FAILED),
            fullName: self::stringOrNull($stored['full_name'] ?? null),
            nationalId: self::stringOrNull($stored['national_id'] ?? null),
            birthDate: self::stringOrNull($stored['birth_date'] ?? null),
            gender: self::stringOrNull($stored['gender'] ?? null),
            city: self::stringOrNull($stored['city'] ?? null),
            address: self::stringOrNull($stored['address'] ?? null),
            documentType: self::stringOrNull($stored['document_type'] ?? null),
            confidence: self::stringOrNull($stored['confidence'] ?? null),
            message: self::stringOrNull($stored['message'] ?? null),
            unclear: $unclear,
            readAt: $readAt,
        );
    }

    public function succeeded(): bool
    {
        return $this->outcome === self::OUTCOME_READ;
    }

    /**
     * The fields worth offering, keyed as the forms name them.
     *
     * @return array<string, string>
     */
    public function fields(): array
    {
        return array_filter([
            'full_name' => $this->fullName,
            'national_id' => $this->nationalId,
            'birth_date' => $this->birthDate,
            'gender' => $this->gender,
            'city' => $this->city,
            'address' => $this->address,
        ], static fn (?string $value) => $value !== null && $value !== '');
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'outcome' => $this->outcome,
            'full_name' => $this->fullName,
            'national_id' => $this->nationalId,
            'birth_date' => $this->birthDate,
            'gender' => $this->gender,
            'city' => $this->city,
            'address' => $this->address,
            'document_type' => $this->documentType,
            'confidence' => $this->confidence,
            'message' => $this->message,
            'unclear' => $this->unclear,
            'read_at' => $this->readAt?->toIso8601String(),
        ];
    }

    // ------------------------------------------------------------ normalising

    /**
     * A name as printed, tidied rather than rewritten.
     *
     * Only whitespace and the decorative characters a camera picks up are
     * touched: an applicant's name is theirs to spell, and the office compares
     * it against the card by eye anyway.
     */
    private static function name(mixed $value): ?string
    {
        $text = self::text($value, 150);

        if ($text === null) {
            return null;
        }

        // Tatweel is typography, not spelling — it stretches a name on a printed
        // card and would otherwise be stored as part of it.
        $text = trim(str_replace("\u{0640}", '', $text));

        return Str::length($text) < 3 ? null : $text;
    }

    /**
     * The national number, kept only at full length.
     *
     * Dropping a short read is the point: ten digits is what the center enters
     * into the licence paperwork, and nine digits that look checked are what
     * sends an applicant back to the desk a month later.
     */
    private static function nationalId(mixed $value, array &$unclear): ?string
    {
        $digits = self::digits($value);

        if ($digits === null) {
            return null;
        }

        $expected = (int) config('id_reader.national_id_digits', 10);

        if ($expected > 0 && strlen($digits) !== $expected) {
            $unclear[] = 'national_id';

            return null;
        }

        return $digits;
    }

    /**
     * A birth date the forms will accept.
     *
     * Both printed orders are read — `1998-04-11` and `11/04/1998` — and
     * anything outside a plausible human lifetime is treated as a misread
     * rather than stored and corrected later.
     */
    private static function birthDate(mixed $value, array &$unclear): ?string
    {
        $text = self::text($value, 40);

        if ($text === null) {
            return null;
        }

        $text = self::westernDigits($text);

        $date = null;

        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'Y/m/d'] as $format) {
            $parsed = Carbon::canBeCreatedFromFormat($text, $format)
                ? Carbon::createFromFormat($format, $text)
                : null;

            if ($parsed) {
                $date = $parsed->startOfDay();
                break;
            }
        }

        if (! $date) {
            $unclear[] = 'birth_date';

            return null;
        }

        // A learner driver is realistically between 15 and 100; outside that the
        // reader has picked up an issue or expiry date instead.
        $age = $date->diffInYears(Carbon::now());

        if ($date->isFuture() || $age < 15 || $age > 100) {
            $unclear[] = 'birth_date';

            return null;
        }

        return $date->toDateString();
    }

    private static function gender(mixed $value): ?string
    {
        $text = self::text($value, 20);

        return in_array($text, ['male', 'female'], true) ? $text : null;
    }

    private static function confidence(mixed $value): ?string
    {
        $text = self::text($value, 10);

        return in_array($text, ['high', 'medium', 'low'], true) ? $text : null;
    }

    /** Digits only, with Arabic-Indic numerals promoted first. */
    private static function digits(mixed $value): ?string
    {
        $text = self::text($value, 40);

        if ($text === null) {
            return null;
        }

        $digits = preg_replace('/\D+/u', '', self::westernDigits($text)) ?? '';

        return $digits === '' ? null : $digits;
    }

    /**
     * Arabic-Indic and Persian numerals as ASCII.
     *
     * Jordanian cards print the number in both, and whichever the model read it
     * from has to end up as something the database and the licence paperwork can
     * compare.
     */
    private static function westernDigits(string $text): string
    {
        return strtr($text, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]);
    }

    /** A trimmed string within a column's width, or null for anything blank. */
    private static function text(mixed $value, int $max): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $text = trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '');

        if ($text === '' || in_array(mb_strtolower($text), ['null', 'none', 'n/a', 'غير واضح'], true)) {
            return null;
        }

        return Str::limit($text, $max, '');
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
