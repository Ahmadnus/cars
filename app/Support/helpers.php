<?php

use App\Services\SettingsRepository;
use App\Support\BranchContext;

if (! function_exists('settings')) {
    /** Read a database-backed setting, falling back to its shipped default. */
    function settings(?string $key = null, mixed $default = null): mixed
    {
        $repository = app(SettingsRepository::class);

        return $key === null ? $repository : $repository->get($key, $default);
    }
}

if (! function_exists('branch_context')) {
    function branch_context(): BranchContext
    {
        return app(BranchContext::class);
    }
}

if (! function_exists('money')) {
    /**
     * Format an amount for display.
     *
     * Digits stay Latin even in Arabic: finance staff read and cross-check
     * these against bank statements and invoices, which use Latin numerals.
     */
    function money(float|int|string|null $amount, bool $withCurrency = true): string
    {
        $formatted = number_format((float) $amount, 2, '.', ',');

        return $withCurrency
            ? $formatted.' '.settings('center.currency_label', 'د.أ')
            : $formatted;
    }
}

if (! function_exists('percent')) {
    function percent(float|int|null $value, int $decimals = 1): string
    {
        return number_format((float) $value, $decimals, '.', ',').'%';
    }
}

if (! function_exists('arabic_date')) {
    /** Gregorian date with an Arabic month name — what Jordanian centers use. */
    function arabic_date(mixed $date, bool $withTime = false): string
    {
        if (blank($date)) {
            return '—';
        }

        $carbon = $date instanceof DateTimeInterface
            ? Illuminate\Support\Carbon::instance($date)
            : Illuminate\Support\Carbon::parse($date);

        return $carbon->translatedFormat($withTime ? 'j F Y — H:i' : 'j F Y');
    }
}

if (! function_exists('short_time')) {
    function short_time(mixed $time): string
    {
        return $time ? substr((string) $time, 0, 5) : '—';
    }
}
