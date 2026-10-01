<?php

namespace App\Enums;

/**
 * The licence categories the centre trains for.
 *
 * This list used to be written out by hand in five places — the public
 * registration API and the trainee, trainer, vehicle and package forms — and
 * they had already drifted apart: `heavy_truck` existed in the API, so an
 * applicant could choose it, but no staff form offered it, so nobody could
 * read it back or set it. Adding a category meant remembering all five.
 *
 * The column is a plain string in every table that holds one, so adding a case
 * here needs no migration.
 */
enum LicenseType: string
{
    case Private = 'private';

    case Motorcycle = 'motorcycle';

    /** Taught as one course: the trainee sits both tests. */
    case PrivateMotorcycle = 'private_motorcycle';

    case LightTruck = 'light_truck';

    case HeavyTruck = 'heavy_truck';

    case Public = 'public';

    public function label(): string
    {
        return match ($this) {
            self::Private => 'خصوصي',
            self::Motorcycle => 'دراجة نارية',
            self::PrivateMotorcycle => 'خصوصي ودراجة',
            self::LightTruck => 'شحن خفيف',
            self::HeavyTruck => 'شحن ثقيل',
            self::Public => 'عمومي',
        };
    }

    /** `['private' => 'خصوصي', ...]`, for a select's options. */
    public static function options(): array
    {
        return array_column(
            array_map(
                fn (self $type) => ['value' => $type->value, 'label' => $type->label()],
                self::cases(),
            ),
            'label',
            'value',
        );
    }

    /** `[['value' => ..., 'label' => ...], ...]`, the shape the apps expect. */
    public static function forApi(): array
    {
        return array_map(
            fn (self $type) => ['value' => $type->value, 'label' => $type->label()],
            self::cases(),
        );
    }

    /**
     * The label for a stored value, falling back to the value itself.
     *
     * Rows written before a category was renamed — or by an import — must still
     * render as something, so an unknown value is shown rather than swallowed.
     */
    public static function labelFor(?string $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return self::tryFrom($value)?->label() ?? $value;
    }
}
