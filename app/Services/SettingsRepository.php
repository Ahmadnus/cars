<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

/**
 * Reads and writes configuration stored in the database.
 *
 * Lookups fall back from the branch-specific value, to the organization-wide
 * default, to the hard-coded default below — so a fresh branch behaves sensibly
 * before anyone touches the settings screens.
 */
class SettingsRepository
{
    protected const CACHE_KEY = 'settings.all';

    protected const CACHE_TTL = 3600;

    /** Shipped defaults: group.key => [value, type]. */
    public const DEFAULTS = [
        'center.name' => ['مركز تدريب القيادة', 'string'],
        'center.phone' => ['', 'string'],
        'center.address' => ['', 'string'],
        'center.email' => ['', 'string'],
        'center.currency' => ['JOD', 'string'],
        'center.currency_label' => ['د.أ', 'string'],

        'training.default_lesson_duration' => [45, 'int'],
        'training.slot_step_minutes' => [15, 'int'],
        'training.low_balance_threshold' => [2, 'int'],
        'training.allow_negative_balance' => [false, 'bool'],

        'cancellation.min_notice_hours' => [12, 'int'],
        'cancellation.burn_lesson_on_late_cancel' => [true, 'bool'],
        'cancellation.burn_lesson_on_no_show' => [true, 'bool'],

        'payroll.pay_day' => [1, 'int'],
        'payroll.require_reason_on_edit' => [true, 'bool'],

        'notifications.lesson_reminder_hours' => [24, 'int'],
        'notifications.enable_in_app' => [true, 'bool'],
        'notifications.enable_email' => [false, 'bool'],
        'notifications.enable_sms' => [false, 'bool'],
        'notifications.enable_whatsapp' => [false, 'bool'],
        'notifications.enable_push' => [false, 'bool'],
    ];

    /** @return array<string, mixed> group.key => value, for the current branch */
    protected function resolved(?int $branchId): array
    {
        $all = Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            return Setting::query()->get()->groupBy('branch_id')->map(
                fn ($rows) => $rows->mapWithKeys(fn (Setting $s) => ["{$s->group}.{$s->key}" => $s->typedValue()])->all()
            )->all();
        });

        $defaults = collect(self::DEFAULTS)->map(fn ($pair) => $pair[0])->all();
        $organization = $all[''] ?? $all[null] ?? [];
        $branch = $branchId !== null ? ($all[$branchId] ?? []) : [];

        return array_merge($defaults, $organization, $branch);
    }

    public function get(string $key, mixed $default = null, ?int $branchId = null): mixed
    {
        $branchId ??= app(\App\Support\BranchContext::class)->currentId();

        return $this->resolved($branchId)[$key] ?? $default ?? (self::DEFAULTS[$key][0] ?? null);
    }

    public function int(string $key, int $default = 0, ?int $branchId = null): int
    {
        return (int) $this->get($key, $default, $branchId);
    }

    public function bool(string $key, bool $default = false, ?int $branchId = null): bool
    {
        return (bool) $this->get($key, $default, $branchId);
    }

    public function string(string $key, string $default = '', ?int $branchId = null): string
    {
        return (string) $this->get($key, $default, $branchId);
    }

    /** @return array<string, mixed> every key in one group */
    public function group(string $group, ?int $branchId = null): array
    {
        $branchId ??= app(\App\Support\BranchContext::class)->currentId();
        $prefix = $group.'.';

        return collect($this->resolved($branchId))
            ->filter(fn ($value, $key) => str_starts_with($key, $prefix))
            ->mapWithKeys(fn ($value, $key) => [substr($key, strlen($prefix)) => $value])
            ->all();
    }

    public function set(string $key, mixed $value, ?int $branchId = null): void
    {
        [$group, $name] = explode('.', $key, 2);
        $type = self::DEFAULTS[$key][1] ?? $this->inferType($value);

        Setting::updateOrCreate(
            ['branch_id' => $branchId, 'group' => $group, 'key' => $name],
            ['value' => Setting::encode($value, $type), 'type' => $type],
        );

        $this->flush();
    }

    /** @param array<string, mixed> $values group.key => value */
    public function setMany(array $values, ?int $branchId = null): void
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value, $branchId);
        }
    }

    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    protected function inferType(mixed $value): string
    {
        return match (true) {
            is_bool($value) => 'bool',
            is_int($value) => 'int',
            is_float($value) => 'float',
            is_array($value) => 'json',
            default => 'string',
        };
    }
}
