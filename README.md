# MigrateRrdRetention — Plugin para LibreNMS

![PHP](https://img.shields.io/badge/PHP-8.1%2B-blue)
![LibreNMS](https://img.shields.io/badge/LibreNMS-compatible-green)
![License](https://img.shields.io/badge/license-MIT-lightgrey)

Plugin de administración que permite **migrar la retención de los archivos RRD** de LibreNMS a un nuevo esquema de alta resolución (`RRA:AVERAGE:0.5:1:114048 RRA:MAX:0.5:1:114048`) directamente desde la interfaz web. Incluye gestión de servicios, copia de seguridad automática y configuración de sudoers. **Solo accesible para administradores. No modifica ningún archivo del núcleo de LibreNMS.**

## Screenshots

![Panel principal](screenshots/main.png)

> Convierte todos los archivos RRD a retención de 13 meses con datos crudos cada 5 min (114,048 filas), con backup automático y control de servicios desde la interfaz.

---

## Características

- **Migración RRD** con un solo clic desde la interfaz de LibreNMS.
- **Backup automático** de todos los archivos RRD antes de la migración en `/opt/librenms/rrd_backup_pre_migration`.
- **Control de servicios**: detiene e inicia `librenms-scheduler.timer` antes y después de la migración.
- **Bitácora en tiempo real**: muestra el progreso y los errores de la migración.
- **Verificación de configuración**: comprueba que `config.php` tenga el `rrd_rra` correcto antes de migrar.
- **Configuración de sudoers**: verifica y guía la configuración de permisos para que el proceso web pueda controlar los servicios sin contraseña.
- Accesible únicamente para usuarios con rol de administrador.

---

## Instalación

### 1. Clonar el repositorio

```bash
cd /opt/librenms/app/Plugins
git clone https://github.com/danpal-dev/librenms-plugin-migrate-rrd-retention.git MigrateRrdRetention
```

### 2. Corregir permisos

```bash
chown -R librenms:librenms /opt/librenms/app/Plugins/MigrateRrdRetention
```

### 3. Configurar sudoers (necesario para gestionar servicios)

Crea el archivo `/etc/sudoers.d/librenms-plugin` con el siguiente contenido:

```
www-data ALL=(ALL) NOPASSWD: /bin/systemctl stop librenms-scheduler.timer, /bin/systemctl start librenms-scheduler.timer, /bin/systemctl status librenms-scheduler.timer
```

> Ajusta `www-data` al usuario del servidor web si es diferente (p. ej. `nginx`).

### 4. Activar el plugin en LibreNMS

1. Inicia sesión en LibreNMS como administrador.
2. Ve a **Configuración → Plugins** (o accede a `/plugins`).
3. Busca **MigrateRrdRetention** en la lista y haz clic en **Enable**.

### Actualizar

> Repositorio: https://github.com/danpal-dev/librenms-plugin-migrate-rrd-retention

```bash
cd /opt/librenms/app/Plugins/MigrateRrdRetention
git pull
chown -R librenms:librenms .
sudo -u librenms php artisan view:cache
sudo -u librenms php artisan cache:clear
```

### Desinstalar

1. Desactiva el plugin desde **Configuración → Plugins**.
2. Elimina la carpeta:

```bash
rm -rf /opt/librenms/app/Plugins/MigrateRrdRetention
```

---

## Uso

1. Accede a **Plugins → MigrateRrdRetention**.
2. Verifica que el panel de configuración muestre los checks en verde (config.php, sudoers, backup).
3. Haz clic en **Detener servicios** para pausar el scheduler.
4. Haz clic en **Iniciar migración** y monitoriza el progreso en la bitácora.
5. Al finalizar, haz clic en **Iniciar servicios**.

> **Advertencia:** La migración puede tardar varios minutos dependiendo del número de dispositivos y archivos RRD. Se recomienda realizarla en una ventana de mantenimiento.

---

## Requisitos

- LibreNMS con soporte de plugins (sistema de hooks `app/Plugins`).
- PHP 8.1+
- Python 3 (para el script de migración RRD).
- `rrdtool` instalado en el servidor.

## Base de datos

El plugin **no crea tablas nuevas ni escribe en `devices_attribs`**. La configuración de retención se guarda en la columna `settings` (JSON) de la tabla `plugins`. El script Python opera directamente sobre archivos `.rrd` en disco, sin modificar la base de datos.
- Permisos de sudoers configurados correctamente.

---

## Autor

**danpal-dev**
- GitHub: [@danpal-dev](https://github.com/danpal-dev)

---

## Licencia

MIT
