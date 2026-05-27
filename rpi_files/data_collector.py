import asyncio
import sqlite3
import re
import os
import logging
from bleak import BleakClient, BleakScanner
from bleak.exc import BleakError

# --- Configurazione ---
DEVICE_NAME = "SensoreTH"
DEVICE_ADDRESS = None  # oppure "XX:XX:XX:XX:XX:XX" per forzare MAC
UART_TX_CHAR_UUID = "6e400003-b5a3-f393-e0a9-e50e24dcca9e"
DB_PATH = "/var/www/html/sensor_data.db"
RECONNECT_DELAY = 10  # secondi tra un tentativo di riconnessione e il prossimo

# --- Logging ---
logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [%(levelname)s] %(message)s",
    datefmt="%Y-%m-%d %H:%M:%S"
)
log = logging.getLogger(__name__)

# --- Database ---
def init_db():
    log.info(f"DB path: {DB_PATH}")
    log.info(f"Directory esiste: {os.path.exists(os.path.dirname(DB_PATH))}")
    log.info(f"Directory scrivibile: {os.access(os.path.dirname(DB_PATH), os.W_OK)}")
    try:
        conn = sqlite3.connect(DB_PATH)
        cursor = conn.cursor()
        cursor.execute('''
            CREATE TABLE IF NOT EXISTS measurements (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
                temperature REAL,
                humidity REAL
            )
        ''')
        conn.commit()
        conn.close()
        log.info("Tabella DB inizializzata OK")
    except Exception as e:
        log.error(f"Errore init DB: {e}")

def store_data(temperature, humidity):
    try:
        conn = sqlite3.connect(DB_PATH)
        cursor = conn.cursor()
        cursor.execute(
            'INSERT INTO measurements (temperature, humidity) VALUES (?, ?)',
            (temperature, humidity)
        )
        conn.commit()
        cursor.execute('SELECT COUNT(*) FROM measurements')
        count = cursor.fetchone()[0]
        conn.close()
        log.info(f"Salvato: T={temperature:.1f} H={humidity:.1f} | Totale righe: {count}")
    except Exception as e:
        log.error(f"Errore salvataggio DB: {e}")

# --- BLE ---
def notification_handler(sender, data):
    try:
        message = data.decode("utf-8").strip()
        log.debug(f"BLE raw: '{message}'")
        match = re.search(r"T:([-+]?\d*\.?\d+),H:([-+]?\d*\.?\d+)", message)
        if match:
            temp = float(match.group(1))
            hum = float(match.group(2))
            log.info(f"Ricevuto: T={temp:.1f}C  H={hum:.1f}%")
            store_data(temp, hum)
        else:
            log.warning(f"Messaggio non riconosciuto: '{message}'")
    except Exception as e:
        log.error(f"Errore parsing notifica: {e}")

async def find_device():
    """Cerca il device BLE, ritorna None se non trovato."""
    if DEVICE_ADDRESS:
        log.info(f"Ricerca per indirizzo: {DEVICE_ADDRESS}")
        return await BleakScanner.find_device_by_address(DEVICE_ADDRESS, timeout=10)
    else:
        log.info(f"Ricerca per nome: '{DEVICE_NAME}'")
        devices = await BleakScanner.discover(timeout=10)
        log.info(f"Device trovati: {len(devices)}")
        for d in devices:
            log.info(f"  {d.address} | '{d.name}'")
        return next((d for d in devices if d.name and DEVICE_NAME in d.name), None)

async def connect_and_listen(device):
    """Connette e rimane in ascolto. Lancia eccezione se la connessione cade."""
    log.info(f"Connessione a {device.name} ({device.address})...")
    async with BleakClient(device, timeout=20) as client:
        log.info(f"Connesso!")
        await client.start_notify(UART_TX_CHAR_UUID, notification_handler)
        log.info("Notifiche attive. In ascolto...")
        # Rimane connesso finché non cade la connessione
        while client.is_connected:
            await asyncio.sleep(2.0)
        log.warning("Connessione caduta.")

async def run():
    """Loop principale con riconnessione automatica."""
    while True:
        try:
            device = await find_device()
            if not device:
                log.warning(f"Device '{DEVICE_NAME}' non trovato. Riprovo tra {RECONNECT_DELAY}s...")
                await asyncio.sleep(RECONNECT_DELAY)
                continue

            await connect_and_listen(device)

        except BleakError as e:
            log.error(f"Errore BLE: {e}")
        except Exception as e:
            log.error(f"Errore generico: {e}")

        log.info(f"Riconnessione tra {RECONNECT_DELAY} secondi...")
        await asyncio.sleep(RECONNECT_DELAY)

if __name__ == "__main__":
    init_db()
    try:
        asyncio.run(run())
    except KeyboardInterrupt:
        log.info("Stop.")
