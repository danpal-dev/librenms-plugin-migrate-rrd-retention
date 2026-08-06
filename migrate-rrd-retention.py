#!/usr/bin/env python3
"""
Migra todos los archivos RRD de LibreNMS a retención de 13 meses
sin agrupamiento (datos crudos cada 5 min).

Nueva estructura:
  RRA:AVERAGE:0.5:1:114048  (396 días x 288 = 114,048 filas a 5 min)
  RRA:MAX:0.5:1:114048

Los RRAs agrupados (pdp_per_row > 1) se eliminan.
Los datos crudos existentes (rra[0] y rra[3], pdp_per_row=1) se preservan.
"""

import os
import shlex
import stat
import subprocess
import xml.etree.ElementTree as ET
import glob
import tempfile

RRD_DIR = "/opt/librenms/rrd"
TARGET_ROWS = 114048
BACKUP_DIR = "/opt/librenms/rrd_backup_pre_migration"
ERROR_LOG = "/opt/librenms/logs/migrate-rrd-retention-errors.log"

# Usuario/grupo destino para todos los archivos RRD
RRD_USER = "librenms"
RRD_GROUP = "librenms"


def _resolve_uid_gid() -> tuple[int, int]:
    """Resuelve uid/gid de librenms una sola vez al arrancar."""
    import pwd, grp
    try:
        uid = pwd.getpwnam(RRD_USER).pw_uid
        gid = grp.getgrnam(RRD_GROUP).gr_gid
    except KeyError as e:
        raise SystemExit(f"ERROR: usuario/grupo '{e}' no encontrado en el sistema.")
    return uid, gid


# UID/GID resueltos globalmente
_TARGET_UID, _TARGET_GID = _resolve_uid_gid()


def run_cmd(args: list[str], check: bool = True) -> str:
    """Ejecuta un comando como lista (sin shell=True) para evitar inyección."""
    result = subprocess.run(args, capture_output=True, text=True)
    if check and result.returncode != 0:
        raise RuntimeError(
            f"Error ejecutando: {shlex.join(args)}\n{result.stderr.strip()}"
        )
    return result.stdout


def fix_permissions(path: str, mode: int = 0o664) -> None:
    """Aplica propietario y permisos correctos inmediatamente tras crear/reemplazar un archivo."""
    os.chown(path, _TARGET_UID, _TARGET_GID)
    os.chmod(path, mode)


def migrate_rrd(rrd_path: str) -> tuple[bool, str]:
    """Migra un archivo RRD a retención 13 meses sin agrupamiento.

    Preserva propietario y permisos del archivo original en el archivo resultante.
    """
    # Capturar stat ANTES de cualquier modificación
    orig_stat = os.stat(rrd_path)
    orig_uid = orig_stat.st_uid
    orig_gid = orig_stat.st_gid
    orig_mode = stat.S_IMODE(orig_stat.st_mode)

    xml_fd, xml_path = tempfile.mkstemp(suffix=".xml", dir="/tmp")
    os.close(xml_fd)
    new_rrd = rrd_path + ".new"

    try:
        # 1. Exportar a XML usando stdout (sin shell redirection)
        with open(xml_path, "w") as xml_out:
            result = subprocess.run(
                ["rrdtool", "dump", rrd_path],
                stdout=xml_out,
                stderr=subprocess.PIPE,
                text=True,
            )
            if result.returncode != 0:
                raise RuntimeError(f"rrdtool dump falló: {result.stderr.strip()}")

        tree = ET.parse(xml_path)
        root = tree.getroot()

        # Determinar cuántos DS hay
        ds_count = len(root.findall("ds"))

        # Encontrar RRAs a conservar (pdp_per_row = 1)
        rras = root.findall("rra")
        raw_rras = []
        aggregated_rras = []

        for rra in rras:
            pdp = int(rra.find("pdp_per_row").text.strip())
            cf = rra.find("cf").text.strip()
            if pdp == 1:
                raw_rras.append((cf, rra))
            else:
                aggregated_rras.append(rra)

        # Si no hay RRAs crudos, no podemos migrar
        if not raw_rras:
            return False, "No se encontraron RRAs con pdp_per_row=1"

        # Eliminar RRAs agrupados del árbol
        for rra in aggregated_rras:
            root.remove(rra)

        # Expandir los RRAs crudos a TARGET_ROWS
        first_count = None
        for cf, rra in raw_rras:
            db = rra.find("database")
            current_rows_elem = list(db)
            current_count = len(current_rows_elem)
            if first_count is None:
                first_count = current_count

            rows_to_add = TARGET_ROWS - current_count
            if rows_to_add > 0:
                nan_values = "".join([f"<v>NaN</v>" for _ in range(ds_count)])
                existing_rows = list(db)
                for child in existing_rows:
                    db.remove(child)
                for _ in range(rows_to_add):
                    db.append(ET.fromstring(f"<row>{nan_values}</row>"))
                for row in existing_rows:
                    db.append(row)
            elif rows_to_add < 0:
                all_rows = list(db)
                for row in all_rows[: abs(rows_to_add)]:
                    db.remove(row)

        # Si falta MAX, agregar RRA MAX con NaN basado en AVERAGE
        has_max = any(cf == "MAX" for cf, _ in raw_rras)
        has_avg = any(cf == "AVERAGE" for cf, _ in raw_rras)
        if has_avg and not has_max:
            avg_rra = next(rra for cf, rra in raw_rras if cf == "AVERAGE")
            max_rra = ET.fromstring(ET.tostring(avg_rra))
            max_rra.find("cf").text = "MAX"
            db = max_rra.find("database")
            for row in list(db):
                db.remove(row)
            nan_values = "".join([f"<v>NaN</v>" for _ in range(ds_count)])
            for _ in range(TARGET_ROWS):
                db.append(ET.fromstring(f"<row>{nan_values}</row>"))
            root.append(max_rra)

        # 2. Guardar XML modificado
        tree.write(xml_path, encoding="utf-8", xml_declaration=True)

        # 3. Crear nuevo RRD desde el XML modificado
        run_cmd(["rrdtool", "restore", xml_path, new_rrd])

        # 4. Reemplazar el original de forma atómica
        os.replace(new_rrd, rrd_path)

        # 5. CRÍTICO: restaurar propietario y permisos inmediatamente
        #    (os.replace hereda el stat del archivo .new, no del original)
        os.chown(rrd_path, orig_uid, orig_gid)
        os.chmod(rrd_path, orig_mode)

        return True, f"Migrado: {first_count} → {TARGET_ROWS} filas"

    finally:
        # Limpiar archivos temporales siempre, incluso si hay error
        if os.path.exists(xml_path):
            os.unlink(xml_path)
        if os.path.exists(new_rrd):
            # Si llegamos aquí con .new existente, es porque replace falló
            # o fue interrumpido — lo eliminamos para no dejar basura root
            os.unlink(new_rrd)


