import sqlite3
from datetime import datetime

# Generar el nombre del archivo de backup con fecha y hora
fecha_hora = datetime.now().strftime("%Y%m%d_%H%M%S")
backup_filename = f'backup_{fecha_hora}.db'

# Conectamos a la base de datos original
conn = sqlite3.connect('calificaciones.db')

# Creamos la conexión al archivo de backup
with sqlite3.connect(backup_filename) as backup_conn:
    conn.backup(backup_conn)

conn.close()

print(f"Backup creado: {backup_filename}")
