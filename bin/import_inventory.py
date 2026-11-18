#!/usr/bin/env python3
"""Import the October inventory snapshot from the supplied XLSX workbook."""
import argparse
import csv
import os
import re
import subprocess
import sys
import tempfile
import xml.etree.ElementTree as ET
import zipfile
from datetime import datetime, timedelta
from pathlib import Path

NS = {
    "main": "http://schemas.openxmlformats.org/spreadsheetml/2006/main",
    "office_rel": "http://schemas.openxmlformats.org/officeDocument/2006/relationships",
    "package_rel": "http://schemas.openxmlformats.org/package/2006/relationships",
}
COLUMNS = [
    "external_id", "item_code", "description", "account_code", "account_name",
    "alternate_code", "building_code", "location_code", "location_name",
    "status", "permitted", "added_date", "status_date", "updated_date",
    "status_time", "source_sheet",
]
SOURCE_COLUMNS = {
    "external_id": "B", "item_code": "J", "description": "D",
    "account_code": "E", "account_name": "I", "alternate_code": "L",
    "building_code": "F", "location_code": "O", "location_name": "A",
    "status": "M", "permitted": "C", "added_date": "K",
    "status_date": "H", "updated_date": "G", "status_time": "N",
}
PSQL_IMPORT_SQL = """
BEGIN;
TRUNCATE inventory_import_stage;
\\copy inventory_import_stage (external_id,item_code,description,account_code,account_name,alternate_code,building_code,location_code,location_name,status,permitted,added_date,status_date,updated_date,status_time,source_sheet) FROM '{csv_path}' WITH (FORMAT csv, HEADER true, NULL '\\N');
DO $$ BEGIN
    IF EXISTS (SELECT 1 FROM inventory_items) THEN
        RAISE EXCEPTION 'La importación solo se permite cuando el inventario está vacío.';
    END IF;
    IF EXISTS (
        SELECT 1
        FROM inventory_import_stage s
        WHERE BTRIM(s.account_name) <> ''
          AND BTRIM(s.account_code) <> ''
          AND NOT EXISTS (
              SELECT 1 FROM clients c
              WHERE c.code = CASE
                  WHEN LENGTH(split_part(BTRIM(s.account_code), CHR(92), 1)) < 4
                  THEN LPAD(split_part(BTRIM(s.account_code), CHR(92), 1), 4, CHR(48))
                  ELSE split_part(BTRIM(s.account_code), CHR(92), 1)
              END
          )
    ) THEN
        RAISE EXCEPTION 'La importación contiene códigos de cliente que no están en el catálogo maestro.';
    END IF;
    IF EXISTS (
        SELECT 1
        FROM inventory_import_stage s
        JOIN clients c
          ON c.code = CASE
              WHEN LENGTH(split_part(BTRIM(s.account_code), CHR(92), 1)) < 4
              THEN LPAD(split_part(BTRIM(s.account_code), CHR(92), 1), 4, CHR(48))
              ELSE split_part(BTRIM(s.account_code), CHR(92), 1)
          END
        WHERE POSITION(CHR(92) IN BTRIM(s.account_code)) > 0
          AND NOT EXISTS (
              SELECT 1
              FROM departaments d
              WHERE d.client_id = c.id
                AND d.department_code IN (
                    SUBSTRING(BTRIM(s.account_code) FROM POSITION(CHR(92) IN BTRIM(s.account_code)) + 1),
                    CASE
                        WHEN LEFT(
                            SUBSTRING(BTRIM(s.account_code) FROM POSITION(CHR(92) IN BTRIM(s.account_code)) + 1),
                            LENGTH(c.code) + 1
                        ) = c.code || '-'
                        THEN SUBSTRING(
                            SUBSTRING(BTRIM(s.account_code) FROM POSITION(CHR(92) IN BTRIM(s.account_code)) + 1)
                            FROM LENGTH(c.code) + 2
                        )
                        ELSE SUBSTRING(BTRIM(s.account_code) FROM POSITION(CHR(92) IN BTRIM(s.account_code)) + 1)
                    END
                )
          )
    ) THEN
        RAISE EXCEPTION 'La importación contiene códigos de departamento que no están en el catálogo del cliente.';
    END IF;
END $$;
INSERT INTO building (code)
SELECT DISTINCT building_code
FROM inventory_import_stage
WHERE building_code <> ''
ON CONFLICT (code) DO NOTHING;
INSERT INTO warehouse_locations (building_code, code, name)
SELECT DISTINCT building_code, location_code, location_name
FROM inventory_import_stage
WHERE building_code <> '' AND location_code <> ''
ON CONFLICT (building_code, code) DO UPDATE SET
    name = CASE WHEN EXCLUDED.name <> '' THEN EXCLUDED.name ELSE warehouse_locations.name END;
INSERT INTO inventory_items (
    external_id,item_code,description,account_code,account_name,client_id,department_id,alternate_code,location_id,
    status,permitted,added_date,status_date,updated_date,status_time,source_sheet
)
SELECT s.external_id,s.item_code,s.description,
       CASE
           WHEN LENGTH(split_part(BTRIM(s.account_code), CHR(92), 1)) < 4
           THEN LPAD(split_part(BTRIM(s.account_code), CHR(92), 1), 4, CHR(48))
           ELSE split_part(BTRIM(s.account_code), CHR(92), 1)
       END,
       s.account_name,c.id,COALESCE(direct_department.id, prefixed_department.id),s.alternate_code,l.id,
       s.status,s.permitted,s.added_date,s.status_date,s.updated_date,s.status_time,s.source_sheet
FROM inventory_import_stage s
JOIN warehouse_locations l ON l.building_code = s.building_code AND l.code = s.location_code
LEFT JOIN clients c
  ON c.code = CASE
      WHEN LENGTH(split_part(BTRIM(s.account_code), CHR(92), 1)) < 4
      THEN LPAD(split_part(BTRIM(s.account_code), CHR(92), 1), 4, CHR(48))
      ELSE split_part(BTRIM(s.account_code), CHR(92), 1)
  END
LEFT JOIN departaments direct_department
  ON direct_department.client_id = c.id
 AND POSITION(CHR(92) IN BTRIM(s.account_code)) > 0
 AND direct_department.department_code =
    SUBSTRING(BTRIM(s.account_code) FROM POSITION(CHR(92) IN BTRIM(s.account_code)) + 1)
LEFT JOIN departaments prefixed_department
 ON direct_department.id IS NULL
 AND prefixed_department.client_id = c.id
 AND POSITION(CHR(92) IN BTRIM(s.account_code)) > 0
 AND prefixed_department.department_code = CASE
     WHEN LEFT(
         SUBSTRING(BTRIM(s.account_code) FROM POSITION(CHR(92) IN BTRIM(s.account_code)) + 1),
         LENGTH(c.code) + 1
     ) = c.code || '-'
     THEN SUBSTRING(
         SUBSTRING(BTRIM(s.account_code) FROM POSITION(CHR(92) IN BTRIM(s.account_code)) + 1)
         FROM LENGTH(c.code) + 2
     )
     ELSE SUBSTRING(BTRIM(s.account_code) FROM POSITION(CHR(92) IN BTRIM(s.account_code)) + 1)
 END;
COMMIT;
"""


