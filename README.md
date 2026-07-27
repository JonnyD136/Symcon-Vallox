# Symcon-Vallox

IP-Symcon-Modul für **Vallox-Lüftungsanlagen** (MyVallox / MV-Serie, u. a.
**ValloPlus 270 MV**) über die MyVallox-WebSocket-Schnittstelle.

> ⚠️ **v0.1 – noch nicht gegen echte Hardware getestet.** Protokoll und
> Register-Offsets sind aus den etablierten Referenz-Implementierungen
> rekonstruiert und byte-genau nachgebaut, der Test am realen Gerät steht aus.
> Siehe [CLAUDE.md](CLAUDE.md) → „Am echten Gerät zu prüfen".

## Funktionen

- **Auslesen:** Außen-/Zu-/Ab-/Fortluft-Temperatur, Luftfeuchte, CO₂,
  Lüfterstufe + Zu-/Abluftventilator, Wärmetauscher-Zustand, Abtauung,
  Rest-Timer (Boost/Kamin/Extra), Filterwechsel-Restzeit, Störungszähler,
  Gerätemodell.
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
