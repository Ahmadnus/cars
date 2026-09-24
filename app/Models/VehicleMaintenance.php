<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class VehicleMaintenance extends Model
{
    use BelongsToBranch;
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'vehicle_id', 'branch_id', 'type', 'title', 'service_date', 'cost',
        'provider', 'odometer_km', 'next_service_on', 'expense_id', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'service_date' => 'date',
            'next_service_on' => 'date',
            'cost' => 'decimal:2',
            'odometer_km' => 'integer',
        ];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /** The ledger entry this maintenance was posted to, once booked. */
    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }
}
