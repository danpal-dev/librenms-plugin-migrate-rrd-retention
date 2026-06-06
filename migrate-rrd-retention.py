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
import subprocess
import xml.etree.ElementTree as ET
import glob
import tempfile

RRD_DIR = "/opt/librenms/rrd"
TARGET_ROWS = 114048
BACKUP_DIR = "/opt/librenms/rrd_backup_pre_migration"
ERROR_LOG = "/opt/librenms/logs/migrate-rrd-retention-errors.log"

def run(cmd, check=True):
    result = subprocess.run(cmd, shell=True, capture_output=True, text=True)
    if check and result.returncode != 0:
        raise RuntimeError(f"Error ejecutando: {cmd}\n{result.stderr}")
    return result.stdout

def migrate_rrd(rrd_path):
    """Migra un archivo RRD a retención 13 meses sin agrupamiento."""
    
    # 1. Exportar a XML
    with tempfile.NamedTemporaryFile(suffix=".xml", delete=False) as f:
        xml_path = f.name
    
    try:
        run(f'rrdtool dump "{rrd_path}" > "{xml_path}"')
        
        tree = ET.parse(xml_path)
        root = tree.getroot()
        
        # Determinar cuántos DS hay
        ds_count = len(root.findall('ds'))
        
        # Encontrar RRAs a conservar (pdp_per_row = 1)
        rras = root.findall('rra')
        raw_rras = []
        aggregated_rras = []
        
        for rra in rras:
            pdp = int(rra.find('pdp_per_row').text.strip())
            cf = rra.find('cf').text.strip()
            if pdp == 1:
                raw_rras.append((cf, rra))
            else:
                aggregated_rras.append(rra)
        
        # Si no hay RRAs crudos, no podemos migrar
        if not raw_rras:
            return False, "No se encontraron RRAs con pdp_per_row=1"
        
        # Verificar que tengamos AVERAGE y MAX crudos
        # (raw_cfs disponible por si se necesita ampliar la lógica)
        # Eliminar RRAs agrupados del árbol
        for rra in aggregated_rras:
            root.remove(rra)
        
        # Expandir los RRAs crudos a TARGET_ROWS
        first_count = None
        for cf, rra in raw_rras:
            db = rra.find('database')
            current_rows_elem = list(db)
            current_count = len(current_rows_elem)
            if first_count is None:
                first_count = current_count  # guardar del primer RRA para el mensaje final
            
            # Actualizar el número de filas en la cabecera del RRA (no existe explícito, lo maneja rrdtool)
            # Necesitamos agregar filas NaN al principio para llegar a TARGET_ROWS
            rows_to_add = TARGET_ROWS - current_count
            if rows_to_add > 0:
                # Construir una fila NaN con el número correcto de DS
                nan_values = "".join([f"<v>NaN</v>" for _ in range(ds_count)])
                
                # Insertar al principio (datos más antiguos)
                # ET no tiene insert_before fácil, reconstruimos
                existing_rows = list(db)
                for child in existing_rows:
                    db.remove(child)
                
                # Agregar filas NaN primero
                for _ in range(rows_to_add):
                    row_el = ET.fromstring(f"<row>{nan_values}</row>")
                    db.append(row_el)
                
                # Luego los datos reales
                for row in existing_rows:
                    db.append(row)
            
            # Si hay más filas que TARGET_ROWS, recortar las más antiguas
            elif rows_to_add < 0:
                all_rows = list(db)
                # Mantener las más recientes (las últimas TARGET_ROWS)
                to_remove = all_rows[:abs(rows_to_add)]
                for row in to_remove:
                    db.remove(row)
        
        # Si falta MAX, agregar RRA MAX basado en el AVERAGE (con NaN)
        has_max = any(cf == 'MAX' for cf, _ in raw_rras)
        has_avg = any(cf == 'AVERAGE' for cf, _ in raw_rras)
        
        if has_avg and not has_max:
            # Clonar la estructura del AVERAGE pero con cf=MAX y todo NaN
            avg_rra = next(rra for cf, rra in raw_rras if cf == 'AVERAGE')
            max_rra = ET.fromstring(ET.tostring(avg_rra))
            max_rra.find('cf').text = 'MAX'
            db = max_rra.find('database')
            all_rows = list(db)
            nan_values = "".join([f"<v>NaN</v>" for _ in range(ds_count)])
            for row in all_rows:
                db.remove(row)
            for _ in range(TARGET_ROWS):
                db.append(ET.fromstring(f"<row>{nan_values}</row>"))
            root.append(max_rra)
        
        # Guardar XML modificado
        tree.write(xml_path, encoding='utf-8', xml_declaration=True)
        
        # 2. Crear nuevo RRD desde el XML modificado
        new_rrd = rrd_path + ".new"
        run(f'rrdtool restore "{xml_path}" "{new_rrd}"')
        
        # 3. Reemplazar el original
        os.replace(new_rrd, rrd_path)
        
        return True, f"Migrado: {first_count} → {TARGET_ROWS} filas"
        
    finally:
        if os.path.exists(xml_path):
            os.unlink(xml_path)
        new_rrd = rrd_path + ".new"
        if os.path.exists(new_rrd):
            os.unlink(new_rrd)

def check_dependencies():
    """Verifica que rrdtool esté instalado antes de empezar."""
    result = subprocess.run('which rrdtool', shell=True, capture_output=True)
    if result.returncode != 0:
        raise SystemExit("ERROR: rrdtool no está instalado o no está en el PATH.")

def main():
    check_dependencies()

    # Buscar todos los RRD
    rrd_files = glob.glob(f"{RRD_DIR}/**/*.rrd", recursive=True)
    total = len(rrd_files)
    print(f"Encontrados {total} archivos RRD")
    print(f"Destino: {TARGET_ROWS} filas (5 min × 396 días = 13 meses sin agrupamiento)")
    print()
    
    # Crear backup del directorio (hardlinks para ahorrar espacio)
    if not os.path.exists(BACKUP_DIR):
        print(f"Creando backup en {BACKUP_DIR}...")
        run(f'cp -al "{RRD_DIR}" "{BACKUP_DIR}"')
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
        with open(ERROR_LOG, 'w') as ef:
            ef.write(f"Errores de migración ({len(errors)}):\n")
            for name, err in errors:
                ef.write(f"  - {name}: {err}\n")
        # Mostrar también en el log principal (tail puede recortarlos, pero el archivo de errores siempre está completo)
        for name, err in errors:
            print(f"  - {name}: {err}")
    
    # Ajustar permisos
    run(f'chown -R librenms:librenms "{RRD_DIR}"')
    print("Permisos ajustados.")

if __name__ == "__main__":
    main()
