<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Organization extends Model
{
    use HasFactory;
    use HasUuid;
    use SoftDeletes;

    protected $fillable = [
        'name', 'legal_name', 'tax_number', 'phone', 'email',
        'address', 'logo_path', 'currency', 'timezone',
    ];

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }
}
