<?php

namespace App\Support;

use Illuminate\Validation\Rules\Password;

/**
 * Passwords the office hands out.
 *
 * Deliberately simpler than a staff password someone chooses for themselves:
 * these are read out over the phone or sent on WhatsApp, and a trainee types
 * them on a phone keyboard. Letters and digits only, six to eight characters —
 * the center asked for that, and a password nobody can dictate gets written on
 * a sticky note instead, which is worse than a short one.
 *
 * The trade-off is accepted knowingly: what protects these accounts is that they
 * reach one person's own file and nothing else, that the login is rate-limited,
 * and that the office can reset or suspend at any time. Staff accounts that
 * reach money and other people's records keep the full policy.
 */
class IssuedPassword
{
    /** No 0/O or 1/l/I: they are misread on a phone line and mistyped. */
    protected const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';

    public const LENGTH = 8;

    public const MIN_LENGTH = 6;

    /**
     * Validation for a password typed by a member of staff.
     *
     * @return array<int, mixed>
     */
    public static function rules(): array
    {
        return [
            'nullable',
            'string',
            // Typed twice: the office is about to dictate it, and a typo here
            // becomes a trainee who cannot sign in and nobody who can check.
            'confirmed',
            // :ascii matters — bare alpha_num accepts Arabic letters, and a
            // password in Arabic script cannot be dictated over a phone or
            // typed without switching keyboards mid-login.
            'alpha_num:ascii',
            'min:'.self::MIN_LENGTH,
            'max:'.self::LENGTH,
        ];
    }

    /** The same rules, as a sentence for a form hint. */
    public static function hint(): string
    {
        return 'أحرف أو أرقام، من '.self::MIN_LENGTH.' إلى '.self::LENGTH.' خانات.';
    }

    /**
     * A password to hand over.
     *
     * Mixed letters and digits so it is not a guessable word, but both drawn
     * from an alphabet that survives being dictated.
     */
    public static function generate(int $length = self::LENGTH): string
    {
        $length = max(self::MIN_LENGTH, min($length, self::LENGTH));

        // Both kinds guaranteed by construction rather than by retrying: a run
        // of only letters or only digits is well within reach of chance at this
        // length, and a fallback branch would be a second alphabet to keep in
        // step with this one — that is how an 0 or an l gets back in.
        $letters = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz';
        $digits = '23456789';

        $characters = [
            $letters[random_int(0, strlen($letters) - 1)],
            $digits[random_int(0, strlen($digits) - 1)],
        ];

        for ($i = count($characters); $i < $length; $i++) {
            $characters[] = self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        // Shuffled so the digit is not always in the same place.
        return collect($characters)->shuffle()->implode('');
    }

    /** The full policy, for a password someone chooses for themselves. */
    public static function staffRules(): array
    {
        return ['nullable', 'confirmed', Password::defaults()];
    }
}
