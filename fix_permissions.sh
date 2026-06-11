#!/bin/bash

# Script per ripristinare i permessi corretti per l'applicazione Smart Home
# Da eseguire con sudo: sudo ./fix_permissions.sh

TARGET_DIR="/var/www/smarthome"
WEB_USER="www-data"
WEB_GROUP="www-data"

echo "Ripristino permessi in $TARGET_DIR..."

if [ ! -d "$TARGET_DIR" ]; then
    echo "Errore: la directory $TARGET_DIR non esiste."
    exit 1
fi

# 1. Imposta il proprietario e il gruppo a www-data
echo "Impostazione proprietario: $WEB_USER:$WEB_GROUP"
chown -R $WEB_USER:$WEB_GROUP $TARGET_DIR

# 2. Imposta i permessi per le directory (rwxrwxr-x)
echo "Impostazione permessi directory (775)"
find $TARGET_DIR -type d -exec chmod 775 {} +

# 3. Imposta i permessi per i file (rw-rw-r--)
echo "Impostazione permessi file (664)"
find $TARGET_DIR -type f -exec chmod 664 {} +

# 4. Assicurati che il database SQLite sia scrivibile
if [ -f "$TARGET_DIR/sensor_data.db" ]; then
    echo "Verifica database: $TARGET_DIR/sensor_data.db"
    chmod 664 "$TARGET_DIR/sensor_data.db"
fi

# 5. Assicurati che la directory logs sia scrivibile
if [ -d "$TARGET_DIR/logs" ]; then
    echo "Verifica directory logs: $TARGET_DIR/logs"
    chmod 775 "$TARGET_DIR/logs"
fi

echo "Operazione completata."
