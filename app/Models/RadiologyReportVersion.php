<?php

namespace App\Models;

use App\Support\Models\HasPublicUlid;
use App\Support\Models\UsesSchemaQualifiedTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $public_id
 * @property int $radiology_order_id
 * @property int $author_user_id
 * @property int|null $base_verified_version_id
 * @property int $version
 * @property string $state
 * @property string|null $findings
 * @property string|null $impression
 * @property string|null $recommendation
 * @property string|null $amendment_reason
 * @property string|null $amended_statement
 * @property string|null $base_verified_digest
 * @property string|null $prior_amendment_digest
 * @property string $content_digest
 * @property Carbon|null $verified_at
 * @property Carbon $created_at
 */
class RadiologyReportVersion extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    public $timestamps = false;

    public const DRAFT = 'DRAFT';

    public const VERIFIED = 'VERIFIED';

    public const AMENDED_VERIFIED = 'AMENDED_VERIFIED';

    protected $fillable = ['radiology_order_id', 'author_user_id', 'base_verified_version_id', 'version', 'state', 'findings', 'impression', 'recommendation', 'amendment_reason', 'amended_statement', 'base_verified_digest', 'prior_amendment_digest', 'content_digest', 'verified_at', 'created_at'];

    /** @return HasOne<RadiologyReportAcknowledgement, $this> */
    public function acknowledgement(): HasOne
    {
        return $this->hasOne(RadiologyReportAcknowledgement::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    protected function casts(): array
    {
        return ['version' => 'integer', 'verified_at' => 'datetime', 'created_at' => 'datetime'];
    }
}
