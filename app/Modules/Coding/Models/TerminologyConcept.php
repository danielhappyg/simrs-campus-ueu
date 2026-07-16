<?php

namespace App\Modules\Coding\Models;

use App\Support\Models\HasPublicUlid;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $public_id
 * @property int $terminology_release_id
 * @property string $code
 * @property string $display
 * @property string $normalized_code
 * @property string $normalized_display
 * @property string $search_tokens
 * @property bool $active
 */
class TerminologyConcept extends Model
{
    use HasPublicUlid;

    protected $fillable = [
        'terminology_release_id',
        'code',
        'display',
        'normalized_code',
        'normalized_display',
        'search_tokens',
        'active',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $concept): void {
            if (! TerminologyRelease::query()->whereKey($concept->terminology_release_id)->exists()
                || blank($concept->code)
                || blank($concept->display)
                || blank($concept->normalized_code)
                || blank($concept->normalized_display)) {
                throw new DomainException('A terminology concept must belong to a release and preserve code and display search fields.');
            }
        });

        static::updating(function (): never {
            throw new DomainException('Terminology concepts are immutable.');
        });

        static::deleting(function (): never {
            throw new DomainException('Terminology concepts are append-only.');
        });
    }

    /** @return BelongsTo<TerminologyRelease, $this> */
    public function release(): BelongsTo
    {
        return $this->belongsTo(TerminologyRelease::class, 'terminology_release_id');
    }

    /** @return HasMany<TerminologyAlias, $this> */
    public function aliases(): HasMany
    {
        return $this->hasMany(TerminologyAlias::class);
    }

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
