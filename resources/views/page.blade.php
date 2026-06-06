<div class="container-fluid">

    <div class="row">
        <div class="col-md-12">
            <h2><i class="fa fa-database"></i> Migración de Retención RRD</h2>
            <p class="text-muted">
                Convierte todos los archivos RRD a retención de <strong>13 meses</strong>
                con datos crudos cada 5 min (114,048 filas, sin agrupamiento).
            </p>
        </div>
    </div>

    {{-- Flash --}}
    @if($flash)
        <div class="alert alert-{{ $flash_type }}">
            <i class="fa fa-{{ $flash_type === 'danger' ? 'exclamation-triangle' : ($flash_type === 'success' ? 'check-circle' : 'info-circle') }}"></i>
            {{ $flash }}
        </div>
    @endif

    {{-- ADVERTENCIA config.php --}}
    @if(!$config_ok)
    <div class="alert alert-warning">
        <h4><i class="fa fa-exclamation-triangle"></i> &nbsp;config.php no tiene rrd_rra configurado</h4>
        <p>
            Los nuevos dispositivos que agregues a LibreNMS crearán sus RRDs con la retención por defecto
            (7 días a 5 min), <strong>no con 13 meses</strong>. Aplica la configuración para que todos los
            dispositivos futuros sean consistentes con la migración.
        </p>
        <p style="margin-bottom:0; font-size:13px; font-family:monospace; background:#f5f5f5; padding:6px; border-radius:3px;">
            $config['rrd_rra'] = "{{ $target_rra }}";
        </p>
    </div>
    @endif

    {{-- ADVERTENCIA servicios activos --}}
    @if($any_active && !$is_running)
    <div class="alert alert-danger">
        <h4><i class="fa fa-exclamation-triangle"></i> &nbsp;Atención: servicios activos</h4>
        <p>
            El poller está corriendo. Si ejecutas la migración mientras LibreNMS escribe en los RRDs
            puedes <strong>corromper archivos</strong>. Detén los servicios primero.
        </p>
        <p class="text-muted" style="margin-bottom:0; font-size:13px;">
            <strong>Truco de los 5 minutos:</strong> el poller trabaja en ciclos de 5 min. Detén los servicios
            justo después de que termine un ciclo y tendrás tiempo hasta el siguiente. El botón
            <em>"Detener Servicios"</em> lo hace desde aquí sin necesidad de terminal.
        </p>
    </div>
    @endif

    {{-- Tarjetas de estado: 5 tarjetas en 2 filas --}}
    <div class="row" style="margin-bottom: 10px;">

        <div class="col-md-2 col-sm-4">
            <div class="panel panel-default">
                <div class="panel-body text-center">
                    <div style="font-size: 32px; font-weight: bold;">{{ number_format($rrd_count) }}</div>
                    <div class="text-muted">Archivos RRD</div>
                    <div class="text-muted" style="font-size:11px; margin-top:3px;">
                        ~{{ $est_minutes }} min estimados
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-2 col-sm-4">
            <div class="panel panel-{{ $backup_exists ? 'success' : 'warning' }}">
                <div class="panel-body text-center">
                    <i class="fa fa-{{ $backup_exists ? 'check-circle' : 'exclamation-triangle' }} fa-2x"></i><br>
                    <div class="text-muted" style="margin-top: 6px;">
                        Backup<br><small>{{ $backup_exists ? 'disponible' : 'no creado aún' }}</small>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-2 col-sm-4">
            <div class="panel panel-{{ $is_running ? 'info' : 'default' }}">
                <div class="panel-body text-center">
                    @if($is_running)
                        <i class="fa fa-spinner fa-spin fa-2x"></i><br>
                        <div class="text-muted" style="margin-top: 6px;">En curso…</div>
                    @else
                        <i class="fa fa-power-off fa-2x"></i><br>
                        <div class="text-muted" style="margin-top: 6px;">Sin proceso</div>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-md-2 col-sm-4">
            <div class="panel panel-{{ $config_ok ? 'success' : 'warning' }}">
                <div class="panel-body text-center">
                    <i class="fa fa-{{ $config_ok ? 'check-circle' : 'exclamation-triangle' }} fa-2x"></i><br>
                    <div class="text-muted" style="margin-top: 6px;">
                        config.php<br>
                        <small>{{ $config_ok ? 'rrd_rra OK' : 'rrd_rra pendiente' }}</small>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-4 col-sm-8">
            <div class="panel panel-{{ $any_active ? 'danger' : 'success' }}">
                <div class="panel-body" style="padding: 10px 15px;">
                    <div style="font-weight:bold; margin-bottom:6px;">
                        <i class="fa fa-cogs"></i> Servicios LibreNMS
                    </div>
                    @foreach($services as $svc => $status)
                        @php $short = str_replace(['.service','librenms-'], '', $svc); @endphp
                        <div style="font-size:12px; margin-bottom:3px;">
                            <span class="label label-{{ in_array($status, ['active','waiting']) ? 'danger' : ($status === 'inactive' ? 'success' : 'default') }}"
                                  style="min-width:58px; display:inline-block; text-align:center;">
                                {{ $status }}
                            </span>
                            &nbsp;{{ $short }}
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

    </div>{{-- /row tarjetas --}}

    {{-- Botones de acción --}}
    <div class="row" style="margin-bottom: 20px;">
        <div class="col-md-12">

            @if($any_active)
            <form method="POST" action="{{ url('plugin/MigrateRrdRetention') }}" style="display:inline;"
                  onsubmit="return confirm('¿Detener poller, discovery y services?\nLibreNMS dejará de recopilar datos hasta que los reinicies.')">
                @csrf
                <input type="hidden" name="action" value="stop_svcs">
                <button type="submit" class="btn btn-warning">
                    <i class="fa fa-stop"></i> Detener Servicios
                </button>
            </form>
            @else
            <form method="POST" action="{{ url('plugin/MigrateRrdRetention') }}" style="display:inline;">
                @csrf
                <input type="hidden" name="action" value="start_svcs">
                <button type="submit" class="btn btn-success" {{ $is_running ? 'disabled' : '' }}>
                    <i class="fa fa-play"></i> Iniciar Servicios
                </button>
            </form>
            @endif

            <form method="POST" action="{{ url('plugin/MigrateRrdRetention') }}" style="display:inline; margin-left:10px;"
                  onsubmit="return confirm('¿Iniciar la migración RRD?\nSe creará un backup automático antes de modificar cualquier archivo.\nTiempo estimado: ~{{ $est_minutes }} minutos.')">
                @csrf
                <input type="hidden" name="action" value="run">
                <button type="submit"
                        class="btn btn-{{ $any_active ? 'default' : 'danger' }}"
                        {{ ($is_running || $any_active) ? 'disabled' : '' }}
                        @if($any_active) title="Detén los servicios primero" @endif>
                    <i class="fa fa-bolt"></i> Iniciar Migración
                </button>
            </form>

            <form method="POST" action="{{ url('plugin/MigrateRrdRetention') }}" style="display:inline; margin-left:10px;">
                @csrf
                <input type="hidden" name="action" value="clear_log">
                <button type="submit" class="btn btn-default" {{ $is_running ? 'disabled' : '' }}>
                    <i class="fa fa-trash"></i> Limpiar Log
                </button>
            </form>

            <a href="{{ url('plugin/MigrateRrdRetention') }}" class="btn btn-default" style="margin-left:10px;">
                <i class="fa fa-refresh"></i> Refrescar
            </a>

            {{-- Aplicar config manualmente --}}
            @if(!$config_ok)
            <form method="POST" action="{{ url('plugin/MigrateRrdRetention') }}" style="display:inline; margin-left:10px;"
                  onsubmit="return confirm('¿Agregar rrd_rra en config.php?\nEsto afecta solo a nuevos dispositivos, no a los existentes.')">
                @csrf
                <input type="hidden" name="action" value="apply_config">
                <button type="submit" class="btn btn-warning">
                    <i class="fa fa-wrench"></i> Aplicar config.php
                </button>
            </form>
            @endif

        </div>
    </div>

    {{-- Nota sudo: solo si el sudoers no está configurado --}}
    @php
        $sudoOut  = (string) shell_exec('sudo -n systemctl status librenms-scheduler.timer 2>&1');
        $sudoersOk = !str_contains($sudoOut, 'password is required') && !str_contains($sudoOut, 'not allowed');
    @endphp
    @if(!$sudoersOk)
    <div class="alert alert-info" style="font-size:12px; padding:8px 15px;">
        <i class="fa fa-info-circle"></i>
        Para que <strong>Detener/Iniciar Servicios</strong> funcione, agrega a
        <code>/etc/sudoers.d/librenms-plugin</code>:<br>
        <code>www-data ALL=(ALL) NOPASSWD: /bin/systemctl start librenms-scheduler.timer, /bin/systemctl stop librenms-scheduler.timer</code>
    </div>
    @endif

    {{-- Log output --}}
    <div class="row">
        <div class="col-md-12">
            <div class="panel panel-default">
                <div class="panel-heading">
                    <strong>Log de Migración</strong>
                    <span class="text-muted" style="font-size:12px; margin-left:10px;">{{ $log_path }}</span>
                    <span class="text-muted" style="font-size:11px; margin-left:10px;">(últimas 200 líneas)</span>
                </div>
                <div class="panel-body" style="padding:0;">
                    <pre id="log-output" style="
                        background:#1e1e1e; color:#d4d4d4;
                        margin:0; padding:15px;
                        max-height:500px; overflow-y:auto;
                        font-size:12px; border-radius:0 0 4px 4px;
                    ">{{ $log_content ?: '(sin salida aún)' }}</pre>
                </div>
            </div>
        </div>
    </div>

    {{-- Log de errores completo --}}
    @if($error_log)
    <div class="row">
        <div class="col-md-12">
            <div class="panel panel-danger">
                <div class="panel-heading">
                    <strong><i class="fa fa-exclamation-triangle"></i> Errores de Migración (completo)</strong>
                    <span class="text-muted" style="font-size:12px; margin-left:10px;">{{ $error_log_path }}</span>
                </div>
                <div class="panel-body" style="padding:0;">
                    <pre style="
                        background:#2d1a1a; color:#f88;
                        margin:0; padding:15px;
                        max-height:300px; overflow-y:auto;
                        font-size:12px; border-radius:0 0 4px 4px;
                    ">{{ $error_log }}</pre>
                </div>
            </div>
        </div>
    </div>
    @endif

</div>{{-- /container --}}

@if($is_running)
<script>
    setTimeout(function () { location.reload(); }, 5000);
</script>
@endif
<script>
    var log = document.getElementById('log-output');
    if (log) { log.scrollTop = log.scrollHeight; }
</script>
