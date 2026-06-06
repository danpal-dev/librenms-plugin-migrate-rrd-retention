<?php

namespace App\Plugins\MigrateRrdRetention;

use App\Plugins\Hooks\PageHook;

class Page extends PageHook
{
    private const SCRIPT_PATH  = __DIR__ . '/migrate-rrd-retention.py';
    private const LOG_PATH     = '/opt/librenms/logs/migrate-rrd-retention.log';    private const ERROR_LOG   = '/opt/librenms/logs/migrate-rrd-retention-errors.log';    private const PID_PATH     = '/opt/librenms/logs/migrate-rrd-retention.pid';
    private const RRD_DIR      = '/opt/librenms/rrd';
    private const BACKUP_DIR   = '/opt/librenms/rrd_backup_pre_migration';
    private const CONFIG_PATH  = '/opt/librenms/config.php';
    private const TARGET_RRA   = 'RRA:AVERAGE:0.5:1:114048 RRA:MAX:0.5:1:114048';

    // Servicios de LibreNMS que deben detenerse antes de migrar
    private const SERVICES = [
        'librenms-scheduler.timer',
    ];

    public function authorize(\Illuminate\Contracts\Auth\Authenticatable $user): bool
    {
        return $user->can('admin');
    }

    public function data(): array
    {
        $action = request()->input('action');

        if (request()->isMethod('post')) {
            switch ($action) {
                case 'run':          return $this->handleRun();
                case 'stop_svcs':    $this->handleServices('stop');   break;
                case 'start_svcs':   $this->handleServices('start');  break;
                case 'clear_log':    $this->clearLog();               break;
                case 'apply_config': return $this->handleApplyConfig();
            }
        }

        return $this->statusData();
    }

    // ---------------------------------------------------------------

    private function handleRun(): array
    {
        $status = $this->statusData();

        if ($status['is_running']) {
            return array_merge($status, [
                'flash'      => 'La migración ya está en ejecución.',
                'flash_type' => 'warning',
            ]);
        }

        if ($status['any_active']) {
            return array_merge($status, [
                'flash'      => 'Detén los servicios de LibreNMS antes de iniciar la migración para evitar corrupción de archivos RRD.',
                'flash_type' => 'danger',
            ]);
        }

        $log = escapeshellarg(self::LOG_PATH);
        $pid = escapeshellarg(self::PID_PATH);
        $py  = escapeshellarg(self::SCRIPT_PATH);

        shell_exec("nohup python3 {$py} > {$log} 2>&1 & echo \$! > {$pid}");

        // Aplicar config automáticamente al iniciar la migración
        $this->applyRrdRraConfig();

        return array_merge($this->statusData(), [
            'flash'      => 'Migración iniciada en segundo plano. Configuración rrd_rra aplicada en config.php.',
            'flash_type' => 'success',
        ]);
    }

    private function handleApplyConfig(): array
    {
        $result = $this->applyRrdRraConfig();

        return array_merge($this->statusData(), [
            'flash'      => $result['msg'],
            'flash_type' => $result['ok'] ? 'success' : 'danger',
        ]);
    }

    /**
     * Inserta o actualiza $config['rrd_rra'] en config.php.
     * Devuelve ['ok' => bool, 'msg' => string].
     *
     * @return array{ok: bool, msg: string}
     */
    private function applyRrdRraConfig(): array
    {
        if (! file_exists(self::CONFIG_PATH) || ! is_writable(self::CONFIG_PATH)) {
            return ['ok' => false, 'msg' => 'No se puede escribir en ' . self::CONFIG_PATH];
        }

        $content = file_get_contents(self::CONFIG_PATH);
        $line    = '$config[\'rrd_rra\'] = "' . self::TARGET_RRA . '";';
        $pattern = '/^\$config\[\'rrd_rra\'\]\s*=\s*[^\n]+;/m';

        if (preg_match($pattern, $content)) {
            // Ya existe — actualizar solo si el valor es diferente
            $updated = preg_replace($pattern, $line, $content);
            if ($updated === $content) {
                return ['ok' => true, 'msg' => 'config.php ya tenía la retención correcta. Sin cambios.'];
            }
            file_put_contents(self::CONFIG_PATH, $updated);

            return ['ok' => true, 'msg' => 'config.php actualizado con la nueva retención rrd_rra.'];
        }

        // No existe — agregar antes del cierre del archivo
        $updated = rtrim($content) . "\n\n" . $line . "\n";
        file_put_contents(self::CONFIG_PATH, $updated);

        return ['ok' => true, 'msg' => 'Retención rrd_rra agregada en config.php para nuevos dispositivos.'];
    }