def load_env(project: Path) -> dict[str, str]:
    env = os.environ.copy()
    for env_file in (project.parent / ".inventory.env", project / ".env"):
        if env_file.exists():
            for line in env_file.read_text(encoding="utf-8").splitlines():
                line = line.strip()
                if not line or line.startswith("#") or "=" not in line:
                    continue
                key, value = line.split("=", 1)
                env.setdefault(key.strip(), value.strip())
    return env


def cell_value(cell: ET.Element, shared_strings: list[str]) -> str:
    value = cell.find("main:v", NS)
    if cell.get("t") == "s" and value is not None:
        return shared_strings[int(value.text or "0")]
    if cell.get("t") == "inlineStr":
        return "".join(node.text or "" for node in cell.iter(f"{{{NS['main']}}}t"))
    return (value.text or "") if value is not None else ""


def excel_date(value: str) -> str:
    if not value:
        return r"\N"
    try:
        return (datetime(1899, 12, 30) + timedelta(days=float(value))).date().isoformat()
    except (ValueError, OverflowError):
        return r"\N"


def extract_sheet_names(archive: zipfile.ZipFile) -> dict[str, str]:
    workbook = ET.fromstring(archive.read("xl/workbook.xml"))
    rels = ET.fromstring(archive.read("xl/_rels/workbook.xml.rels"))
    targets = {entry.get("Id"): entry.get("Target") for entry in rels}
    result = {}
    for sheet in workbook.findall("main:sheets/main:sheet", NS):
        target = targets[sheet.get(f"{{{NS['office_rel']}}}id")]
        target = target.lstrip("/")
        if not target.startswith("xl/"):
            target = "xl/" + target
        result[sheet.get("name", "")] = target
    return result


