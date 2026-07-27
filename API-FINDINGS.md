# Vallox MV WebSocket-API — Erkenntnisse

Rekonstruiert aus `yozik04/vallox_websocket_api` (Python) und
`danielbayerlein/vallox-api` (JS). Stand: 2026-07-27. **Noch nicht am eigenen
Gerät verifiziert.**

## Verbindung

- WebSocket: `ws://<host>/` — Port **80**, Pfad `/`
- Keine Authentifizierung
- Datenmodell (JS-Konstanten) unter `http://<host>/js/bundle.js` bzw.
  `http://<host>/js/vallox.js`

## Rahmenformat (Modbus-artig)

Alle Felder 16-Bit. Little-Endian für Request-Aufbau, Antwort-Tabelle
Big-Endian.

```
length(u16 LE) | command(u16 LE) | payload... | checksum(u16 LE)
```

- `length`   = Anzahl der 16-Bit-Wörter **nach** dem Längenfeld
  (Write: `n_items*2 + 2`; ReadTables: `3`)
- `checksum` = `Σ (16-Bit-LE-Wörter von length..payload) & 0xFFFF`

### Kommandos (`WS_WEB_UI_COMMAND_*`, aus 2.0.16)

| Name | Wert |
|------|------|
| READ_DATA | 250 |
| WRITE_DATA | 249 |
| LOG | 247 |
| READ_TABLES | 246 |
| LOG_RAW | 243 |

### READ_TABLES

Request (fix): `length=3, command=246, items=0` →
Hex `0300 f600 0000 f900` (= `0300f6000000f900`). ✅ byte-genau nachgebaut.

Antwort: ein großer Bytestrom, interpretiert als Array `uint16 **Big-Endian**`.
Registerwert = `data[offset]`.

### WRITE_DATA

Payload = Folge von `(address u16 LE, value u16 LE)`, **nach Adresse sortiert**.

## Offset-Berechnung (Adresse → Tabellen-Index)

Die Antworttabelle ist die sequenzielle Verkettung mehrerer „Ranges". Jede Range
hat `RANGE_START_<x>`, `RANGE_END_<x>` (in `VlxDevConstants`) und eine Anzahl
`CYC_NUM_OF_<…>` (in `VlxReadConstants`). Buffer-Offset einer Range = Summe der
Anzahlen aller vorherigen Ranges. Register-Offset = `range_base + (addr − range_start)`.

Range-Reihenfolge (= Buffer-Layout):
```
g_cyclone_general_info, g_typhoon_general_info, g_cyclone_hw_state,
g_cyclone_sw_state, g_cyclone_time, g_cyclone_output, g_cyclone_input,
g_cyclone_config, g_cyclone_settings, g_typhoon_settings,
[FW v2: g_self_test | FW v3: g_constant_flow],
g_faults, g_cyclone_weekly_schedule, g_cyclone_extended (optional)
```
FW-Erkennung: v2, wenn `RANGE_START_g_self_test` existiert.

Stichprobe (Modell 2.0.16, v2), gegen `buffer_ranges.py` verifiziert:

| Register | Adresse | Offset |
|----------|--------:|------:|
| A_CYC_FAN_SPEED | 4353 | 64 |
| A_CYC_TEMP_EXTRACT_AIR | 4354 | 65 |
| A_CYC_TEMP_EXHAUST_AIR | 4355 | 66 |
| A_CYC_TEMP_OUTDOOR_AIR | 4356 | 67 |
| A_CYC_TEMP_SUPPLY_AIR | 4358 | 69 |
| A_CYC_EXTR_FAN_SPEED | 4361 | 72 |
| A_CYC_SUPP_FAN_SPEED | 4362 | 73 |
| A_CYC_RH_VALUE | 4363 | 74 |
| A_CYC_CO2_VALUE | 4364 | 75 |
| A_CYC_STATE | 4609 | 107 |
| A_CYC_MODE | 4610 | 108 |
| A_CYC_DEFROSTING | 4611 | 109 |
| A_CYC_BOOST_TIMER | 4612 | 110 |
| A_CYC_FIREPLACE_TIMER | 4613 | 111 |
| A_CYC_CELL_STATE | 4616 | 114 |
| A_CYC_REMAINING_TIME_FOR_FILTER | 4620 | 118 |

## Werte-Konvertierung

- **Temperatur:** `raw == 0` → n/a; sonst `round(raw/100 − 273.15, 1)` °C
- **Sonstige:** `raw == 0xFFFF` → n/a
- **RH:** Prozent (0–100) direkt · **CO₂:** ppm direkt · **Fan:** Prozent

## Profile

Enum: NONE=0, HOME=1, AWAY=2, BOOST=3, FIREPLACE=4, EXTRA=5, AUTO=6.

Ableitung: `BOOST_TIMER>0`→Boost · `FIREPLACE_TIMER>0`→Fireplace ·
`EXTRA_TIMER>0`→Extra · `STATE==0/1/2`→Home/Away/Auto.

Setzen:
| Profil | Schreibvorgang |
|--------|----------------|
| Home | STATE=0, alle Timer=0 |
| Away | STATE=1, alle Timer=0 |
| Auto | STATE=2, alle Timer=0 |
| Boost | BOOST_TIMER=Dauer (Std. `A_CYC_BOOST_TIME`), andere Timer=0 |
| Fireplace | FIREPLACE_TIMER=Dauer (Std. `A_CYC_FIREPLACE_TIME`), andere=0 |
| Extra | EXTRA_TIMER=Dauer (Std. `A_CYC_EXTRA_TIME`), andere=0 |

`A_CYC_MACHINE_MODEL` → Index in Gerätemodell-Liste (5 = „ValloPlus 270 MV").

## Energie / Verbrauch

Die MV-Serie hat **keinen** Energiezähler — es gibt kein `A_CYC_*_ENERGY`/
`*_POWER`/`*_KWH`-Register. Ein elektrischer Verbrauch ist nur schätzbar
(Lüfter % + Heizregister-Status × Nennleistung), nicht gemessen.

Als Ersatz ausgelesen (Offsets vs. 2.0.16 verifiziert):

| Register | Adresse | Offset | Variable |
|----------|--------:|------:|----------|
| A_CYC_SUPPLY_EFFICIENCY | 32775 | 288 | Wirkungsgrad Zuluft (%) |
| A_CYC_EXTRACT_EFFICIENCY | 32776 | 289 | Wirkungsgrad Abluft (%) |
| A_CYC_TOTAL_UP_TIME_HOURS | 4618 | 116 | Betriebsstunden gesamt (h) |
| A_CYC_CURRENT_UP_TIME_HOURS | 4619 | 117 | Betriebsstunden aktuell (h) |
| A_CYC_IO_HEATER | 4868 | 142 | Nachheizung an/aus |
| A_CYC_IO_EXTRA_HEATER | 4869 | 143 | Zusatz-Nachheizung an/aus |

## Offene Punkte (am Gerät klären)

1. Lädt die 270-MV-FW `js/bundle.js`/`js/vallox.js`? Sonst Fallback 2.0.16.
2. FW v2 oder v3 auf der 270 MV → korrekte Range-Auswahl.
3. Passt die gesamte Tabelle in **einen** WS-Frame? (sonst mehrere sammeln)
4. Antwort auf WRITE_DATA (Frame-Inhalt) — aktuell nur „Antwort vorhanden".
5. Skalierung RH/CO₂/Fan gegen Webfrontend gegenprüfen.
