# CLAUDE.md — Vallox MV IP-Symcon-Modul

## Projektübersicht

IP-Symcon-Modul (PHP) zur **Überwachung + Profilsteuerung** einer Vallox-KWL
(MyVallox / MV-Serie, primär **ValloPlus 270 MV R**) über deren WebSocket-API.

- **Architektur:** Standalone Device (Type 3), kein Parent-IO
- **Protokoll:** WebSocket `ws://<host>/` (Port 80), Modbus-artige Binärframes
- **WS-Client:** RFC-6455 in reinem PHP (Raw-Socket, Binärframes, kurzlebig pro Poll)
- **Prefix:** `VLX`
- **Status:** ⚠️ **v0.1 — NICHT gegen echte Hardware getestet** (Gerät folgt).
  Protokoll-Encoder byte-genau gegen die Referenz-Libs verifiziert, Offsets gegen
  das Datenmodell 2.0.16 verifiziert. Realer Test steht aus.

## Protokoll (rekonstruiert)

Quellen: `yozik04/vallox_websocket_api` (Python) und `danielbayerlein/vallox-api`
(JS). Beide u. a. gegen ValloPlus 270 MV getestet.

Rahmen (alle Zahlen 16-Bit):
```
length(LE) | command(LE) | payload... | checksum(LE)
```
- `length`   = Anzahl 16-Bit-Wörter **nach** dem Längenfeld
- `checksum` = Summe aller vorangehenden 16-Bit-**LE**-Wörter & 0xFFFF
- Kommandos: `READ_TABLES=246`, `WRITE_DATA=249`, `READ_DATA=250`

**Lesen** (`READ_TABLES`, Rahmen `0300f6000000f900`): Antwort ist ein großes
Array aus **uint16 Big-Endian**. Jedes Register liegt an einem festen Offset.

**Schreiben** (`WRITE_DATA`): `(address, value)`-Paare, nach Adresse sortiert.

**Offset-Berechnung:** Register-Adresse → Antwort-Offset über „Buffer-Ranges"
(`RANGE_START_*`/`RANGE_END_*` + `CYC_NUM_OF_*`-Zähler, sequenziell gepackt).
Nachgebildet in `ComputeOffsets()` / `BuildRangesFromConstants()`. Hängt von der
Firmware ab → das Modell wird **bevorzugt vom Gerät** geladen
(`http://<host>/js/bundle.js` bzw. `js/vallox.js`, Regex-Parse der
`VlxDevConstants`/`VlxReadConstants`), sonst dient `datamodel.json` (2.0.16) als
Fallback. Das aufgelöste Modell wird im Attribut `ResolvedModel` gecacht.

## Profile (A_CYC_STATE + Timer)

Ableitung (Referenz-Logik, `DeriveProfile()`):
`BOOST_TIMER>0`→Boost, sonst `FIREPLACE_TIMER>0`→Fireplace, sonst
`EXTRA_TIMER>0`→Extra, sonst `STATE 0/1/2`→Home/Away/Auto.
Setzen (`SetProfile()`): Home/Away/Auto = `A_CYC_STATE` 0/1/2 + Timer 0;
Boost/Fireplace/Extra = jeweiliger Timer = Dauer (Standard aus
`A_CYC_*_TIME`), andere Timer 0.

## FACE-Konventionen (eingehalten)

- Klassenname = `module.json`-„name" ohne Leerzeichen → `ValloxMV`
- Timer-Callback in Prefix-Form: `VLX_Poll($_IPS["TARGET"])`
- `KR_READY`-Guard in `ApplyChanges()`
- Eigener Online-Status via `SetStatus()` (102/200/201/202), kein `HasActiveParent`
- `SetValueIfChanged()` statt blindem `SetValue()`
- Instanz-Profile `VLX.<InstanceID>.<Suffix>`, Cleanup in `Destroy()`
- WS/HTTP mit harten Timeouts; Poll-Fehler reißen den Timer nie ab

## Dateistruktur

```
Symcon-Vallox/
├── library.json               ← Library {9CFEBCBA-…}
├── README.md
├── CLAUDE.md                   ← diese Datei
├── API-FINDINGS.md            ← Protokoll-Details + offene Punkte
└── Vallox/
    ├── module.json            ← Type 3, Prefix VLX, {5ED1BFDC-…}
    ├── module.php             ← Hauptklasse inkl. WS-Client + Modell-Loader
    ├── form.json
    └── datamodel.json         ← gebündeltes Modell 2.0.16 (Fallback)
```

## Verifiziert (ohne Hardware)

- `php -l` fehlerfrei, alle JSON valide ✅
- Klassenname-Regel erfüllt ✅
- `READ_TABLES`-Frame byte-identisch zur Python-Referenz (`0300f6000000f900`) ✅
- Offset-Berechnung deckt sich mit `buffer_ranges.py` (Stichprobe Register) ✅

## Am echten Gerät zu prüfen (WICHTIG)

1. Firmware-Datenmodell: lädt `js/bundle.js`/`js/vallox.js`? Sonst greift
   Fallback 2.0.16 — Offsets ggf. falsch, wenn FW-Ranges abweichen.
2. `is_fw_v2`-Erkennung (270 MV kann FW v2 **oder** v3 sein) → richtige Range
   (`g_self_test` vs. `g_constant_flow`).
3. Schreibvorgänge (Profilwechsel) am realen Gerät verifizieren, bevor produktiv.
4. Temperatur-Skalierung (`raw/100 − 273.15`), RH/CO₂-Rohwerte gegen Webfrontend
   abgleichen.
5. Antwortframe-Größe (ganze Tabelle in einem Frame?) — sonst Mehrfach-Frames
   einsammeln.

## Weiterer Ausbau (Ideen)

1. Solltemperaturen je Profil setzen (`A_CYC_*_AIR_TEMP_TARGET`, to_kelvin)
2. Störungs-/Alarmtexte (`A_CYC_FAULT_CODE*` + ALARM_MESSAGES)
3. Filterwechsel-Datum (`A_CYC_FILTER_CHANGED_*`) als echtes Datum
4. RH-/CO₂-Sensorsteuerung (Grenzwerte)
5. Persistenter WS statt Poll (falls Gerät Push unterstützt)