def import_workbook(workbook: Path, project: Path, env: dict[str, str]) -> int:
    if not workbook.is_file():
        raise FileNotFoundError(f"No se encontró el archivo: {workbook}")
    strings = []
    with zipfile.ZipFile(workbook) as archive:
        if "xl/sharedStrings.xml" in archive.namelist():
            for _, element in ET.iterparse(archive.open("xl/sharedStrings.xml"), events=("end",)):
                if element.tag.endswith("}si"):
                    strings.append("".join(node.text or "" for node in element.iter() if node.tag.endswith("}t")))
                    element.clear()

        sheets = extract_sheet_names(archive)
        missing = [name for name in ("CONSOLIDADO", "DESTRUCCIÓN") if name not in sheets]
        if missing:
            raise ValueError(f"El Excel no contiene las hojas requeridas: {', '.join(missing)}")

        seen_ids: set[str] = set()
        written = 0
        with tempfile.NamedTemporaryFile(
            mode="w", encoding="utf-8", newline="", suffix=".csv",
            prefix=".xlsx-import-", dir=project, delete=False,
        ) as csv_file:
            csv_path = Path(csv_file.name)
            writer = csv.writer(csv_file)
            writer.writerow(COLUMNS)
            for sheet_name in ("CONSOLIDADO", "DESTRUCCIÓN"):
                worksheet = ET.iterparse(archive.open(sheets[sheet_name]), events=("end",))
                for _, row in worksheet:
                    if not row.tag.endswith("}row"):
                        continue
                    values = {}
                    for cell in row:
                        if cell.tag.endswith("}c"):
                            match = re.match(r"[A-Z]+", cell.get("r", ""))
                            if match:
                                values[match.group()] = cell_value(cell, strings).strip()
                    row.clear()
                    if values.get("B", "").strip().upper() == "ID":
                        continue
                    external_id = values.get("B", "").strip()
                    if not external_id or external_id in seen_ids:
                        continue
                    seen_ids.add(external_id)
                    status = values.get("M", "").strip().lower()
                    if status == "in":
                        status = "in"
                    elif status == "out":
                        status = "out"
                    elif status == "perm out":
                        status = "permanent_out"
                    else:
                        raise ValueError(f"Estado no reconocido para el ID {external_id}: {status!r}")
                    record = {key: values.get(column, "").strip() for key, column in SOURCE_COLUMNS.items()}
                    record["external_id"] = external_id
                    record["status"] = status
                    record["permitted"] = "true" if record["permitted"].lower() in ("yes", "si", "sí", "true", "1") else "false"
                    for key in ("added_date", "status_date", "updated_date"):
                        record[key] = excel_date(record[key])
                    record["source_sheet"] = sheet_name
                    writer.writerow([record.get(column, "") for column in COLUMNS])
                    written += 1
            csv_file.flush()

    psql_args = [
        "psql", "-h", env.get("INVENTORY_DB_HOST", "127.0.0.1"),
        "-p", env.get("INVENTORY_DB_PORT", "5432"),
        "-U", env.get("INVENTORY_DB_USER", "postgres"),
        "-d", env.get("INVENTORY_DB_NAME", "inventory"),
        "-v", "ON_ERROR_STOP=1",
    ]
    env["PGPASSWORD"] = env.get("INVENTORY_DB_PASSWORD", "")
    try:
        import_sql = PSQL_IMPORT_SQL.format(csv_path=csv_path.as_posix().replace("'", "''"))
        subprocess.run(psql_args + ["-f", "-"], input=import_sql, text=True, env=env, check=True)
    finally:
        csv_path.unlink(missing_ok=True)
    return written


def main() -> int:
    parser = argparse.ArgumentParser(description="Importa el inventario inicial desde el Excel.")
    parser.add_argument("workbook", nargs="?", help="Ruta del archivo XLSX")
    args = parser.parse_args()
    project = Path(__file__).resolve().parents[1]
    workbook = Path(args.workbook).expanduser() if args.workbook else Path(
        "/home/ntovar/Descargas/CAPACIDAD ALMACEN AL CIERRE DE OCTUBRE DE 2026.xlsx"
    )
    env = load_env(project)
    if not env.get("INVENTORY_DB_PASSWORD"):
        print("Falta INVENTORY_DB_PASSWORD en el entorno o en /home/ntovar/.inventory.env.", file=sys.stderr)
        return 2
    try:
        count = import_workbook(workbook, project, env)
    except (OSError, ValueError, zipfile.BadZipFile, subprocess.CalledProcessError) as error:
        print(f"Error al importar el archivo: {error}", file=sys.stderr)
        return 1
    print(f"Importación completada: {count:,} artículos únicos (sin duplicar la hoja INICIALES).")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
