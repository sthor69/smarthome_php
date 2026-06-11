#include <Arduino.h>
#include <SPI.h>
#include "Adafruit_BLE.h"
#include "Adafruit_BluefruitLE_SPI.h"
#include "Adafruit_BluefruitLE_UART.h"
#include <DHT.h>

#include "BluefruitConfig.h"

#ifndef BLUEFRUIT_MODE_DATA
#define BLUEFRUIT_MODE_DATA 0
#endif

#if SOFTWARE_SERIAL_AVAILABLE
#include <SoftwareSerial.h>
#endif

/*=========================================================================
    APPLICATION SETTINGS
---------------------------------------------------------------------------*/
#define DHTPIN 12      // What digital pin we're connected to
#define DHTTYPE DHT22  // DHT 22 (AM2302), AM2321
#define BLUEFRUIT_HWSERIAL_NAME Serial1
#define BLUEFRUIT_UART_MODE_PIN -1  // Not used with SPI
/*=========================================================================*/

// Create the bluefruit object, either software serial...
/*
SoftwareSerial bluefruitSS = SoftwareSerial(BLUEFRUIT_SWUART_TXD_PIN, BLUEFRUIT_SWUART_RXD_PIN);

Adafruit_BluefruitLE_UART ble(bluefruitSS, BLUEFRUIT_UART_MODE_PIN,
                      BLUEFRUIT_UART_CTS_PIN, BLUEFRUIT_UART_RTS_PIN);
*/

/* ...or hardware serial, which does not need the RTS/CTS pins. SPI FLASH is used on the Feather 32u4 Bluefruit LE */
Adafruit_BluefruitLE_SPI ble(BLUEFRUIT_SPI_CS, BLUEFRUIT_SPI_IRQ, BLUEFRUIT_SPI_RST);

DHT dht(DHTPIN, DHTTYPE);

// A small helper
void error(const __FlashStringHelper* err) {
  Serial.println(err);
  while (1)
    ;
}

void setup(void) {
  while (!Serial)
    ;  // required for flora & leonardo
  delay(500);

  Serial.begin(115200);
  Serial.println(F("Adafruit Bluefruit DHT22 Data Collector"));
  Serial.println(F("---------------------------------------"));

  /* Initialise the module */
  Serial.print(F("Initialising the Bluefruit LE module: "));

  if (!ble.begin(VERBOSE_MODE)) {
    error(F("Couldn't find Bluefruit, make sure it's in CoMmanD mode & check wiring?"));
  }
  Serial.println(F("OK!"));

  /* Perform a factory reset to make sure everything is in a known state */
  Serial.println(F("Performing a factory reset: "));
  if (!ble.factoryReset()) {
    Serial.println(F("Couldn't factory reset"));
  }

  // Imposta il nome BLE
  if (!ble.sendCommandCheckOK("AT+GAPDEVNAME=Adafruit Bluefruit LE")) {
    Serial.println(F("Errore impostazione nome"));
  }
  // Salva in memoria
  ble.sendCommandCheckOK("ATZ");
  delay(1000);

  /* Disable command echo from Bluefruit */
  ble.echo(false);

  Serial.println("Requesting Bluefruit info:");
  /* Print Bluefruit information */
  ble.info();

  Serial.println(F("Please use Adafruit Bluefruit LE app to connect in UART mode"));
  Serial.println(F("Then Enter characters to send to Bluefruit"));
  Serial.println();

  ble.verbose(true);  // debug info is a little annoying after this point!

  /* Wait for connection */
  Serial.println(F("Waiting fot connection with ..."));
  while (!ble.isConnected()) {
    delay(500);
  }
  Serial.println(F("CONNECTED!!"));

  // Switch to DATA mode to send raw UART data
  ble.setMode(BLUEFRUIT_MODE_DATA);

  // LED Activity command is only supported from 0.6.6
  if (ble.isVersionAtLeast(MINIMUM_FIRMWARE_VERSION)) {
    // Change Mode LED Activity
    Serial.println(F("******************************"));
    Serial.println(F("Change LED activity to " MODE_LED_BEHAVIOUR));
    ble.sendCommandCheckOK("AT+HWMODELED=" MODE_LED_BEHAVIOUR);
    Serial.println(F("******************************"));
  }

  dht.begin();
}

void loop(void) {
  // Read temperature and humidity
  float h = dht.readHumidity();
  float t = dht.readTemperature();

  // Check if any reads failed and exit early (to try again).
  if (isnan(h) || isnan(t)) {
    Serial.println("Failed to read from DHT sensor!");
    delay(2000);
    return;
  }

  Serial.print("Humidity: ");
  Serial.print(h);
  Serial.print(" %\t");
  Serial.print("Temperature: ");
  Serial.print(t);
  Serial.println(" *C");

  // Send data over BLE UART
  float vbat = analogRead(A9) * 2.0 * 3.3 / 1024.0; bool isUSB = (USBSTA & (1 << VBUS)); ble.print("T:");
  ble.print(t);
  ble.print(",H:"); ble.print(h); ble.print(",V:"); ble.print(vbat); ble.print(",USB:"); ble.println(isUSB); //

  // Wait 10 seconds before next reading
  delay(10000);
}
