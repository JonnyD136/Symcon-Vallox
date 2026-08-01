<?php

declare(strict_types=1);

/**
 * Vallox MV – Zentrale Wohnraumlüftung (KWL) mit MyVallox-Netzwerkschnittstelle
 *
 * Standalone Device Module (Type 3), Prefix VLX. Kommuniziert über dieselbe
 * WebSocket-Schnittstelle wie das MyVallox-Webfrontend:
 *
 *   ws://<host>/         (Port 80, Pfad "/")
 *
 * Binärprotokoll (Modbus-artig, 16-Bit-Register):
 *   Rahmen  = length(LE) | command(LE) | payload... | checksum(LE)
 *             length  = Anzahl 16-Bit-Wörter nach dem Längenfeld
 *             checksum = Summe aller vorangehenden 16-Bit-LE-Wörter & 0xFFFF
 *   Lesen   : READ_TABLES → Antwort = großes Array aus uint16 (Big-Endian).
 *             Jedes Register liegt an einem festen Offset im Antwort-Array.
 *   Schreiben: WRITE_DATA mit (address, value)-Paaren.
 *
 * Register-Adressen → Offset hängen vom Datenmodell der Firmware ab. Das Modell
 * wird bevorzugt vom Gerät geladen (http://<host>/js/bundle.js bzw. js/vallox.js),
 * andernfalls dient das mitgelieferte Modell 2.0.16 (datamodel.json) als Fallback.
 *
 * Protokoll rekonstruiert aus yozik04/vallox_websocket_api und
 * danielbayerlein/vallox-api (beide getestet u. a. gegen ValloPlus 270 MV).
 *
 * ACHTUNG: Dieses Modul wurde noch NICHT gegen echte Hardware verifiziert
 * (Gerät folgt). Offsets/Schreibvorgänge vor Produktiveinsatz am Gerät prüfen.
 *
 * @author  FACE GmbH
 * @version 0.1
 * @see     API-FINDINGS.md
 */
class ValloxMV extends IPSModule
{
    // ─── Verbindung ─────────────────────────────────────────────────
    private const HTTP_TIMEOUT = 4;   // Sekunden (Modell-Download)
    private const WS_TIMEOUT   = 6;   // Sekunden (gesamte WS-Transaktion)
    private const WS_PORT      = 80;
    private const WS_PATH      = '/';

    // ─── Modul-Statuscodes ──────────────────────────────────────────
    private const STATUS_OK        = 102;
    private const STATUS_NO_HOST   = 200;
    private const STATUS_OFFLINE   = 201;
    private const STATUS_NO_MODEL  = 202;

    // ─── Profile (identisch zur Referenz-Enum) ──────────────────────
    private const PROFILE_NONE      = 0;
    private const PROFILE_HOME      = 1;
    private const PROFILE_AWAY      = 2;
    private const PROFILE_BOOST     = 3;
    private const PROFILE_FIREPLACE = 4;
    private const PROFILE_EXTRA     = 5;
    private const PROFILE_AUTO      = 6;

    private const RAW_INVALID = 0xFFFF;

    // ─── Anzeigetexte (DRY-Quelle für Profile UND Text-Variablen) ────
    // Werden sowohl für die Variablenprofil-Assoziationen als auch für die
    // String-Spiegel (…Text) verwendet, damit beides nie auseinanderläuft.
    private const TXT_PROFILE = [
        self::PROFILE_HOME      => 'Zuhause',
        self::PROFILE_AWAY      => 'Abwesend',
        self::PROFILE_BOOST     => 'Boost',
        self::PROFILE_FIREPLACE => 'Kamin',
        self::PROFILE_EXTRA     => 'Extra',
        self::PROFILE_AUTO      => 'Auto',
    ];
    private const TXT_CELL = [
        0 => 'Wärmerückgewinnung',
        1 => 'Kälterückgewinnung',
        2 => 'Bypass',
        3 => 'Abtauung',
    ];
    private const TXT_CO2LVL = [
        0 => 'Sehr gut',
        1 => 'Gut',
        2 => 'Mäßig',
        3 => 'Schlecht',
        4 => 'Sehr schlecht',
    ];
    private const TXT_HUMLVL = [
        0 => 'Optimal',
        1 => 'Etwas trocken',
        2 => 'Zu trocken',
        3 => 'Etwas feucht',
        4 => 'Zu feucht',
    ];

    // Schneller Poll-Burst nach einem Moduswechsel (Lüfter rampen verzögert hoch).
    // 3-s-Takt über ~2 Minuten, damit die rpm-Rampe komplett sichtbar wird.
    private const FASTPOLL_INTERVAL = 3000; // ms
    private const FASTPOLL_COUNT    = 40;   // zusätzliche Polls nach dem Sofort-Poll (≈120 s)

    /** Register, die als Statusvariablen gepflegt werden (Ident-Suffix ⇒ Register). */
    private const METRIC_MAP = [
        'TempOutdoor'     => 'A_CYC_TEMP_OUTDOOR_AIR',
        'TempSupply'      => 'A_CYC_TEMP_SUPPLY_AIR',
        'TempExtract'     => 'A_CYC_TEMP_EXTRACT_AIR',
        'TempExhaust'     => 'A_CYC_TEMP_EXHAUST_AIR',
        'Humidity'        => 'A_CYC_RH_VALUE',
        'CO2'             => 'A_CYC_CO2_VALUE',
        'FanSpeed'        => 'A_CYC_FAN_SPEED',
        'ExtractFanSpeed' => 'A_CYC_EXTR_FAN_SPEED',
        'SupplyFanSpeed'  => 'A_CYC_SUPP_FAN_SPEED',
        'CellState'       => 'A_CYC_CELL_STATE',
        'Defrosting'      => 'A_CYC_DEFROSTING',
        'BoostTimer'      => 'A_CYC_BOOST_TIMER',
        'FireplaceTimer'  => 'A_CYC_FIREPLACE_TIMER',
        'ExtraTimer'      => 'A_CYC_EXTRA_TIMER',
        'FilterDaysLeft'  => 'A_CYC_REMAINING_TIME_FOR_FILTER',
        'FaultCount'      => 'A_CYC_TOTAL_FAULT_COUNT',
        // Wirkungsgrad wird aus den Temperaturen berechnet (Register liefert 0xFFFF),
        // siehe ComputeEfficiency().
        'UpTimeTotal'       => 'A_CYC_TOTAL_UP_TIME_HOURS',
        'UpTimeCurrent'     => 'A_CYC_CURRENT_UP_TIME_HOURS',
        'Heater'            => 'A_CYC_IO_HEATER',
        'ExtraHeater'       => 'A_CYC_IO_EXTRA_HEATER',
        // Einstellbare Profil-Lüfterstufen (%)
        'HomeSpeed'       => 'A_CYC_HOME_SPEED_SETTING',
        'AwaySpeed'       => 'A_CYC_AWAY_SPEED_SETTING',
        'BoostSpeed'      => 'A_CYC_BOOST_SPEED_SETTING',
    ];

    /** In-Memory-Cache des aufgelösten Datenmodells (pro Request-Lauf). */
    private $model = null;

    // ═══════════════════════════════════════════════════════════════
    //  LIFECYCLE
    // ═══════════════════════════════════════════════════════════════

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyInteger('PollInterval', 60);
        $this->RegisterPropertyInteger('PowerVariableID', 0);

        // Aufgelöstes Modell (regs: name → {addr, offset}, ws, source). Leer = noch nicht ermittelt.
        $this->RegisterAttributeString('ResolvedModel', '');
        $this->RegisterAttributeInteger('FastPollRemaining', 0);

        $this->RegisterTimer('PollTimer', 0, 'VLX_Poll($_IPS["TARGET"]);');
        $this->RegisterTimer('FastPollTimer', 0, 'VLX_FastPoll($_IPS["TARGET"]);');
        $this->RegisterTimer('EnergyTimer', 0, 'VLX_UpdateEnergy($_IPS["TARGET"]);');

        $this->EnsureProfiles();

        // ── Statusvariablen ─────────────────────────────────────
        $this->RegisterVariableBoolean('Online', 'Online', $this->GetProfileName('Online'), 10);
        $this->RegisterVariableString('Model', 'Modell', '', 20);

        $this->RegisterVariableInteger('Profile', 'Profil', $this->GetProfileName('Profile'), 30);
        $this->EnableAction('Profile');

        $this->RegisterVariableFloat('TempOutdoor', 'Außenluft', '~Temperature', 40);
        $this->RegisterVariableFloat('TempSupply',  'Zuluft',    '~Temperature', 50);
        $this->RegisterVariableFloat('TempExtract', 'Abluft',    '~Temperature', 60);
        $this->RegisterVariableFloat('TempExhaust', 'Fortluft',  '~Temperature', 70);