def check_dependencies() -> None:
    """Verifica que rrdtool esté instalado antes de empezar."""
    result = subprocess.run(["which", "rrdtool"], capture_output=True)
    if result.returncode != 0:
        raise SystemExit("ERROR: rrdtool no está instalado o no está en el PATH.")


def main() -> None:
    check_dependencies()

    # Buscar todos los RRD
    rrd_files = glob.glob(f"{RRD_DIR}/**/*.rrd", recursive=True)
    total = len(rrd_files)
    print(f"Encontrados {total} archivos RRD")
    print(
        f"Destino: {TARGET_ROWS} filas (5 min × 396 días = 13 meses sin agrupamiento)"
    )
    print()

    # Crear backup del directorio (hardlinks para ahorrar espacio)
    if not os.path.exists(BACKUP_DIR):
        print(f"Creando backup en {BACKUP_DIR}...")
        run_cmd(["cp", "-al", RRD_DIR, BACKUP_DIR])
        print("Backup creado.")
    else:
        print(f"Backup ya existe en {BACKUP_DIR}, continuando...")
    print()

    ok = 0
    errors = []

    for i, rrd_path in enumerate(rrd_files, 1):
        rel = os.path.relpath(rrd_path, RRD_DIR)
        try:
            success, msg = migrate_rrd(rrd_path)
            if success:
                ok += 1
                pct = int(i / total * 100)
                print(f"[{i}/{total} {pct}%] {rel}: {msg}")
            else:
                errors.append((rel, msg))
                print(f"[{i}/{total}] SKIP {rel}: {msg}")
        except Exception as e:
            errors.append((rel, str(e)))
            print(f"[{i}/{total}] ERROR {rel}: {e}")

    print()
    print(f"=== Completado: {ok}/{total} migrados ===")

    if errors:
        print(f"Errores ({len(errors)}) — detalle completo en: {ERROR_LOG}")
        with open(ERROR_LOG, "w") as ef:
            ef.write(f"Errores de migración ({len(errors)}):\n")
            for name, err in errors:
                ef.write(f"  - {name}: {err}\n")
        # Ajustar permisos del log inmediatamente
        fix_permissions(ERROR_LOG, mode=0o640)
        for name, err in errors:
            print(f"  - {name}: {err}")

    # Auditoría final: verificar si quedó algún archivo sin propietario correcto
    wrong = []
    for rrd_path in glob.glob(f"{RRD_DIR}/**/*.rrd", recursive=True):
        s = os.stat(rrd_path)
        if s.st_uid != _TARGET_UID or s.st_gid != _TARGET_GID:
            wrong.append(rrd_path)

    if wrong:
        print(f"\n[WARN] {len(wrong)} archivo(s) con propietario incorrecto — corrigiendo...")
        for p in wrong:
            fix_permissions(p)
        print("Permisos corregidos.")
    else:
        print("\n[OK] Todos los archivos RRD tienen propietario correcto.")


if __name__ == "__main__":
    main()

