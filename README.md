# Symcon-Vallox

IP-Symcon-Modul für **Vallox-Lüftungsanlagen** (MyVallox / MV-Serie, u. a.
**ValloPlus 270 MV**) über die MyVallox-WebSocket-Schnittstelle.

> **Status v0.1 – Lesezugriff live am echten ValloPlus 270 MV verifiziert**
> (Firmware v2, alle Register-Offsets exakt bestätigt). Das Schreiben
> (Profilwechsel) ist implementiert, aber am Gerät noch nicht live getestet.

## Funktionen

- **Auslesen:** Außen-/Zu-/Ab-/Fortluft-Temperatur, Luftfeuchte, CO₂,
  Lüfterstufe (%), Zu-/Abluftventilator-Drehzahl (rpm), Wärmetauscher-Zustand,
  Abtauung, Rest-Timer (Boost/Kamin/Extra), Filterwechsel-Restzeit,
  Betriebsstunden, Heizregister-Status, Störungszähler, Gerätemodell.
- **Wärmerückgewinnungs-Wirkungsgrad:** aus den Temperaturen berechnet
  (das Gerät füllt die Wirkungsgrad-Register nicht), nur im Rückgewinnungs-
  betrieb bei ausreichender Temperaturdifferenz.
- **Verbrauch:** eine externe Messvariable (z. B. Zwischenzähler/Messsteckdose)
  kann in der Konfig verknüpft und in die Instanz gespiegelt werden — die
  Anlage selbst liefert keinen elektrischen Verbrauch.
- **Steuern:** Betriebsprofil setzen — Home, Away, Boost, Fireplace, Extra, Auto
  (Boost/Fireplace/Extra mit optionaler Dauer in Minuten).

## Installation

1. In Symcon: **Module Store → Modul-Control → Repository hinzufügen**
   `https://github.com/JonnyD136/Symcon-Vallox`
2. Instanz **Vallox MV** anlegen.
3. **IP-Adresse/Hostname** der Anlage eintragen, Abfrageintervall wählen.

## Konfiguration

| Feld | Bedeutung |
|------|-----------|
| IP-Adresse / Hostname | Netzwerkadresse der Vallox-Anlage |
| Abfrageintervall | Poll-Intervall in Sekunden (0 = aus) |
| Verbrauchsvariable | Optional: externe Variable (z. B. Messsteckdose), wird als „Verbrauch" gespiegelt |

Das Datenmodell (Register-Offsets) wird beim Verbinden **bevorzugt vom Gerät**
geladen und andernfalls aus dem mitgelieferten Modell **2.0.16** aufgebaut.

## Öffentliche Funktionen

```php
VLX_Poll(int $InstanceID);                          // alle Werte aktualisieren
VLX_RequestInfo(int $InstanceID);                   // Datenmodell + Modellname neu ermitteln
VLX_SetProfile(int $InstanceID, int $Profile, int $Duration = 0);
                                                    // 1=Home 2=Away 3=Boost 4=Fireplace 5=Extra 6=Auto
VLX_ReadRegisterByName(int $InstanceID, string $Name); // Debug: Einzelregister lesen
```

## Technik

- Standalone Device (Type 3), Prefix `VLX`
- WebSocket `ws://<host>/` (Port 80), Modbus-artiges Binärprotokoll
- Reiner-PHP-WS-Client (RFC 6455), keine externen Abhängigkeiten

## Danksagung

Protokoll rekonstruiert aus
[yozik04/vallox_websocket_api](https://github.com/yozik04/vallox_websocket_api)
und [danielbayerlein/vallox-api](https://github.com/danielbayerlein/vallox-api).
