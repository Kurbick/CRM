<?php

namespace App\Models;

use App\Support\Access\SystemRole;
use Spatie\Permission\Models\Role as SpatieRole;

class Role extends SpatieRole
{
    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function isSystem(): bool
    {
        return (bool) $this->is_system;
    }

    public function isAdministrator(): bool
    {
        return $this->name === SystemRole::Administrator->value;
    }
}
