<?php

namespace App\Models;

use App\Support\Database\SchemaQualifier;
use App\Support\Models\UsesSchemaQualifiedTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int $id
 * @property string $name
 * @property string|null $description
 */
class Permission extends Model
{
    use UsesSchemaQualifiedTable;

    protected $fillable = [
        'name',
        'description',
    ];

    /**
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, SchemaQualifier::table('permission_role'));
    }
}
