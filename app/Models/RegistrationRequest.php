<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A join request submitted from the public app.
 *
 * Deliberately not a trainee: nothing reaches the training records until staff
 * approve it, so a stranger filling the form cannot create a file, consume a
 * trainee number, or show up in any report.
 */
class RegistrationRequest extends Model
{
    use HasUuid;

    public const STATUS_PENDING = 'pending';

    public const STATUS_REVIEWING = 'reviewing';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_WITHDRAWN = 'withdrawn';

    /** Statuses a member of staff can still act on. */
    public const OPEN_STATUSES = [self::STATUS_PENDING, self::STATUS_REVIEWING];

    protected $fillable = [
        'branch_id', 'full_name', 'phone', 'secondary_phone', 'national_id',
        'birth_date', 'gender', 'city', 'address', 'license_type', 'notes',
        'phone_verified_at', 'status', 'decision_reason', 'reviewed_by',
        'reviewed_at', 'trainee_id', 'ip_address', 'reference',
    ];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'phone_verified_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $request) {
            $request->reference ??= static::generateReference();
        });
    }

    /**
     * A short reference the applicant can quote on the phone.
     *
     * Random rather than sequential: a sequential number would let anyone
     * holding one reference guess others and read their status.
     */
    public static function generateReference(): string
    {
        do {
            $reference = 'RQ-'.now()->format('y').'-'.Str::upper(Str::random(6));
        } while (static::where('reference', $reference)->exists());

        return $reference;
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function trainee(): BelongsTo
    {
        return $this->belongsTo(Trainee::class);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('registration_requests.status', self::OPEN_STATUSES);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    public function isVerified(): bool
    {
        return $this->phone_verified_at !== null;
    }

    /** @return array<string, string> */
    public static function statuses(): array
    {
        return [
            self::STATUS_PENDING => 'جديد',
            self::STATUS_REVIEWING => 'قيد المراجعة',
            self::STATUS_APPROVED => 'مقبول',
            self::STATUS_REJECTED => 'مرفوض',
            self::STATUS_WITHDRAWN => 'مسحوب',
        ];
    }

    public function statusLabel(): string
    {
        return static::statuses()[$this->status] ?? $this->status;
    }
}
