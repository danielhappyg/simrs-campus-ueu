<?php

namespace App\Models;

use App\Support\Authorization\Capability;
use App\Support\Database\SchemaQualifier;
use App\Support\Models\HasPublicUlid;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $public_id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string $status
 * @property bool $is_system_administrator
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password', 'status', 'is_system_administrator'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasPublicUlid, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    protected $attributes = [
        'status' => 'ACTIVE',
        'is_system_administrator' => false,
    ];

    /**
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }

    public function hasRole(string $slug): bool
    {
        if ($this->relationLoaded('roles')) {
            return $this->roles->contains(fn (Role $role): bool => $role->slug === $slug);
        }

        try {
            return $this->roles()->where('roles.slug', $slug)->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    public function canCapability(string $capability): bool
    {
        if ($this->is_system_administrator) {
            return true;
        }

        return in_array($capability, $this->capabilityList(), true);
    }

    /**
     * @return list<string>
     */
    public function capabilityList(): array
    {
        if ($this->is_system_administrator) {
            return Capability::all();
        }

        try {
            $roleUser = SchemaQualifier::table('role_user');
            $permissionRole = SchemaQualifier::table('permission_role');
            $permissions = SchemaQualifier::table('permissions');

            $rows = DB::select(
                "SELECT DISTINCT p.name AS name
                 FROM {$roleUser} AS ru
                 INNER JOIN {$permissionRole} AS pr ON pr.role_id = ru.role_id
                 INNER JOIN {$permissions} AS p ON p.id = pr.permission_id
                 WHERE ru.user_id = ?",
                [$this->getKey()],
            );

            /** @var list<string> $names */
            $names = array_values(array_unique(array_map(
                static fn (object $row): string => (string) $row->name,
                $rows,
            )));

            return $names;
        } catch (\Throwable $exception) {
            report($exception);
            error_log('[simrs] capabilityList failed for user '.$this->getKey().': '.$exception->getMessage());

            return [];
        }
    }

    /**
     * @return list<string>
     */
    public function roleSlugs(): array
    {
        try {
            $roleUser = SchemaQualifier::table('role_user');
            $roles = SchemaQualifier::table('roles');

            $rows = DB::select(
                "SELECT r.slug AS slug
                 FROM {$roleUser} AS ru
                 INNER JOIN {$roles} AS r ON r.id = ru.role_id
                 WHERE ru.user_id = ?",
                [$this->getKey()],
            );

            /** @var list<string> $slugs */
            $slugs = array_values(array_unique(array_map(
                static fn (object $row): string => (string) $row->slug,
                $rows,
            )));

            return $slugs;
        } catch (\Throwable $exception) {
            report($exception);
            error_log('[simrs] roleSlugs failed for user '.$this->getKey().': '.$exception->getMessage());

            return [];
        }
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_system_administrator' => 'boolean',
            'last_login_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }
}