    /** Verifica si config.php ya tiene rrd_rra con el valor correcto. */
    private function configIsCorrect(): bool
    {
        if (! file_exists(self::CONFIG_PATH)) {
            return false;
        }
        $content = file_get_contents(self::CONFIG_PATH);
        $escaped = preg_quote(self::TARGET_RRA, '/');

        return (bool) preg_match('/\$config\[\'rrd_rra\'\]\s*=\s*"' . $escaped . '"\s*;/', $content);
    }

    private function handleServices(string $op): void
    {
        // Validar que $op solo pueda ser 'start' o 'stop'
        if (! in_array($op, ['start', 'stop'], true)) {
            return;
        }
        foreach (self::SERVICES as $svc) {
            shell_exec('sudo systemctl ' . $op . ' ' . escapeshellarg($svc) . ' 2>&1');
        }
        // Pequeña pausa para que systemd refleje el cambio de estado
        sleep(2);
    }

    private function clearLog(): void
    {
        if (file_exists(self::LOG_PATH)) {
            file_put_contents(self::LOG_PATH, '');
        }
    }

    private function isRunning(): bool
    {
        if (! file_exists(self::PID_PATH)) {
            return false;
        }
        $pid = (int) trim(file_get_contents(self::PID_PATH));
        if ($pid <= 0) {
            return false;
        }
        $running = file_exists("/proc/{$pid}");
        // Limpiar PID file si el proceso ya terminó
        if (! $running) {
            @unlink(self::PID_PATH);
        }

        return $running;
    }

    /** Lee las últimas $lines líneas del log sin cargar el archivo completo en memoria. */
    private function readLogTail(string $path, int $lines = 200): string
    {
        $result = shell_exec('tail -n ' . (int) $lines . ' ' . escapeshellarg($path) . ' 2>/dev/null');

        return $result ?? '';
    }

    /** Devuelve true si el estado indica que el servicio/timer está corriendo. */
    private function isActiveStatus(string $status): bool
    {
        return in_array($status, ['active', 'waiting'], true);
    }

    /** @return array<string,string>  servicio => 'active'|'inactive'|'waiting'|'unknown' */
    private function serviceStatuses(): array
    {
        $result = [];
        foreach (self::SERVICES as $svc) {
            $out = trim((string) shell_exec("systemctl is-active " . escapeshellarg($svc) . " 2>/dev/null"));
            // timer activo = 'active' (waiting); timer parado = 'inactive'
            $result[$svc] = in_array($out, ['active', 'inactive', 'waiting'], true) ? $out : 'unknown';
        }

        return $result;
    }

    private function rrdCount(): int
    {
        $count = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::RRD_DIR, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->getExtension() === 'rrd') {
                $count++;
            }
        }

        return $count;
    }

    private function statusData(): array
    {
        $services = $this->serviceStatuses();
        // 'active' y 'waiting' indican que el timer/servicio está corriendo
        $anyActive = count(array_filter($services, fn($s) => $this->isActiveStatus($s))) > 0;

        $rrdCount = is_dir(self::RRD_DIR) ? $this->rrdCount() : 0;
        // Estimación: ~3 segundos por RRD (dump + modify XML + restore)
        $estSeconds  = $rrdCount * 3;
        $estMinutes  = (int) round($estSeconds / 60);

        return [
            'is_running'    => $this->isRunning(),
            'backup_exists' => is_dir(self::BACKUP_DIR),
            'log_content'   => file_exists(self::LOG_PATH) ? $this->readLogTail(self::LOG_PATH, 200) : '',
            'error_log'     => file_exists(self::ERROR_LOG) ? file_get_contents(self::ERROR_LOG) : '',
            'error_log_path' => self::ERROR_LOG,
            'rrd_count'     => $rrdCount,
            'est_minutes'   => $estMinutes,
            'log_path'      => self::LOG_PATH,
            'services'      => $services,
            'any_active'    => $anyActive,
            'config_ok'     => $this->configIsCorrect(),
            'target_rra'    => self::TARGET_RRA,
            'flash'         => null,
            'flash_type'    => 'info',
        ];
    }
}
