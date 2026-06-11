#include <Arduino.h>
#include <SPI.h>
#include "Adafruit_BLE.h"
#include "Adafruit_BluefruitLE_SPI.h"

#include <DHT.h>
#include "BluefruitConfig.h"

/*=========================================================================
    APPLICATION SETTINGS
---------------------------------------------------------------------------*/
#define DHTPIN        12          // Pin dati DHT22
#define DHTTYPE       DHT22       // DHT 22 (AM2302), AM2321
#define VBATPIN       A9          // Partitore batteria sul Feather 32u4
#define SEND_INTERVAL 10000UL     // ms tra una lettura/invio e l'altra
#define DEVICE_NAME   "Adafruit Bluefruit LE"
/*=========================================================================*/

// Bluefruit via SPI hardware (Feather 32u4 Bluefruit LE)
Adafruit_BluefruitLE_SPI ble(BLUEFRUIT_SPI_CS, BLUEFRUIT_SPI_IRQ, BLUEFRUIT_SPI_RST);

DHT dht(DHTPIN, DHTTYPE);

bool wasConnected = false;
unsigned long lastSend = 0;

// Errore fatale: lampeggia il LED rosso invece di bloccarsi in silenzio
void fatalError(const __FlashStringHelper* err) {
  Serial.println(err);
  pinMode(LED_BUILTIN, OUTPUT);
  while (1) {
    digitalWrite(LED_BUILTIN, HIGH); delay(100);
    digitalWrite(LED_BUILTIN, LOW);  delay(100);
  }
}

void setup(void) {
  // NIENTE "while (!Serial);" — bloccherebbe per sempre il boot
  // quando il Feather è alimentato senza monitor seriale aperto.
  Serial.begin(115200);
  delay(2000);  // tempo per aprire il monitor se collegato, poi si parte comunque

  Serial.println(F("Feather DHT22 -> BLE UART"));
  Serial.println(F("-------------------------"));

  Serial.print(F("Init Bluefruit: "));
  if (!ble.begin(VERBOSE_MODE)) {
    fatalError(F("Bluefruit non trovato: verifica cablaggio/modalita'"));
  }
  Serial.println(F("OK"));

  // NIENTE factory reset ad ogni boot: cancella la configurazione e
  // riavvia il modulo due volte, allungando i tempi e creando race
  // con il collector che tenta di connettersi.

  ble.echo(false);

  // Il nome è salvato in NVM: impostarlo ad ogni boot è innocuo,
  // ma non serve ATZ (il reset ritarderebbe solo l'advertising).
  ble.sendCommandCheckOK("AT+GAPDEVNAME=" DEVICE_NAME);

  ble.info();

  // LED del modulo in modalita' MODE (se firmware >= 0.6.6)
  if (ble.isVersionAtLeast(MINIMUM_FIRMWARE_VERSION)) {
    ble.sendCommandCheckOK("AT+HWMODELED=" MODE_LED_BEHAVIOUR);
  }

  ble.verbose(false);

  // DATA mode subito, senza aspettare una connessione:
  // setup() non deve MAI bloccarsi in attesa di un central.
  ble.setMode(BLUEFRUIT_MODE_DATA);

  dht.begin();

  Serial.println(F("In advertising. In attesa del collector..."));
}

void loop(void) {
  // 1) Se nessuno e' connesso, non leggere e non trasmettere:
  //    in DATA mode i print senza connessione vengono scartati.
  if (!ble.isConnected()) {
    if (wasConnected) {
      Serial.println(F("Disconnesso. Torno in advertising..."));
      wasConnected = false;
    }
    delay(1000);
    return;
  }

  if (!wasConnected) {
    Serial.println(F("CONNESSO!"));
    wasConnected = true;
    lastSend = 0;  // invia subito la prima lettura
  }

  // 2) Cadenza di invio senza delay() lungo bloccante:
  //    cosi' la perdita di connessione viene rilevata entro ~1s.
  unsigned long now = millis();
  if (lastSend != 0 && (now - lastSend) < SEND_INTERVAL) {
    delay(200);
    return;
  }

  // 3) Lettura sensore
  float h = dht.readHumidity();
  float t = dht.readTemperature();

  if (isnan(h) || isnan(t)) {
    Serial.println(F("Lettura DHT fallita, riprovo..."));
    delay(2000);
    return;
  }

  // 4) Telemetria alimentazione
  float vbat = analogRead(VBATPIN) * 2.0 * 3.3 / 1024.0;
  bool isUSB = (USBSTA & (1 << VBUS));

  Serial.print(F("T=")); Serial.print(t);
  Serial.print(F("C H=")); Serial.print(h);
  Serial.print(F("% V=")); Serial.print(vbat);
  Serial.print(F("V USB=")); Serial.println(isUSB);

  // 5) Invio su BLE UART: una riga unica, terminata da \n,
  //    nel formato atteso dalla regex di data_collector.py:
  //    T:<f>,H:<f>,V:<f>,USB:<0|1>
  ble.print(F("T:"));   ble.print(t);
  ble.print(F(",H:"));  ble.print(h);
  ble.print(F(",V:"));  ble.print(vbat);
  ble.print(F(",USB:")); ble.println(isUSB ? 1 : 0);

  lastSend = now;
}
