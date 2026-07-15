<?php

namespace App\Modules\Teaching\Models;

use App\Modules\Teaching\Enums\ScenarioStatus;
use App\Support\Models\HasPublicUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $public_id
 * @property string $code
 * @property string $title
 * @property int $version
 * @property ScenarioStatus $status
 * @property array<int, string>|null $learning_outcomes
 * @property array<string, mixed>|null $fixture_spec
 */
class SimulationScenario extends Model
{
    use HasPublicUlid;

    protected $fillable = [
        'code',
        'title',
        'version',
        'status',
        'learning_outcomes',
        'fixture_spec',
        'ruleset_version',
        'published_at',
    ];

    /**
     * @return HasMany<SimulationSession, $this>
     */
    public function sessions(): HasMany
    {
        return $this->hasMany(SimulationSession::class, 'scenario_id');
    }

    protected function casts(): array
    {
        return [
            'status' => ScenarioStatus::class,
            'learning_outcomes' => 'array',
            'fixture_spec' => 'array',
            'published_at' => 'datetime',
        ];
    }
}