        $this->RegisterVariableFloat('Humidity', 'Luftfeuchte', '~Humidity.F', 80);
        $this->RegisterVariableInteger('HumidityRating', 'Feuchte-Bewertung', $this->GetProfileName('HumLevel'), 85);
        $this->RegisterVariableInteger('CO2', 'CO₂', $this->GetProfileName('CO2'), 90);
        $this->RegisterVariableInteger('CO2Rating', 'Luftqualität (CO₂)', $this->GetProfileName('CO2Level'), 95);

        $this->RegisterVariableBoolean('ForcedActive', 'Zwangslüftung aktiv', $this->GetProfileName('Forced'), 96);
        $this->RegisterVariableString('ForcedReason', 'Grund Zwangslüftung', '', 97);
        $this->RegisterVariableInteger('ForcedSince', 'Zwangslüftung seit', '~UnixTimestamp', 98);

        $this->RegisterVariableInteger('FanSpeed',        'Lüfterstufe',                 $this->GetProfileName('Percent'), 100);
        $this->RegisterVariableInteger('ExtractFanSpeed', 'Abluftventilator (Drehzahl)', $this->GetProfileName('RPM'), 110);
        $this->RegisterVariableInteger('SupplyFanSpeed',  'Zuluftventilator (Drehzahl)', $this->GetProfileName('RPM'), 120);

        $this->RegisterVariableInteger('CellState',  'Wärmetauscher',  $this->GetProfileName('CellState'), 130);
        $this->RegisterVariableBoolean('Defrosting', 'Abtauung aktiv', '~Alert',                           140);

        $this->RegisterVariableInteger('SupplyEfficiency',  'Wirkungsgrad Zuluft', $this->GetProfileName('Percent'), 200);
        $this->RegisterVariableInteger('ExtractEfficiency', 'Wirkungsgrad Abluft', $this->GetProfileName('Percent'), 210);
        $this->RegisterVariableBoolean('Heater',      'Nachheizung',       '~Switch', 220);
        $this->RegisterVariableBoolean('ExtraHeater', 'Zusatz-Nachheizung', '~Switch', 230);
        $this->RegisterVariableInteger('UpTimeTotal',   'Betriebsstunden gesamt',  $this->GetProfileName('Hours'), 240);
        $this->RegisterVariableInteger('UpTimeCurrent', 'Betriebsstunden aktuell', $this->GetProfileName('Hours'), 250);

        // ── Einstellbare Profil-Werte ───────────────────────────
        $this->RegisterVariableInteger('HomeSpeed',  'Home – Lüfterstufe',  $this->GetProfileName('Percent'), 300);
        $this->RegisterVariableInteger('AwaySpeed',  'Away – Lüfterstufe',  $this->GetProfileName('Percent'), 310);
        $this->RegisterVariableInteger('BoostSpeed', 'Boost – Lüfterstufe', $this->GetProfileName('Percent'), 320);
        $this->EnableAction('HomeSpeed');
        $this->EnableAction('AwaySpeed');
        $this->EnableAction('BoostSpeed');

        $this->RegisterVariableInteger('BoostTimer',     'Boost verbleibend',     $this->GetProfileName('Minutes'), 150);
        $this->RegisterVariableInteger('FireplaceTimer', 'Kamin verbleibend',     $this->GetProfileName('Minutes'), 160);
        $this->RegisterVariableInteger('ExtraTimer',     'Extra verbleibend',     $this->GetProfileName('Minutes'), 170);

        $this->RegisterVariableInteger('FilterDaysLeft', 'Filterwechsel in', $this->GetProfileName('Days'), 180);
        $this->RegisterVariableInteger('FaultCount',     'Aktive Störungen', '',                            190);

        // ── Anzeigetexte (read-only Spiegel für Visualisierungen/IPSView) ──
        // IPSView stellt Strings immer korrekt dar, unabhängig davon, ob es
        // instanzeigene Variablenprofile auflösen kann.
        $this->RegisterVariableString('BetriebsartText',    'Betriebsart',    '', 400);
        $this->RegisterVariableString('LuftqualitaetText',  'Luftqualität',   '', 410);
        $this->RegisterVariableString('WaermetauscherText', 'Wärmetauscher',  '', 420);
        $this->RegisterVariableString('AbtauungText',       'Abtauung',       '', 430);
        $this->RegisterVariableString('NachheizungText',    'Nachheizung',    '', 440);
        $this->RegisterVariableString('ZwangslueftungText', 'Zwangslüftung',  '', 450);
        $this->RegisterVariableString('CO2Text',            'CO₂ (Text)',     '', 460);
        $this->RegisterVariableString('FeuchteText',        'Feuchte (Text)', '', 470);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // Verbrauchsvariable spiegeln (unabhängig von der Geräteverbindung).
        $this->SetupPowerMirror();

        if (IPS_GetKernelRunlevel() == KR_READY) {
            $this->UpdateEnergy();
        }

        $host = trim($this->ReadPropertyString('Host'));
        if ($host === '') {
            $this->SetStatus(self::STATUS_NO_HOST);
            $this->SetTimerInterval('PollTimer', 0);
            return;
        }

        $interval = $this->ReadPropertyInteger('PollInterval');
        $this->SetTimerInterval('PollTimer', $interval > 0 ? $interval * 1000 : 0);

