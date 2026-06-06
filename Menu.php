<?php

namespace App\Plugins\MigrateRrdRetention;

use App\Plugins\Hooks\MenuEntryHook;

class Menu extends MenuEntryHook
{
    public function authorize(\Illuminate\Contracts\Auth\Authenticatable $user, array $settings = []): bool
    {
        return $user->can('admin');
    }

    public function data(array $settings = []): array
    {
        return [];
    }
}
