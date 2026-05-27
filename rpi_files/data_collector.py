import asyncio
import sqlite3
import datetime
import re
import os
from bleak import BleakClient, BleakScanner

DEVICE_NAME = "SensoreTH"  # aggiorna col nome reale
DEVICE_ADDRESS = None       # oppure metti "XX:XX:XX:XX:XX:XX" per forzare MAC
UART_TX_CHAR_UUID = "6e400003-b5a3-f393-e0a9-e50e24dcca9e"
DB_PATH = "/var/www/smarthome/sensor_data.db"

def init_db():
    print(f"[DB] Path: {DB_PATH}")
    print(f"[DB] Directory esiste: {os.path.exists(os.path.dirname(DB_PATH))}")
    print(f"[DB] Directory scrivibile: {os.access(os.path.dirname(DB_PATH), os.W_OK)}")
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
        print("[DB] Tabella inizializzata OK")
    except Exception as e:
        print(f"[DB] ERRORE init: {e}")

def store_data(temperature, humidity):
    try:
        conn = sqlite3.connect(DB_PATH)
        cursor = conn.cursor()
        cursor.execute(
            'INSERT INTO measurements (temperature, humidity) VALUES (?, ?)',
            (temperature, humidity)
        )
        conn.commit()
        # Verifica che sia stato inserito
        cursor.execute('SELECT COUNT(*) FROM measurements')
        count = cursor.fetchone()[0]
        conn.close()
        print(f"[DB] Salvato OK - Temp={temperature}, Hum={humidity} | Totale righe: {count}")
    except Exception as e:
        print(f"[DB] ERRORE salvataggio: {e}")

def notification_handler(sender, data):
    print(f"[BLE] Raw bytes: {data.hex()}")
    try:
        message = data.decode("utf-8").strip()
        print(f"[BLE] Messaggio: '{message}'")
        match = re.search(r"T:([-+]?\d*\.?\d+),H:([-+]?\d*\.?\d+)", message)
        if match:
            temp = float(match.group(1))
            hum = float(match.group(2))
            print(f"[BLE] Parsed - T={temp}, H={hum}")
            store_data(temp, hum)
        else:
            print(f"[BLE] Nessun match nel messaggio: '{message}'")
    except Exception as e:
        print(f"[BLE] ERRORE parsing: {e}")

async def run():
    if DEVICE_ADDRESS:
        print(f"[BLE] Connessione diretta a {DEVICE_ADDRESS}")
        device = await BleakScanner.find_device_by_address(DEVICE_ADDRESS)
    else:
        print(f"[BLE] Scansione per '{DEVICE_NAME}'...")
        # Mostra TUTTI i device trovati
        devices = await BleakScanner.discover(timeout=10)
        print(f"[BLE] Device trovati: {len(devices)}")
        for d in devices:
            print(f"  -> {d.address} | nome: '{d.name}'")
        device = next((d for d in devices if d.name and DEVICE_NAME in d.name), None)

    if not device:
        print(f"[BLE] Device non trovato!")
        return

    print(f"[BLE] Connessione a {device.name} ({device.address})...")
    async with BleakClient(device) as client:
        print(f"[BLE] Connesso: {client.is_connected}")
        services = client.services
        print("[BLE] Servizi disponibili:")
        for s in services:
            print(f"  Servizio: {s.uuid}")
            for c in s.characteristics:
                print(f"    Caratteristica: {c.uuid} | props: {c.properties}")

        await client.start_notify(UART_TX_CHAR_UUID, notification_handler)
        print("[BLE] Notifiche attive. In attesa dati...")
        while True:
            await asyncio.sleep(1.0)

if __name__ == "__main__":
    init_db()
    try:
        asyncio.run(run())
    except KeyboardInterrupt:
        print("Stop.")