        if (IPS_GetKernelRunlevel() == KR_READY) {
            $this->RequestInfo();
            $this->Poll();
        }
    }

    public function Destroy()
    {
        $prefix = 'VLX.' . $this->InstanceID . '.';
        foreach (IPS_GetVariableProfileList() as $p) {
            if (strpos($p, $prefix) === 0) {
                @IPS_DeleteVariableProfile($p);
            }
        }
        parent::Destroy();
    }

    // ═══════════════════════════════════════════════════════════════
    //  PUBLIC API (Prefix-Funktionen)
    // ═══════════════════════════════════════════════════════════════

    /**
     * Alle Register lesen und die Statusvariablen aktualisieren.
     */
    public function Poll(): bool
    {
        $raw = $this->FetchRawMetrics();
        if ($raw === false) {
            $this->SetOffline();
            return false;
        }

        $this->SetValueIfChanged('Online', true);
        $this->SetStatus(self::STATUS_OK);
        $this->ApplyMetrics($raw);
        return true;
    }

    /**
     * Ein Poll aus dem Schnell-Burst (nach Moduswechsel). Zählt runter und
     * stoppt den Burst-Timer, sobald keine weiteren Läufe mehr offen sind.
     */
    public function FastPoll(): void
    {
        $this->Poll();
        $remaining = $this->ReadAttributeInteger('FastPollRemaining') - 1;
        $this->WriteAttributeInteger('FastPollRemaining', max(0, $remaining));
        if ($remaining <= 0) {
            $this->SetTimerInterval('FastPollTimer', 0);
        }
    }

    /**
     * Startet nach einem Modus-/Stufenwechsel einen kurzen Poll-Burst, damit die
     * hochlaufenden Lüfterdrehzahlen (rpm) zeitnah sichtbar werden.
     */
    private function TriggerFastPoll(): void
    {
        $this->Poll(); // sofort
        $this->WriteAttributeInteger('FastPollRemaining', self::FASTPOLL_COUNT);
        $this->SetTimerInterval('FastPollTimer', self::FASTPOLL_INTERVAL);
    }

    /**
     * Datenmodell (Register-Offsets) neu ermitteln – bevorzugt vom Gerät,
     * sonst aus dem mitgelieferten Modell. Aktualisiert außerdem den Modellnamen.
     */
    public function RequestInfo(): bool
    {
        $resolved = $this->ResolveModel(true);
        if ($resolved === false) {
            $this->SetStatus(self::STATUS_NO_MODEL);
            return false;
        }
        // Modellname aus A_CYC_MACHINE_MODEL nachziehen (best effort).
        $raw = $this->FetchRawMetrics();
        if (is_array($raw) && isset($raw['A_CYC_MACHINE_MODEL'])) {
            $this->UpdateModelName((int)$raw['A_CYC_MACHINE_MODEL']);
        }
        return true;
    }

    /**
     * Betriebsprofil setzen.
     *
     * @param int $Profile  1=Home, 2=Away, 3=Boost, 4=Fireplace, 5=Extra, 6=Auto
     * @param int $Duration Timeout in Minuten für Boost/Fireplace/Extra
     *                      (0 = Standardeinstellung des Geräts verwenden).
     */
    public function SetProfile(int $Profile, int $Duration = 0): bool
    {
        $reg = $this->Regs();
        if ($reg === false) {
            return false;
        }

        $needName = function (string $name) use ($reg): bool {
            return isset($reg[$name]);
        };

        // Für Boost/Fireplace/Extra ggf. Standarddauer vom Gerät holen.
        $dur = $Duration;
        if ($dur <= 0 && in_array($Profile, [self::PROFILE_BOOST, self::PROFILE_FIREPLACE, self::PROFILE_EXTRA], true)) {
            $defReg = [
                self::PROFILE_BOOST     => 'A_CYC_BOOST_TIME',
                self::PROFILE_FIREPLACE => 'A_CYC_FIREPLACE_TIME',
                self::PROFILE_EXTRA     => 'A_CYC_EXTRA_TIME',
            ][$Profile];
            $raw = $this->FetchRawMetrics();
            $dur = (is_array($raw) && isset($raw[$defReg])) ? (int)$raw[$defReg] : 30;
        }

        switch ($Profile) {
            case self::PROFILE_HOME:
                $writes = ['A_CYC_STATE' => 0, 'A_CYC_BOOST_TIMER' => 0, 'A_CYC_FIREPLACE_TIMER' => 0, 'A_CYC_EXTRA_TIMER' => 0];
                break;
            case self::PROFILE_AWAY:
                $writes = ['A_CYC_STATE' => 1, 'A_CYC_BOOST_TIMER' => 0, 'A_CYC_FIREPLACE_TIMER' => 0, 'A_CYC_EXTRA_TIMER' => 0];
                break;
            case self::PROFILE_AUTO:
                $writes = ['A_CYC_STATE' => 2, 'A_CYC_BOOST_TIMER' => 0, 'A_CYC_FIREPLACE_TIMER' => 0, 'A_CYC_EXTRA_TIMER' => 0];
                break;
            case self::PROFILE_BOOST:
                $writes = ['A_CYC_BOOST_TIMER' => $dur, 'A_CYC_FIREPLACE_TIMER' => 0, 'A_CYC_EXTRA_TIMER' => 0];
                break;
            case self::PROFILE_FIREPLACE:
                $writes = ['A_CYC_BOOST_TIMER' => 0, 'A_CYC_FIREPLACE_TIMER' => $dur, 'A_CYC_EXTRA_TIMER' => 0];
                break;
            case self::PROFILE_EXTRA:
                $writes = ['A_CYC_BOOST_TIMER' => 0, 'A_CYC_FIREPLACE_TIMER' => 0, 'A_CYC_EXTRA_TIMER' => $dur];
                break;
            default:
                $this->SendDebug(__FUNCTION__, 'Unbekanntes Profil: ' . $Profile, 0);
                return false;
        }

        // Nur tatsächlich vorhandene Register schreiben.
        $writes = array_filter($writes, fn($k) => $needName($k), ARRAY_FILTER_USE_KEY);
        if (count($writes) === 0) {
            return false;
        }

        $ok = $this->WriteRegisters($writes);
        if ($ok) {
            $this->TriggerFastPoll();
        }
        return $ok;
    }

    /**
     * Jahresverbrauch als Hochrechnung aus der gemessenen Leistung aktualisieren.
     *
     * Kein Zähler: gebildet wird der zeitgewichtete Mittelwert der archivierten
     * Leistung der verknüpften Messvariable (max. 365 Tage) und daraus
     *   kWh/Jahr = Ø Leistung [W] × 8760 h / 1000
     * Solange noch keine Tageswerte vorliegen, dienen Stundenwerte als Basis,
     * ganz am Anfang der Momentanwert.
     */
    public function UpdateEnergy(): bool
    {
        $src = $this->ReadPropertyInteger('PowerVariableID');
        if ($src <= 0 || !IPS_VariableExists($src) || !@$this->GetIDForIdent('AnnualEnergy')) {
            return false;
        }
        $archive = $this->GetArchiveID();
        if ($archive === 0) {
            $this->SendDebug(__FUNCTION__, 'Kein Archive-Handler vorhanden', 0);
            return false;
        }

        $now = time();

        // 1) Tages-Aggregate über maximal ein Jahr (Stufe 1 = täglich).
        [$avg, $dur] = $this->AggregateMean($archive, $src, 1, $now - 365 * 86400, $now);

        // 2) Zu wenig Historie -> Stunden-Aggregate der letzten Woche (Stufe 0).
        if ($dur < 3600) {
            [$avg, $dur] = $this->AggregateMean($archive, $src, 0, $now - 7 * 86400, $now);
        }

        if ($dur > 0) {
            $basis = $dur < 86400
                ? sprintf('Ø über %.1f Stunden', $dur / 3600)
                : sprintf('Ø über %.1f Tage', $dur / 86400);
        } else {
            // 3) Fallback: Momentanwert (direkt nach Aktivierung der Archivierung).
            $avg   = (float)GetValue($src);
            $basis = 'Momentanwert (noch keine Historie)';
        }

        $this->SetValueIfChanged('AvgPower', round($avg, 1));
        $this->SetValueIfChanged('AnnualEnergy', round($avg * 8760 / 1000, 1));
        $this->SetValueIfChanged('EnergyBasis', sprintf('%.1f W · %s', $avg, $basis));
        return true;
    }

    /**
     * Zeitgewichteter Mittelwert aus Archiv-Aggregaten.
     *
     * @return array{0:float,1:int} [Mittelwert, gewichtete Gesamtdauer in Sekunden]
     */
    private function AggregateMean(int $archive, int $varID, int $stage, int $from, int $to): array
    {
        $sum = 0.0;
        $dur = 0;
        $rows = @AC_GetAggregatedValues($archive, $varID, $stage, $from, $to, 0);
        if (is_array($rows)) {
            foreach ($rows as $r) {
                $d = (int)($r['Duration'] ?? 0);
                if ($d > 0) {
                    $sum += (float)$r['Avg'] * $d;
                    $dur += $d;
                }
            }
        }
        return [$dur > 0 ? $sum / $dur : 0.0, $dur];
    }

    /**
     * Lüfterstufe (%) eines Profils setzen (schreibt A_CYC_<PROFIL>_SPEED_SETTING).
     * Praktisch für Skripte/Automationen (z. B. saisonale Zuhause-Anpassung).
     *
     * @param int $Profile 1=Home, 2=Away, 3=Boost
     * @param int $Percent 0–100
     */
    public function SetProfileSpeed(int $Profile, int $Percent): bool
    {
        $map = [
            self::PROFILE_HOME  => ['A_CYC_HOME_SPEED_SETTING', 'HomeSpeed'],
            self::PROFILE_AWAY  => ['A_CYC_AWAY_SPEED_SETTING', 'AwaySpeed'],
            self::PROFILE_BOOST => ['A_CYC_BOOST_SPEED_SETTING', 'BoostSpeed'],
        ];
        if (!isset($map[$Profile])) {
            $this->SendDebug(__FUNCTION__, 'Profil ohne feste Stufe: ' . $Profile, 0);
            return false;
        }
        [$reg, $ident] = $map[$Profile];
        $pct = max(0, min(100, $Percent));
        $ok = $this->WriteRegisters([$reg => $pct]);
        if ($ok) {
            $this->SetValueIfChanged($ident, $pct);
        }
        return $ok;
    }

    /**
     * Ein einzelnes Register anhand seines Namens lesen (Debug/Test-Center).
     * @return string
     */
    public function ReadRegisterByName(string $Name): string
    {
        $Name = trim($Name);
        if ($Name === '') {
            return 'ERROR: leerer Name';
        }
        $reg = $this->Regs();
        if ($reg === false) {
            return 'ERROR: kein Datenmodell';
        }
        if (!isset($reg[$Name])) {
            return 'ERROR: unbekanntes Register (nicht im kuratierten Modell): ' . $Name;
        }
        $raw = $this->FetchRawMetrics();
        if (!is_array($raw) || !array_key_exists($Name, $raw)) {
            return 'ERROR: keine Antwort';
        }
        $v = $raw[$Name];
        return sprintf('%s = %d (addr=%d, offset=%d)', $Name, (int)$v, $reg[$Name]['addr'], $reg[$Name]['offset']);
    }

    // ═══════════════════════════════════════════════════════════════
    //  REQUEST ACTION (UI)
    // ═══════════════════════════════════════════════════════════════

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'Profile':
                $this->SetProfile((int)$Value);
                break;

            case 'HomeSpeed':
            case 'AwaySpeed':
            case 'BoostSpeed':
                $map = [
                    'HomeSpeed'  => 'A_CYC_HOME_SPEED_SETTING',
                    'AwaySpeed'  => 'A_CYC_AWAY_SPEED_SETTING',
                    'BoostSpeed' => 'A_CYC_BOOST_SPEED_SETTING',
                ];
                $val = max(0, min(100, (int)$Value));
                if ($this->WriteRegisters([$map[$Ident] => $val])) {
                    $this->SetValue($Ident, $val);
                    $this->TriggerFastPoll();
                }
                break;

            default:
                $this->SendDebug(__FUNCTION__, 'Unbekannter Ident: ' . $Ident, 0);
        }
    }

    /**
     * Reagiert auf Änderungen der verknüpften Verbrauchsvariable.
     */
    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message == VM_UPDATE && $SenderID == $this->ReadPropertyInteger('PowerVariableID')) {
            $this->UpdatePowerMirror();
        }
    }

    // ═══════════════════════════════════════════════════════════════
    //  PRIVATE: Verbrauchsvariable spiegeln
    // ═══════════════════════════════════════════════════════════════

    /**
     * Legt die gespiegelte Variable "Verbrauch" an (Typ/Profil von der Quelle),
     * registriert die Nachrichtenverfolgung neu und zieht den aktuellen Wert.
     */
    private function SetupPowerMirror(): void
    {
        // Alte VM_UPDATE-Registrierungen entfernen (Neuaufbau bei jedem ApplyChanges).
        foreach ($this->GetMessageList() as $senderID => $messages) {
            foreach ($messages as $msg) {
                if ($msg == VM_UPDATE) {
                    $this->UnregisterMessage($senderID, VM_UPDATE);
                }
            }
        }

        $id = $this->ReadPropertyInteger('PowerVariableID');
        if ($id > 0 && IPS_VariableExists($id)) {
            $src     = IPS_GetVariable($id);
            $type    = (int)$src['VariableType'];
            $profile = $src['VariableCustomProfile'] !== '' ? $src['VariableCustomProfile'] : $src['VariableProfile'];

            $this->MaintainVariable('PowerConsumption', 'Verbrauch', $type, $profile, 195, true);
            $this->RegisterMessage($id, VM_UPDATE);
            $this->UpdatePowerMirror();

            // Energie-Hochrechnung: Variablen anlegen, Archivierung der Quelle
            // sicherstellen (ohne Historie ist kein Durchschnitt möglich) und Timer starten.
            $this->MaintainVariable('AvgPower', 'Ø Leistung', VARIABLETYPE_FLOAT, $this->GetProfileName('Watt'), 196, true);
            $this->MaintainVariable('AnnualEnergy', 'Jahresverbrauch (Prognose)', VARIABLETYPE_FLOAT, $this->GetProfileName('kWh'), 197, true);
            $this->MaintainVariable('EnergyBasis', 'Prognose-Basis', VARIABLETYPE_STRING, '', 198, true);

            $archive = $this->GetArchiveID();
            if ($archive > 0 && !AC_GetLoggingStatus($archive, $id)) {
                AC_SetLoggingStatus($archive, $id, true);
                $this->SendDebug('Energie', 'Archivierung für Quellvariable #' . $id . ' aktiviert', 0);
            }
            $this->SetTimerInterval('EnergyTimer', 3600 * 1000); // stündlich
        } else {
            // Verknüpfung entfernt → Variablen wieder abbauen.
            $this->MaintainVariable('PowerConsumption', 'Verbrauch', VARIABLETYPE_FLOAT, '', 195, false);
            $this->MaintainVariable('AvgPower', 'Ø Leistung', VARIABLETYPE_FLOAT, '', 196, false);
            $this->MaintainVariable('AnnualEnergy', 'Jahresverbrauch (Prognose)', VARIABLETYPE_FLOAT, '', 197, false);
            $this->MaintainVariable('EnergyBasis', 'Prognose-Basis', VARIABLETYPE_STRING, '', 198, false);
            $this->SetTimerInterval('EnergyTimer', 0);
        }
    }

    /**
     * Instanz-ID des Archive-Handlers (oder 0, wenn keiner vorhanden).
     */
    private function GetArchiveID(): int
    {
        $ids = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}');
        return count($ids) > 0 ? (int)$ids[0] : 0;
    }

    /**
     * Aktuellen Wert der Quellvariable in die gespiegelte Variable übernehmen.
     */
    private function UpdatePowerMirror(): void
    {
        $id = $this->ReadPropertyInteger('PowerVariableID');
        if ($id > 0 && IPS_VariableExists($id) && @$this->GetIDForIdent('PowerConsumption')) {
            $this->SetValueIfChanged('PowerConsumption', GetValue($id));
        }
    }

    // ═══════════════════════════════════════════════════════════════
    //  PRIVATE: Metrik-Verarbeitung
    // ═══════════════════════════════════════════════════════════════

    /**
     * READ_TABLES ausführen und alle kuratierten Register als Rohwerte (name → int)
     * zurückgeben. 0xFFFF wird als "ungültig" durchgereicht (Filterung erfolgt später).
     * @return array<string,int>|false
     */
    private function FetchRawMetrics()
    {
        $reg = $this->Regs();
        if ($reg === false) {
            return false;
        }

        $payload = $this->BuildReadTableRequest();
        $frames  = $this->WsTransact([$payload], 1);
        if ($frames === false || count($frames) === 0) {
            return false;
        }

        $words = $this->ParseUint16BE($frames[0]);
        $count = count($words);
        if ($count === 0) {
            return false;
        }

        $raw = [];
        foreach ($reg as $name => $info) {
            $off = $info['offset'];
            if ($off >= 0 && $off < $count) {
                $raw[$name] = $words[$off];
            }
        }
        return $raw;
    }

    /**
     * Rohwerte in Statusvariablen umsetzen (inkl. Einheiten-Konvertierung).
     * @param array<string,int> $raw
     */
    private function ApplyMetrics(array $raw): void
    {
        if (isset($raw['A_CYC_MACHINE_MODEL'])) {
            $this->UpdateModelName((int)$raw['A_CYC_MACHINE_MODEL']);
        }

        foreach (self::METRIC_MAP as $ident => $regName) {
            if (!array_key_exists($regName, $raw)) {
                continue;
            }
            $v = (int)$raw[$regName];

            switch ($ident) {
                case 'TempOutdoor':
                case 'TempSupply':
                case 'TempExtract':
                case 'TempExhaust':
                    $c = $this->ToCelsius($v);
                    if ($c !== null) {
                        $this->SetValueIfChanged($ident, $c);
                    }
                    break;

                case 'Defrosting':
                case 'Heater':
                case 'ExtraHeater':
                    if ($v !== self::RAW_INVALID) {
                        $this->SetValueIfChanged($ident, $v > 0);
                    }
                    break;

                default: // Prozent, ppm, Minuten, Tage, Zähler, CellState
                    if ($v !== self::RAW_INVALID) {
                        $this->SetValueIfChanged($ident, $v);
                    }
            }
        }

        $this->SetValueIfChanged('Profile', $this->DeriveProfile($raw));
        $this->ComputeEfficiency($raw);
        $this->RateCO2($raw);
        $this->RateHumidity($raw);
        $this->EvaluateForcedVentilation($raw);
        $this->UpdateTextMirrors($raw);
    }

    /**
     * Schreibt die String-Spiegel der bewerteten/aufgezählten Werte.
     * Muss nach den Bewertungs- und Auswertungsmethoden laufen, da es deren
     * Ergebnisse aus den Statusvariablen übernimmt.
     *
     * @param array<string,int> $raw
     */
    private function UpdateTextMirrors(array $raw): void
    {
        $val = function (string $ident) {
            $vid = @$this->GetIDForIdent($ident);
            return $vid ? GetValue($vid) : null;
        };

        $profile = $val('Profile');
        $this->SetValueIfChanged('BetriebsartText', self::TXT_PROFILE[$profile] ?? '—');

        $cell = $val('CellState');
        $this->SetValueIfChanged('WaermetauscherText', self::TXT_CELL[$cell] ?? '—');

        // CO₂: nur wenn ein Sensor plausible Werte liefert.
        $co2 = (int)($raw['A_CYC_CO2_VALUE'] ?? self::RAW_INVALID);
        if ($co2 !== self::RAW_INVALID && $co2 > 0) {
            $this->SetValueIfChanged('CO2Text', sprintf('%d ppm', $co2));
            $this->SetValueIfChanged('LuftqualitaetText', self::TXT_CO2LVL[$val('CO2Rating')] ?? '—');
        } else {
            $this->SetValueIfChanged('CO2Text', '—');
            $this->SetValueIfChanged('LuftqualitaetText', '—');
        }

        $rh = (int)($raw['A_CYC_RH_VALUE'] ?? self::RAW_INVALID);
        if ($rh !== self::RAW_INVALID && $rh > 0) {
            $this->SetValueIfChanged('FeuchteText', sprintf('%d %% – %s', $rh, self::TXT_HUMLVL[$val('HumidityRating')] ?? '?'));
        } else {
            $this->SetValueIfChanged('FeuchteText', '—');
        }

        $this->SetValueIfChanged('AbtauungText',       $val('Defrosting') ? 'Aktiv' : 'Aus');
        $this->SetValueIfChanged('NachheizungText',    $val('Heater') ? 'An' : 'Aus');
        $forced = (bool)$val('ForcedActive');
        $reason = (string)$val('ForcedReason');
        $this->SetValueIfChanged(
            'ZwangslueftungText',
            $forced ? ('Ja' . ($reason !== '' && $reason !== '—' ? ' (' . $reason . ')' : '')) : 'Nein'
        );
    }

    /**
     * Luftfeuchte in eine Bewertung einordnen (Zielkorridor 40–55 %).
     *
     * @param array<string,int> $raw
     */
    private function RateHumidity(array $raw): void
    {
        if (!array_key_exists('A_CYC_RH_VALUE', $raw)) {
            return;
        }
        $rh = (int)$raw['A_CYC_RH_VALUE'];
        if ($rh === self::RAW_INVALID || $rh <= 0) {
            return; // kein Sensor / ungültig
        }

        if ($rh < 35) {
            $level = 2; // Zu trocken
        } elseif ($rh < 40) {
            $level = 1; // Etwas trocken
        } elseif ($rh <= 55) {
            $level = 0; // Optimal
        } elseif ($rh <= 60) {
            $level = 3; // Etwas feucht
        } else {
            $level = 4; // Zu feucht
        }
        $this->SetValueIfChanged('HumidityRating', $level);
    }

    /**
     * Erkennt, ob die Anlage über ihre Profil-Grundstufe hinaus hochgefahren ist
     * (Zwangslüftung durch CO₂-/Feuchte-Automatik), ermittelt den Grund und
     * protokolliert das Ereignis beim Einsetzen ins Symcon-Log.
     *
     * @param array<string,int> $raw
     */
    private function EvaluateForcedVentilation(array $raw): void
    {
        $fan   = (int)($raw['A_CYC_FAN_SPEED'] ?? 0);
        $state = $raw['A_CYC_STATE'] ?? null;

        // Grundstufe des aktiven Profils (Home/Away). Bei Auto gibt es keine feste Basis.
        $base = null;
        if ($state === 0) {
            $base = $raw['A_CYC_HOME_SPEED_SETTING'] ?? null;
        } elseif ($state === 1) {
            $base = $raw['A_CYC_AWAY_SPEED_SETTING'] ?? null;
        }

        // Läuft ein Zeitprofil (Boost/Kamin/Extra), erklärt das die erhöhte Drehzahl:
        // A_CYC_STATE bleibt dabei auf Home/Away, deshalb sonst falsch-positiv.
        $timed = ((int)($raw['A_CYC_BOOST_TIMER'] ?? 0) > 0)
              || ((int)($raw['A_CYC_FIREPLACE_TIMER'] ?? 0) > 0)
              || ((int)($raw['A_CYC_EXTRA_TIMER'] ?? 0) > 0);

        $active = ($base !== null) && !$timed && ($fan > (int)$base + 5); // 5 % Toleranz

        // Grund ermitteln (CO₂-Schwelle / Feuchte über Basislevel).
        // Das Gerät führt A_CYC_RH_BASIC_LEVEL als gleitende Basis nach, deshalb
        // braucht es einen echten Abstand statt nur ">=".
        $reasons = [];
        $co2 = (int)($raw['A_CYC_CO2_VALUE'] ?? self::RAW_INVALID);
        $thr = (int)($raw['A_CYC_CO2_THRESHOLD'] ?? 0);
        if ($co2 !== self::RAW_INVALID && $co2 > 0 && $thr > 0 && $co2 >= $thr) {
            $reasons[] = 'CO₂';
        }
        $rh   = (int)($raw['A_CYC_RH_VALUE'] ?? self::RAW_INVALID);
        $rhb  = (int)($raw['A_CYC_RH_BASIC_LEVEL'] ?? 0);
        if ($rh !== self::RAW_INVALID && $rh > 0 && $rhb > 0 && $rh >= $rhb + 2) {
            $reasons[] = 'Feuchte';
        }
        $reasonStr = $active ? ($reasons ? implode(' + ', $reasons) : 'unbekannt') : '—';

        $was = (bool)@GetValue(@$this->GetIDForIdent('ForcedActive'));

        $this->SetValueIfChanged('ForcedActive', $active);
        $this->SetValueIfChanged('ForcedReason', $reasonStr);

        if ($active && !$was) {
            // Beginn einer Zwangslüftung -> Zeitpunkt + Log-Eintrag
            $this->SetValueIfChanged('ForcedSince', time());
            $this->LogMessage(sprintf(
                'Zwangslüftung AN – Grund: %s (Lüfter %d%% > Basis %d%%, CO₂ %s ppm/Schwelle %d, Feuchte %s%%/Basis %d)',
                $reasonStr, $fan, (int)$base,
                $co2 === self::RAW_INVALID ? 'n/a' : $co2, $thr,
                $rh === self::RAW_INVALID ? 'n/a' : $rh, $rhb
            ), KL_MESSAGE);
        } elseif (!$active && $was) {
            $this->LogMessage(sprintf('Zwangslüftung AUS (Lüfter %d%% zurück auf Basis %d%%)', $fan, (int)$base), KL_MESSAGE);
        }
    }

    /**
     * CO₂-Wert (ppm) in eine Luftqualitäts-Stufe (0–4) einordnen.
     * Schwellen nach gängiger Innenraumbewertung (Pettenkofer/UBA).
     *
     * @param array<string,int> $raw
     */
    private function RateCO2(array $raw): void
    {
        if (!array_key_exists('A_CYC_CO2_VALUE', $raw)) {
            return;
        }
        $ppm = (int)$raw['A_CYC_CO2_VALUE'];
        if ($ppm === self::RAW_INVALID || $ppm <= 0) {
            return; // kein CO₂-Sensor / ungültig
        }

        if ($ppm <= 800) {
            $level = 0; // Sehr gut
        } elseif ($ppm <= 1000) {
            $level = 1; // Gut
        } elseif ($ppm <= 1400) {
            $level = 2; // Mäßig
        } elseif ($ppm <= 2000) {
            $level = 3; // Schlecht
        } else {
            $level = 4; // Sehr schlecht
        }
        $this->SetValueIfChanged('CO2Rating', $level);
    }

    /**
     * Wärmerückgewinnungs-Wirkungsgrad aus den Temperaturen berechnen (wie MyVallox),
     * da das Gerät die Wirkungsgrad-Register nicht füllt (0xFFFF).
     *
     *   η_Zuluft = (Zuluft − Außen) / (Abluft − Außen)
     *   η_Abluft = (Abluft − Fortluft) / (Abluft − Außen)
     *
     * Nur sinnvoll im Rückgewinnungsbetrieb (Zelle = Wärmerückgewinnung) und bei
     * ausreichender Temperaturdifferenz; sonst wird der letzte Wert beibehalten.
     *
     * @param array<string,int> $raw
     */
    private function ComputeEfficiency(array $raw): void
    {
        $to = $this->ToCelsius((int)($raw['A_CYC_TEMP_OUTDOOR_AIR'] ?? 0));
        $ts = $this->ToCelsius((int)($raw['A_CYC_TEMP_SUPPLY_AIR']  ?? 0));
        $te = $this->ToCelsius((int)($raw['A_CYC_TEMP_EXTRACT_AIR'] ?? 0));
        $tx = $this->ToCelsius((int)($raw['A_CYC_TEMP_EXHAUST_AIR'] ?? 0));
        if ($to === null || $ts === null || $te === null || $tx === null) {
            return;
        }

        $denom = $te - $to;
        $cell  = $raw['A_CYC_CELL_STATE'] ?? null; // 0 = Wärmerückgewinnung
        // Mindest-ΔT von 3 K vermeidet unsinnige Werte bei kleinen Differenzen/Bypass.
        if ($cell !== 0 || $denom < 3.0) {
            return;
        }

        $clamp = fn(float $v): int => (int)max(0, min(100, round($v)));
        $this->SetValueIfChanged('SupplyEfficiency',  $clamp(($ts - $to) / $denom * 100));
        $this->SetValueIfChanged('ExtractEfficiency', $clamp(($te - $tx) / $denom * 100));
    }

    /**
     * Aktuelles Profil aus den Rohwerten ableiten (Logik der Referenz-Lib).
     * @param array<string,int> $raw
     */
    private function DeriveProfile(array $raw): int
    {
        if ((int)($raw['A_CYC_BOOST_TIMER'] ?? 0) > 0) {
            return self::PROFILE_BOOST;
        }
        if ((int)($raw['A_CYC_FIREPLACE_TIMER'] ?? 0) > 0) {
            return self::PROFILE_FIREPLACE;
        }
        if ((int)($raw['A_CYC_EXTRA_TIMER'] ?? 0) > 0) {
            return self::PROFILE_EXTRA;
        }
        $state = $raw['A_CYC_STATE'] ?? null;
        if ($state === 0) {
            return self::PROFILE_HOME;
        }
        if ($state === 1) {
            return self::PROFILE_AWAY;
        }
        if ($state === 2) {
            return self::PROFILE_AUTO;
        }
        return self::PROFILE_NONE;
    }

    private function ToCelsius(int $raw): ?float
    {
        if ($raw === 0 || $raw === self::RAW_INVALID) {
            return null;
        }
        return round($raw / 100.0 - 273.15, 1);
    }


    private function UpdateModelName(int $index): void
    {
        $model = $this->LoadBundledModel();
        $list  = $model['deviceModels'] ?? [];
        $name  = $list[$index] ?? null;
        if (is_string($name) && $name !== '') {
            $this->SetValueIfChanged('Model', $name);
            $this->SetSummary($name);
        }
    }

    private function SetOffline(): void
    {
        $this->SetValueIfChanged('Online', false);
        if ($this->GetStatus() != self::STATUS_OFFLINE) {
            $this->LogMessage('Vallox ' . $this->ReadPropertyString('Host') . ' nicht erreichbar', KL_WARNING);
        }
        $this->SetStatus(self::STATUS_OFFLINE);
    }

    // ═══════════════════════════════════════════════════════════════
    //  PRIVATE: Datenmodell (Register → Offset)
    // ═══════════════════════════════════════════════════════════════

    /**
     * Liefert die aufgelöste Register-Map (name → {addr, offset}) oder false.
     */
    private function Regs()
    {
        $resolved = $this->ResolveModel(false);
        if ($resolved === false) {
            return false;
        }
        return $resolved['regs'];
    }

    /**
     * Aufgelöstes Modell zurückgeben. Bei $forceReload oder leerem Attribut wird
     * es neu ermittelt (Gerät bevorzugt, sonst Bundle) und im Attribut gecacht.
     * @return array{regs:array,ws:array,source:string}|false
     */
    private function ResolveModel(bool $forceReload)
    {
        if (!$forceReload) {
            $cached = $this->ReadAttributeString('ResolvedModel');
            if ($cached !== '') {
                $dec = json_decode($cached, true);
                if (is_array($dec) && isset($dec['regs'], $dec['ws'])) {
                    return $dec;
                }
            }
        }

        // 1) Versuche, das Modell vom Gerät zu laden.
        $resolved = $this->ResolveFromUnit();

        // 2) Fallback: mitgeliefertes Modell.
        if ($resolved === false) {
            $resolved = $this->ResolveFromBundle();
        }

        if ($resolved !== false) {
            $this->WriteAttributeString('ResolvedModel', json_encode($resolved));
        }
        return $resolved;
    }

    /**
     * Modell aus dem mitgelieferten datamodel.json aufbauen.
     * @return array|false
     */
    private function ResolveFromBundle()
    {
        $model = $this->LoadBundledModel();
        if ($model === false) {
            return false;
        }
        $regs = $this->ComputeOffsets($model['ranges'], $model['registers']);
        return ['regs' => $regs, 'ws' => $model['ws'], 'source' => 'bundle ' . ($model['sourceVersion'] ?? '?')];
    }

    /**
     * Modell direkt vom Gerät laden (js/bundle.js bzw. js/vallox.js) und parsen.
     * @return array|false
     */
    private function ResolveFromUnit()
    {
        $host = trim($this->ReadPropertyString('Host'));
        if ($host === '') {
            return false;
        }
        $js = false;
        foreach (['js/bundle.js', 'js/vallox.js'] as $path) {
            $js = $this->HttpGet('http://' . $host . '/' . $path);
            if ($js !== false && strpos($js, 'VlxDevConstants') !== false) {
                break;
            }
            $js = false;
        }
        if ($js === false) {
            return false;
        }

        $constants = $this->ParseConstants($js);
        $dev  = $constants['VlxDevConstants'] ?? [];
        $read = $constants['VlxReadConstants'] ?? [];
        if (!isset($dev['WS_WEB_UI_COMMAND_READ_TABLES'])) {
            return false;
        }

        $ranges = $this->BuildRangesFromConstants($dev, $read);
        if (count($ranges) === 0) {
            return false;
        }

        // Adressen der kuratierten Register aus dem Geräte-Modell ziehen.
        $bundle    = $this->LoadBundledModel();
        $regNames  = array_keys($bundle['registers'] ?? []);
        $registers = [];
        foreach ($regNames as $name) {
            if (isset($dev[$name])) {
                $registers[$name] = (int)$dev[$name];
            }
        }
        // A_CYC_MACHINE_MODEL zusätzlich sicherstellen.
        if (isset($dev['A_CYC_MACHINE_MODEL'])) {
            $registers['A_CYC_MACHINE_MODEL'] = (int)$dev['A_CYC_MACHINE_MODEL'];
        }

        $ws = [
            'READ_TABLES' => (int)$dev['WS_WEB_UI_COMMAND_READ_TABLES'],
            'WRITE_DATA'  => (int)$dev['WS_WEB_UI_COMMAND_WRITE_DATA'],
            'READ_DATA'   => (int)($dev['WS_WEB_UI_COMMAND_READ_DATA'] ?? 250),
        ];

        $regs = $this->ComputeOffsets($ranges, $registers);
        $this->SendDebug('Modell', 'Vom Gerät geladen, ' . count($regs) . ' Register aufgelöst', 0);
        return ['regs' => $regs, 'ws' => $ws, 'source' => 'unit'];
    }

    /**
     * Register-Adressen anhand der Buffer-Ranges auf Antwort-Offsets abbilden.
     * @param array $ranges    Liste {start,end,count} in Buffer-Reihenfolge
     * @param array $registers name → address
     * @return array name → {addr, offset}
     */
    private function ComputeOffsets(array $ranges, array $registers): array
    {
        // Kumulativen Buffer-Offset je Range bestimmen.
        $pos = 0;
        $prepared = [];
        foreach ($ranges as $r) {
            $prepared[] = ['start' => (int)$r['start'], 'end' => (int)$r['end'], 'base' => $pos];
            $pos += (int)$r['count'];
        }

        $out = [];
        foreach ($registers as $name => $addr) {
            $addr = (int)$addr;
            foreach ($prepared as $p) {
                if ($addr >= $p['start'] && $addr <= $p['end']) {
                    $out[$name] = ['addr' => $addr, 'offset' => $p['base'] + ($addr - $p['start'])];
                    break;
                }
            }
        }
        return $out;
    }

    /**
     * Buffer-Ranges aus den Firmware-Konstanten aufbauen (Reihenfolge = Buffer-Layout).
     * Spiegelt buffer_ranges.from_constants der Referenz-Lib.
     */
    private function BuildRangesFromConstants(array $dev, array $read): array
    {
        $ranges = [];
        $isFwV2 = isset($dev['RANGE_START_g_self_test']);

        $add = function (string $name, string $countKey, bool $required = true) use (&$ranges, $dev, $read) {
            $sk = 'RANGE_START_' . $name;
            $ek = 'RANGE_END_' . $name;
            if (isset($dev[$sk], $dev[$ek], $read[$countKey])) {
                $ranges[] = ['start' => (int)$dev[$sk], 'end' => (int)$dev[$ek], 'count' => (int)$read[$countKey], 'name' => $name];
            } elseif ($required) {
                $this->SendDebug('Modell', 'Range fehlt: ' . $name, 0);
            }
        };

        $add('g_cyclone_general_info', 'CYC_NUM_OF_GENERAL_INFO');
        $add('g_typhoon_general_info', 'CYC_NUM_OF_GENERAL_TYP_INFO');
        $add('g_cyclone_hw_state', 'CYC_NUM_OF_HW_STATES');
        $add('g_cyclone_sw_state', 'CYC_NUM_OF_SW_STATES');
        $add('g_cyclone_time', 'CYC_NUM_OF_TIME_ELEMENTS');
        $add('g_cyclone_output', 'CYC_NUM_OF_OUTPUTS');
        $add('g_cyclone_input', 'CYC_NUM_OF_INPUTS');
        $add('g_cyclone_config', 'CYC_NUM_OF_CONFIGS');
        $add('g_cyclone_settings', 'CYC_NUM_OF_CYC_SETTINGS');
        $add('g_typhoon_settings', 'CYC_NUM_OF_TYP_SETTINGS');
        if ($isFwV2) {
            $add('g_self_test', 'CYC_NUM_OF_SELF_TESTS');
        } else {
            $add('g_constant_flow', 'CYC_NUM_OF_CF');
        }
        $add('g_faults', 'CYC_NUM_OF_FAULTS');
        $add('g_cyclone_weekly_schedule', 'CYC_NUM_OF_SCHEDULED_EVENTS');
        $add('g_cyclone_extended', 'CYC_NUM_OF_EXT_SETTINGS', false);

        return $ranges;
    }

    /**
     * VlxDevConstants / VlxReadConstants aus einer JS-Datei extrahieren.
     * Spiegelt DataModel._parse_js_file der Referenz-Lib.
     * @return array{VlxDevConstants:array,VlxReadConstants:array}
     */
    private function ParseConstants(string $js): array
    {
        $out = ['VlxDevConstants' => [], 'VlxReadConstants' => []];
        if (!preg_match_all('/(Vlx\w+)\.(\w+)\s*=\s*([\w.]+)\s*[,;]/i', $js, $m, PREG_SET_ORDER)) {
            return $out;
        }
        foreach ($m as $match) {
            $parent = $match[1];
            if ($parent !== 'VlxDevConstants' && $parent !== 'VlxReadConstants') {
                continue;
            }
            $key = $match[2];
            $val = trim($match[3]);
            if (isset($out[$parent][$key])) {
                continue; // erste Definition gewinnt
            }
            if (preg_match('/^\d/', $val)) {
                $num = (stripos($val, '0x') === 0) ? intval($val, 16) : (int)floatval($val);
                $out[$parent][$key] = $num;
            } else {
                // Referenz auf andere Konstante "Parent.Key"
                $path = explode('.', $val);
                if (count($path) === 2 && isset($out[$path[0]][$path[1]])) {
                    $out[$parent][$key] = $out[$path[0]][$path[1]];
                }
            }
        }
        return $out;
    }

    /**
     * Mitgeliefertes Datenmodell laden (gecacht in $this->model).
     * @return array|false
     */
    private function LoadBundledModel()
    {
        if (is_array($this->model)) {
            return $this->model;
        }
        $file = __DIR__ . '/datamodel.json';
        if (!is_file($file)) {
            return false;
        }
        $dec = json_decode((string)file_get_contents($file), true);
        if (!is_array($dec)) {
            return false;
        }
        $this->model = $dec;
        return $dec;
    }

    // ═══════════════════════════════════════════════════════════════
    //  PRIVATE: Nachrichten-Kodierung (Modbus-artig)
    // ═══════════════════════════════════════════════════════════════

    private function BuildReadTableRequest(): string
    {
        $resolved = $this->ResolveModel(false);
        $cmd = $resolved['ws']['READ_TABLES'] ?? 246;
        // length=3, command, items=0
        $fields = pack('v', 3) . pack('v', $cmd) . pack('v', 0);
        return $fields . pack('v', $this->Checksum16($fields));
    }

    /**
     * @param array<string,int> $items name → uint16-Wert
     */
    private function BuildWriteRequest(array $items): string
    {
        $reg = $this->Regs();
        $resolved = $this->ResolveModel(false);
        $cmd = $resolved['ws']['WRITE_DATA'] ?? 249;

        // name → address, nach Adresse sortiert (wie Referenz).
        $pairs = [];
        foreach ($items as $name => $value) {
            if (!isset($reg[$name])) {
                continue;
            }
            $pairs[] = ['addr' => $reg[$name]['addr'], 'value' => max(0, min(0xFFFF, (int)$value))];
        }
        usort($pairs, fn($a, $b) => $a['addr'] <=> $b['addr']);

        $n = count($pairs);
        $length = $n * 2 + 2;
        $fields = pack('v', $length) . pack('v', $cmd);
        foreach ($pairs as $p) {
            $fields .= pack('v', $p['addr']) . pack('v', $p['value']);
        }
        return $fields . pack('v', $this->Checksum16($fields));
    }

    /**
     * 16-Bit-Prüfsumme: Summe aller 16-Bit-LE-Wörter & 0xFFFF.
     */
    private function Checksum16(string $data): int
    {
        $c = 0;
        $len = intdiv(strlen($data), 2);
        for ($i = 0; $i < $len; $i++) {
            $c += (ord($data[$i * 2 + 1]) << 8) + ord($data[$i * 2]);
        }
        return $c & 0xFFFF;
    }

    /**
     * Binärstring als Array von uint16 (Big-Endian) interpretieren.
     * @return int[]
     */
    private function ParseUint16BE(string $data): array
    {
        $len = intdiv(strlen($data), 2);
        if ($len === 0) {
            return [];
        }
        $vals = unpack('n*', substr($data, 0, $len * 2));
        return array_values($vals);
    }

    private function WriteRegisters(array $items): bool
    {
        $payload = $this->BuildWriteRequest($items);
        $frames  = $this->WsTransact([$payload], 1);
        if ($frames === false) {
            $this->SendDebug('WS', 'Schreibvorgang ohne Antwort', 0);
            return false;
        }
        return true;
    }

    // ═══════════════════════════════════════════════════════════════
    //  PRIVATE: HTTP
    // ═══════════════════════════════════════════════════════════════

    /** @return string|false */
    private function HttpGet(string $url)
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, self::HTTP_TIMEOUT);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, self::HTTP_TIMEOUT);
        curl_setopt($ch, CURLOPT_ENCODING, ''); // Gerät liefert das JS-Modell gzip-komprimiert
        $response = curl_exec($ch);
        $error    = curl_error($ch);
        $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $response === '' || $code >= 400) {
            $this->SendDebug('HTTP', 'GET ' . $url . ' fehlgeschlagen: ' . $error . ' (HTTP ' . $code . ')', 0);
            return false;
        }
        return (string)$response;
    }

    // ═══════════════════════════════════════════════════════════════
    //  PRIVATE: WebSocket-Client (RFC 6455, reines PHP, Binärframes)
    // ═══════════════════════════════════════════════════════════════

    /**
     * Öffnet eine kurzlebige WS-Verbindung, sendet alle Binär-Payloads und liest
     * genau $expectResponses Antwortframes (Text/Binary) ein.
     *
     * @param string[] $payloads
     * @return string[]|false  Rohbytes der Antwortframes
     */
    private function WsTransact(array $payloads, int $expectResponses)
    {
        $host = trim($this->ReadPropertyString('Host'));
        if ($host === '' || count($payloads) === 0) {
            return false;
        }

        $errno = 0;
        $errstr = '';
        $fp = @stream_socket_client('tcp://' . $host . ':' . self::WS_PORT, $errno, $errstr, self::WS_TIMEOUT);
        if ($fp === false) {
            $this->SendDebug('WS', 'Verbindung fehlgeschlagen: ' . $errstr . ' (' . $errno . ')', 0);
            return false;
        }
        stream_set_timeout($fp, self::WS_TIMEOUT);
        $deadline = microtime(true) + self::WS_TIMEOUT;

        // ── Handshake ───────────────────────────────────────────
        $key = base64_encode(random_bytes(16));
        $handshake = 'GET ' . self::WS_PATH . " HTTP/1.1\r\n"
            . 'Host: ' . $host . "\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . 'Sec-WebSocket-Key: ' . $key . "\r\n"
            . "Sec-WebSocket-Version: 13\r\n"
            . "\r\n";
        fwrite($fp, $handshake);

        $headers = $this->WsReadHandshake($fp, $deadline);
        if ($headers === false || strpos($headers, ' 101 ') === false) {
            $this->SendDebug('WS', 'Handshake fehlgeschlagen: ' . substr((string)$headers, 0, 120), 0);
            fclose($fp);
            return false;
        }

        // ── Payloads senden (Binärframes) ───────────────────────
        foreach ($payloads as $payload) {
            fwrite($fp, $this->WsEncodeFrame($payload, 0x2));
        }

        // ── Antworten einsammeln ────────────────────────────────
        $collected = [];
        while (count($collected) < $expectResponses && microtime(true) < $deadline) {
            $frame = $this->WsReadFrame($fp, $deadline);
            if ($frame === false) {
                break;
            }
            [$opcode, $data] = $frame;

            if ($opcode === 0x8) { // Close
                break;
            }
            if ($opcode === 0x9) { // Ping → Pong
                fwrite($fp, $this->WsEncodeFrame($data, 0xA));
                continue;
            }
            if ($opcode !== 0x1 && $opcode !== 0x2) {
                continue;
            }
            $collected[] = $data;
        }

        @fwrite($fp, $this->WsEncodeFrame('', 0x8));
        @fclose($fp);

        if (count($collected) < $expectResponses) {
            $this->SendDebug('WS', 'Zu wenige Antworten: ' . count($collected) . '/' . $expectResponses, 0);
            return count($collected) > 0 ? $collected : false;
        }
        return $collected;
    }

    /** @return string|false */
    private function WsReadHandshake($fp, float $deadline)
    {
        $buf = '';
        while (strpos($buf, "\r\n\r\n") === false && microtime(true) < $deadline) {
            $chunk = fread($fp, 512);
            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($fp);
                if (!empty($meta['timed_out'])) {
                    return false;
                }
                usleep(10000);
                continue;
            }
            $buf .= $chunk;
            if (strlen($buf) > 8192) {
                break;
            }
        }
        return $buf === '' ? false : $buf;
    }

    private function WsEncodeFrame(string $payload, int $opcode = 0x2): string
    {
        $b1 = 0x80 | ($opcode & 0x0F);
        $len = strlen($payload);
        $header = chr($b1);

        if ($len <= 125) {
            $header .= chr(0x80 | $len);
        } elseif ($len <= 0xFFFF) {
            $header .= chr(0x80 | 126) . pack('n', $len);
        } else {
            $header .= chr(0x80 | 127) . pack('J', $len);
        }

        $mask = random_bytes(4);
        $header .= $mask;

        $masked = '';
        for ($i = 0; $i < $len; $i++) {
            $masked .= $payload[$i] ^ $mask[$i % 4];
        }
        return $header . $masked;
    }

    /**
     * @return array{0:int,1:string}|false [opcode, payload]
     */
    private function WsReadFrame($fp, float $deadline)
    {
        $h = $this->WsReadExact($fp, 2, $deadline);
        if ($h === false) {
            return false;
        }
        $b1 = ord($h[0]);
        $b2 = ord($h[1]);
        $opcode = $b1 & 0x0F;
        $masked = ($b2 & 0x80) !== 0;
        $len = $b2 & 0x7F;

        if ($len === 126) {
            $ext = $this->WsReadExact($fp, 2, $deadline);
            if ($ext === false) {
                return false;
            }
            $len = unpack('n', $ext)[1];
        } elseif ($len === 127) {
            $ext = $this->WsReadExact($fp, 8, $deadline);
            if ($ext === false) {
                return false;
            }
            $len = unpack('J', $ext)[1];
        }

        $maskKey = '';
        if ($masked) {
            $maskKey = $this->WsReadExact($fp, 4, $deadline);
            if ($maskKey === false) {
                return false;
            }
        }

        $payload = $len > 0 ? $this->WsReadExact($fp, $len, $deadline) : '';
        if ($payload === false) {
            return false;
        }

        if ($masked && $len > 0) {
            for ($i = 0; $i < $len; $i++) {
                $payload[$i] = $payload[$i] ^ $maskKey[$i % 4];
            }
        }
        return [$opcode, $payload];
    }

    /** @return string|false */
    private function WsReadExact($fp, int $count, float $deadline)
    {
        $buf = '';
        while (strlen($buf) < $count && microtime(true) < $deadline) {
            $chunk = fread($fp, $count - strlen($buf));
            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($fp);
                if (!empty($meta['timed_out']) || feof($fp)) {
                    return false;
                }
                usleep(5000);
                continue;
            }
            $buf .= $chunk;
        }
        return strlen($buf) === $count ? $buf : false;
    }

    // ═══════════════════════════════════════════════════════════════
    //  PRIVATE: Variablen & Profile
    // ═══════════════════════════════════════════════════════════════

    private function SetValueIfChanged(string $ident, $value): void
    {
        $vid = @$this->GetIDForIdent($ident);
        if (!$vid) {
            return;
        }
        $old = GetValue($vid);
        if (is_float($old) || is_float($value)) {
            if (round((float)$old, 3) === round((float)$value, 3)) {
                return;
            }
        } elseif ($old === $value) {
            return;
        }
        $this->SetValue($ident, $value);
    }

    private function GetProfileName(string $suffix): string
    {
        return 'VLX.' . $this->InstanceID . '.' . $suffix;
    }

    private function EnsureProfiles(): void
    {
        $pOnline = $this->GetProfileName('Online');
        if (!IPS_VariableProfileExists($pOnline)) {
            IPS_CreateVariableProfile($pOnline, VARIABLETYPE_BOOLEAN);
            IPS_SetVariableProfileAssociation($pOnline, false, 'Offline', 'Close', 0xFF0000);
            IPS_SetVariableProfileAssociation($pOnline, true, 'Online', 'Ok', 0x00CC00);
            IPS_SetVariableProfileIcon($pOnline, 'Network');
        }

        // Profil-Assoziationen bewusst bei JEDEM Aufruf setzen (nicht nur beim
        // Erstellen), damit die deutschen Beschriftungen ApplyChanges überleben.
        $pProfile = $this->GetProfileName('Profile');
        if (!IPS_VariableProfileExists($pProfile)) {
            IPS_CreateVariableProfile($pProfile, VARIABLETYPE_INTEGER);
        }
        IPS_SetVariableProfileIcon($pProfile, 'Climate');
        $profileMeta = [
            self::PROFILE_NONE      => ['',        0x808080],
            self::PROFILE_HOME      => ['House',   0x00CC00],
            self::PROFILE_AWAY      => ['Moon',    0x0099FF],
            self::PROFILE_BOOST     => ['Speedo',  0xFF9900],
            self::PROFILE_FIREPLACE => ['Flame',   0xFF3300],
            self::PROFILE_EXTRA     => ['Plus',    0xFFCC00],
            self::PROFILE_AUTO      => ['Gear',    0x00CCCC],
        ];
        IPS_SetVariableProfileAssociation($pProfile, self::PROFILE_NONE, 'Unbestimmt', '', 0x808080);
        foreach (self::TXT_PROFILE as $value => $name) {
            [$icon, $color] = $profileMeta[$value] ?? ['', -1];
            IPS_SetVariableProfileAssociation($pProfile, $value, $name, $icon, $color);
        }

        $pCell = $this->GetProfileName('CellState');
        if (!IPS_VariableProfileExists($pCell)) {
            IPS_CreateVariableProfile($pCell, VARIABLETYPE_INTEGER);
        }
        $cellColors = [0 => 0xFF6600, 1 => 0x0099FF, 2 => 0x00CC00, 3 => 0xCCCCCC];
        foreach (self::TXT_CELL as $value => $name) {
            IPS_SetVariableProfileAssociation($pCell, $value, $name, 'Climate', $cellColors[$value] ?? -1);
        }

        $pPercent = $this->GetProfileName('Percent');
        if (!IPS_VariableProfileExists($pPercent)) {
            IPS_CreateVariableProfile($pPercent, VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileText($pPercent, '', ' %');
            IPS_SetVariableProfileValues($pPercent, 0, 100, 1);
            IPS_SetVariableProfileIcon($pPercent, 'Ventilation');
        }

        $pRPM = $this->GetProfileName('RPM');
        if (!IPS_VariableProfileExists($pRPM)) {
            IPS_CreateVariableProfile($pRPM, VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileText($pRPM, '', ' rpm');
            IPS_SetVariableProfileIcon($pRPM, 'Ventilation');
        }

        // Im Profil stehen die Grenzwerte mit dabei; die Kurzform aus
        // TXT_HUMLVL wird in FeuchteText verwendet (dort steht der %-Wert davor).
        $pHum = $this->GetProfileName('HumLevel');
        if (!IPS_VariableProfileExists($pHum)) {
            IPS_CreateVariableProfile($pHum, VARIABLETYPE_INTEGER);
        }
        IPS_SetVariableProfileIcon($pHum, 'Drops');
        IPS_SetVariableProfileAssociation($pHum, 0, 'Optimal (40–55 %)',  '', 0x00C853);
        IPS_SetVariableProfileAssociation($pHum, 1, 'Etwas trocken',      '', 0xFFC107);
        IPS_SetVariableProfileAssociation($pHum, 2, 'Zu trocken (<35 %)', '', 0xD50000);
        IPS_SetVariableProfileAssociation($pHum, 3, 'Etwas feucht',       '', 0xFFC107);
        IPS_SetVariableProfileAssociation($pHum, 4, 'Zu feucht (>60 %)',  '', 0xD50000);

        $pForced = $this->GetProfileName('Forced');
        if (!IPS_VariableProfileExists($pForced)) {
            IPS_CreateVariableProfile($pForced, VARIABLETYPE_BOOLEAN);
        }
        IPS_SetVariableProfileAssociation($pForced, false, 'Nein', '', 0x808080);
        IPS_SetVariableProfileAssociation($pForced, true, 'Ja', 'Ventilation', 0xFF8000);

        $pWatt = $this->GetProfileName('Watt');
        if (!IPS_VariableProfileExists($pWatt)) {
            IPS_CreateVariableProfile($pWatt, VARIABLETYPE_FLOAT);
            IPS_SetVariableProfileText($pWatt, '', ' W');
            IPS_SetVariableProfileDigits($pWatt, 1);
            IPS_SetVariableProfileIcon($pWatt, 'Electricity');
        }

        $pKwh = $this->GetProfileName('kWh');
        if (!IPS_VariableProfileExists($pKwh)) {
            IPS_CreateVariableProfile($pKwh, VARIABLETYPE_FLOAT);
            IPS_SetVariableProfileText($pKwh, '', ' kWh');
            IPS_SetVariableProfileDigits($pKwh, 1);
            IPS_SetVariableProfileIcon($pKwh, 'Electricity');
        }

        $pCO2 = $this->GetProfileName('CO2');
        if (!IPS_VariableProfileExists($pCO2)) {
            IPS_CreateVariableProfile($pCO2, VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileText($pCO2, '', ' ppm');
            IPS_SetVariableProfileIcon($pCO2, 'Gauge');
        }

        $pCO2Level = $this->GetProfileName('CO2Level');
        if (!IPS_VariableProfileExists($pCO2Level)) {
            IPS_CreateVariableProfile($pCO2Level, VARIABLETYPE_INTEGER);
        }
        IPS_SetVariableProfileIcon($pCO2Level, 'Gauge');
        $co2Colors = [0 => 0x00C853, 1 => 0x8BC34A, 2 => 0xFFC107, 3 => 0xFF8000, 4 => 0xD50000];
        foreach (self::TXT_CO2LVL as $value => $name) {
            IPS_SetVariableProfileAssociation($pCO2Level, $value, $name, '', $co2Colors[$value] ?? -1);
        }

        $pMin = $this->GetProfileName('Minutes');
        if (!IPS_VariableProfileExists($pMin)) {
            IPS_CreateVariableProfile($pMin, VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileText($pMin, '', ' min');
            IPS_SetVariableProfileIcon($pMin, 'Clock');
        }

        $pHours = $this->GetProfileName('Hours');
        if (!IPS_VariableProfileExists($pHours)) {
            IPS_CreateVariableProfile($pHours, VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileText($pHours, '', ' h');
            IPS_SetVariableProfileIcon($pHours, 'Clock');
        }

        $pDays = $this->GetProfileName('Days');
        if (!IPS_VariableProfileExists($pDays)) {
            IPS_CreateVariableProfile($pDays, VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileText($pDays, '', ' Tage');
            IPS_SetVariableProfileIcon($pDays, 'Calendar');
        }
    }
}
