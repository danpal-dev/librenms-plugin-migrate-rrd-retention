<div style="margin: 15px;">
    <h4><i class="fa fa-database"></i> Migración de Retención RRD — Configuración</h4>
    <p class="text-muted">
        Este plugin es de <strong>uso único</strong>. No hay parámetros que configurar aquí.<br>
        La página principal del plugin (<a href="{{ url('plugin/MigrateRrdRetention') }}">Migrar Retención RRD</a>)
        es donde se ejecuta la migración.
    </p>

    <hr>

    <h5>Estado del sistema</h5>
    <table class="table table-condensed" style="max-width: 600px;">
        <tbody>
            <tr>
                <td><i class="fa fa-file-code-o"></i> <strong>config.php — rrd_rra</strong></td>
                <td>
                    @if($config_ok)
                        <span class="label label-success"><i class="fa fa-check"></i> Correcto</span>
                    @else
                        <span class="label label-warning"><i class="fa fa-exclamation-triangle"></i> No configurado</span>
                    @endif
                </td>
                <td class="text-muted" style="font-size:11px;">{{ $config_path }}</td>
            </tr>
            <tr>
                <td><i class="fa fa-lock"></i> <strong>Sudoers</strong></td>
                <td>
                    @if($sudoers_ok)
                        <span class="label label-success"><i class="fa fa-check"></i> Configurado</span>
                    @else
                        <span class="label label-warning"><i class="fa fa-exclamation-triangle"></i> Pendiente</span>
                    @endif
                </td>
                <td class="text-muted" style="font-size:11px;">{{ $sudoers_path }}</td>
            </tr>
            <tr>
                <td><i class="fa fa-hdd-o"></i> <strong>Backup pre-migración</strong></td>
                <td>
                    @if($backup_exists)
                        <span class="label label-success"><i class="fa fa-check"></i> Disponible</span>
                    @else
                        <span class="label label-default"><i class="fa fa-minus"></i> No creado</span>
                    @endif
                </td>
                <td class="text-muted" style="font-size:11px;">{{ $backup_dir }}</td>
            </tr>
        </tbody>
    </table>

    <hr>

    <h5>Retención objetivo</h5>
    <pre style="background:#f5f5f5; padding:8px; border-radius:3px; font-size:12px;">$config['rrd_rra'] = "{{ $target_rra }}";</pre>
    <p class="text-muted" style="font-size:12px;">
        114,048 filas × 5 min = 396 días (~13 meses) de datos crudos sin agrupamiento.
    </p>

    @if(!$sudoers_ok)
    <hr>
    <h5>Configurar Sudoers</h5>
    <p class="text-muted" style="font-size:12px;">
        Para que los botones <strong>Detener/Iniciar Servicios</strong> funcionen desde el plugin, ejecuta:
    </p>
    <pre style="background:#1e1e1e; color:#d4d4d4; padding:10px; border-radius:3px; font-size:12px;">echo 'www-data ALL=(ALL) NOPASSWD: /bin/systemctl start librenms-scheduler.timer, /bin/systemctl stop librenms-scheduler.timer' \
  | sudo tee /etc/sudoers.d/librenms-plugin
sudo chmod 440 /etc/sudoers.d/librenms-plugin</pre>
    @endif

</div>
