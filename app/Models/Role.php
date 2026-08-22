<?php

namespace App\Models;

use App\Support\Database\SchemaQualifier;
use App\Support\Models\UsesSchemaQualifiedTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property string|null $description
 */
class Role extends Model
{
    use UsesSchemaQualifiedTable;

    protected $fillable = [
        'slug',
        'name',
        'description',
    ];

    /**
     * @return BelongsToMany<Permission, $this>
     */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, SchemaQualifier::table('permission_role'));
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, SchemaQualifier::table('role_user'));
    }
}
