<?php

namespace App\Models;

use App\Support\Models\HasPublicUlid;
use App\Support\Models\UsesSchemaQualifiedTable;
use Database\Factories\LabDiagnosticResultFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $public_id
 * @property int $lab_service_request_id
 * @property int $entered_by_user_id
 * @property string $status
 * @property string $result_text
 * @property Carbon $issued_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class LabDiagnosticResult extends Model
{
    /** @use HasFactory<LabDiagnosticResultFactory> */
    use HasFactory, HasPublicUlid, UsesSchemaQualifiedTable;

    public const STATUS_PRELIMINARY = 'PRELIMINARY';

    public const STATUS_FINAL = 'FINAL';

    /**
     * @var list<string>
     */
    public const STATUS_VALUES = [
        self::STATUS_PRELIMINARY,
        self::STATUS_FINAL,
    ];

    protected $fillable = [
        'lab_service_request_id',
        'entered_by_user_id',
        'status',
        'result_text',
        'issued_at',
    ];

    protected $attributes = [
        'status' => self::STATUS_FINAL,
    ];

    /**
     * @return BelongsTo<LabServiceRequest, $this>
     */
    public function serviceRequest(): BelongsTo
    {
        return $this->belongsTo(LabServiceRequest::class, 'lab_service_request_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entered_by_user_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
        ];
    }
}
