import asyncio
import sqlite3
import re
import os
import logging
import signal
import sys
from bleak import BleakClient, BleakScanner
from bleak.exc import BleakError

# --- Configurazione ---
DEVICE_NAME = "Adafruit Bluefruit LE"
DEVICE_ADDRESS = None  # oppure "XX:XX:XX:XX:XX:XX" per forzare MAC
UART_TX_CHAR_UUID = "6e400003-b5a3-f393-e0a9-e50e24dcca9e"
DB_PATH = "/var/www/html/sensor_data.db"
RECONNECT_DELAY = 10  # secondi tra un tentativo di riconnessione e il prossimo
ADDRESS_CACHE_FILE = os.path.join(os.path.dirname(__file__), ".last_ble_address")

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

# --- Persistence ---
def load_cached_address():
    if os.path.exists(ADDRESS_CACHE_FILE):
        try:
            with open(ADDRESS_CACHE_FILE, "r") as f:
                addr = f.read().strip()
                if addr:
                    log.info(f"Caricato indirizzo salvato: {addr}")
                    return addr
        except Exception as e:
            log.error(f"Errore lettura cache indirizzo: {e}")
    return None

def save_cached_address(address):
    try:
        with open(ADDRESS_CACHE_FILE, "w") as f:
            f.write(address)
            log.info(f"Indirizzo salvato in cache: {address}")
    except Exception as e:
        log.error(f"Errore scrittura cache indirizzo: {e}")

def clear_cached_address():
    if os.path.exists(ADDRESS_CACHE_FILE):
        try:
            os.remove(ADDRESS_CACHE_FILE)
            log.info("Cache indirizzo rimossa.")
        except Exception as e:
            log.error(f"Errore rimozione cache indirizzo: {e}")

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
    """Cerca il device BLE, ritorna l'indirizzo o il device se trovato."""
    # 1. Priorità all'indirizzo configurato
    if DEVICE_ADDRESS:
        log.info(f"Utilizzo indirizzo configurato: {DEVICE_ADDRESS}")
        return DEVICE_ADDRESS

    # 2. Verifica cache
    cached = load_cached_address()
    if cached:
        log.info(f"Utilizzo indirizzo dalla cache: {cached}")
        return cached

    # 3. Scansione se non abbiamo un indirizzo noto
    log.info(f"Ricerca per nome: '{DEVICE_NAME}'")
    devices = await BleakScanner.discover(timeout=10)
    log.info(f"Device trovati: {len(devices)}")
    for d in devices:
        log.info(f"  {d.address} | '{d.name}'")

    device = next((d for d in devices if d.name and DEVICE_NAME in d.name), None)
    if device:
        save_cached_address(device.address)
        return device

    return None

async def ensure_disconnected(device_or_address):
    """Tenta di forzare la disconnessione di una sessione rimasta appesa."""
    addr = device_or_address.address if hasattr(device_or_address, 'address') else device_or_address
    log.info(f"Verifica sessioni appese per {addr}...")
    try:
        async with BleakClient(device_or_address, timeout=5.0) as client:
            log.info(f"Connessione stabilita per pulizia, ora disconnetto...")
    except Exception as e:
        log.debug(f"Nessuna sessione appesa o errore durante pulizia (ignorabile): {e}")

async def connect_and_listen(device):
    """Connette e rimane in ascolto. Lancia eccezione se la connessione cade."""
    addr = device.address if hasattr(device, 'address') else device
    log.info(f"Connessione a {addr}...")
    async with BleakClient(device, timeout=20) as client:
        log.info(f"Connesso!")
        await client.start_notify(UART_TX_CHAR_UUID, notification_handler)
        log.info("Notifiche attive. In ascolto...")
        # Rimane connesso finché non cade la connessione
        while client.is_connected:
            await asyncio.sleep(2.0)
        log.warning("Connessione caduta.")

async def run():
    # Aspetta che bluetoothd sia pronto
    await asyncio.sleep(5)
    
    """Loop principale con riconnessione automatica."""
    while True:
        try:
            device = await find_device()
            if not device:
                log.warning(f"Device '{DEVICE_NAME}' non trovato. Riprovo tra {RECONNECT_DELAY}s...")
                await asyncio.sleep(RECONNECT_DELAY)
                continue

            # Tenta di pulire eventuali connessioni rimaste appese
            await ensure_disconnected(device)

            await connect_and_listen(device)

        except BleakError as e:
            if "InProgress" in str(e):
                log.warning("Bluetooth non ancora pronto, attendo...")
                await asyncio.sleep(15)
                continue
            log.error(f"Errore BLE: {e}")
            # Se la connessione fallisce e stavamo usando la cache, proviamo a pulirla
            if not DEVICE_ADDRESS:
                log.info("Sospetto problema con l'indirizzo in cache. Pulizia...")
                clear_cached_address()
        except Exception as e:
            log.error(f"Errore generico: {e}")

        log.info(f"Riconnessione tra {RECONNECT_DELAY} secondi...")
        try:
            await asyncio.sleep(RECONNECT_DELAY)
        except asyncio.CancelledError:
            break

def handle_exit(sig, frame):
    log.info(f"Ricevuto segnale {sig}. Terminazione...")
    # Questo forzerà la chiusura dell'event loop
    sys.exit(0)

if __name__ == "__main__":
    init_db()

    # Gestione segnali di terminazione
    signal.signal(signal.SIGINT, handle_exit)
    signal.signal(signal.SIGTERM, handle_exit)

    try:
        asyncio.run(run())
    except (KeyboardInterrupt, SystemExit):
        log.info("Stop.")
