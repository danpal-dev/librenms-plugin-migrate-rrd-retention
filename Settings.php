<?php

namespace App\Plugins\MigrateRrdRetention;

use App\Plugins\Hooks\SettingsHook;

class Settings extends SettingsHook
{
    private const TARGET_RRA  = 'RRA:AVERAGE:0.5:1:114048 RRA:MAX:0.5:1:114048';
    private const CONFIG_PATH = '/opt/librenms/config.php';
    private const BACKUP_DIR  = '/opt/librenms/rrd_backup_pre_migration';
    private const SUDOERS     = '/etc/sudoers.d/librenms-plugin';

    public function authorize(\Illuminate\Contracts\Auth\Authenticatable $user): bool
    {
        return $user->can('admin');
    }

    public function data(array $settings = []): array
    {
        return [
            'target_rra'    => self::TARGET_RRA,
            'config_path'   => self::CONFIG_PATH,
            'backup_dir'    => self::BACKUP_DIR,
            'sudoers_path'  => self::SUDOERS,
            'config_ok'     => $this->configIsCorrect(),
            'backup_exists' => is_dir(self::BACKUP_DIR),
            'sudoers_ok'    => $this->sudoersOk(),
        ];
    }

    private function configIsCorrect(): bool
    {
        if (! file_exists(self::CONFIG_PATH)) {
            return false;
        }
        $escaped = preg_quote(self::TARGET_RRA, '/');

        return (bool) preg_match('/\$config\[\'rrd_rra\'\]\s*=\s*"' . $escaped . '"\s*;/', file_get_contents(self::CONFIG_PATH));
    }

    private function sudoersOk(): bool
    {
        // Si el sudoers está bien, este comando no pedirá contraseña
        $out = (string) shell_exec('sudo -n systemctl status librenms-scheduler.timer 2>&1');

        return ! str_contains($out, 'password is required')
            && ! str_contains($out, 'not allowed');
    }
}
