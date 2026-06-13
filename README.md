"# smarthome_php" # Feather BLE Sensor Monitoring System

This project provides a complete solution to monitor temperature and humidity from an Adafruit Feather 32u4 Bluefruit LE and display it on a web dashboard hosted on a Raspberry Pi.

## Components

1.  **Arduino Sketch** (`feather_sketch.ino`): Firmware for the Feather board.
2.  **Data Collector** (`data_collector.py`): Python script to receive BLE data and store it in SQLite.
3.  **Web Dashboard** (`index.html`, `data.php`, `export.php`): HTML/JS frontend and PHP backend to visualize the data.

## Setup Instructions

### 1. Arduino Setup
- Open `feather_sketch.ino` in the Arduino IDE.
- Install the following libraries:
    - `Adafruit BluefruitLE nRF51`
    - `Adafruit DHT Sensor Library`
    - `Adafruit Unified Sensor`
- Upload the sketch to your Adafruit Feather 32u4 Bluefruit LE.

### 2. Raspberry Pi Setup

#### Install Dependencies
```bash
sudo apt update
sudo apt install apache2 php php-sqlite3 sqlite3 python3-pip
pip3 install bleak
```

#### Deploy Web Files
Copy the web files to the Apache document root:
```bash
sudo cp index.html data.php export.php /var/www/smarthome/
```

#### Permissions (Critical)
The data collector and Apache both need access to the database file.
```bash
# Create the database file if it doesn't exist
sudo touch /var/www/smarthome/sensor_data.db
# Set ownership to www-data (Apache user)
sudo chown www-data:www-data /var/www/smarthome/sensor_data.db
# Ensure the directory is also writable if you want the script to be able to create the DB
sudo chown www-data:www-data /var/www/smarthome/
# Add your user to the www-data group to run the collector script
sudo usermod -a -G www-data $USER
# Change permissions so group can write
sudo chmod 664 /var/www/smarthome/sensor_data.db
```
*Note: You may need to logout and log back in for group changes to take effect.*

#### Run the Data Collector
```bash
python3 data_collector.py
```
*Note: Ensure the `DB_PATH` in `data_collector.py` matches `/var/www/smarthome/sensor_data.db`.*

### 3. View the Dashboard
Navigate to `http://<your-raspberry-pi-ip>/smarthome/index.html` in your web browser.

### 4. Email Notifications (Gmail)
To enable registration confirmation emails via Gmail:
1.  Log in to the dashboard and open the **Configurazione** (⚙️) modal.
2.  In the **Impostazioni Email (SMTP)** section, enter your Gmail address in **Username SMTP** and **Email Mittente**.
3.  **Crucial**: Do not use your regular Gmail password. You must create a **Google App Password**:
    - Go to your Google Account settings.
    - Security -> 2-Step Verification -> App passwords.
    - Select 'Other' and name it (e.g., 'SmartHome Monitor').
    - Copy the 16-character code and paste it into the **Password SMTP** field in the dashboard.
4.  Ensure **Host SMTP** is `smtp.gmail.com`, **Porta** is `587`, and **Sicurezza** is `tls`.
5.  Click **Salva**.
