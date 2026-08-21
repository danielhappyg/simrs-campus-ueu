<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Support\Authorization\Capability;
use App\Support\Authorization\RoleCapabilityMatrix;
use Illuminate\Database\Seeder;

class RbacSeeder extends Seeder
{
    public function run(): void
    {
        $permissionIds = [];

        foreach (Capability::all() as $name) {
            $permission = Permission::query()->updateOrCreate(
                ['name' => $name],
                ['description' => $name],
            );
            $permissionIds[$name] = $permission->id;
        }

        foreach (RoleCapabilityMatrix::roles() as $slug => $meta) {
            $role = Role::query()->updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => $meta['name'],
                    'description' => $meta['description'],
                ],
            );

            $ids = collect(RoleCapabilityMatrix::capabilitiesFor($slug))
                ->map(fn (string $capability): int => $permissionIds[$capability])
                ->all();

            $role->permissions()->sync($ids);
        }
    }
}
