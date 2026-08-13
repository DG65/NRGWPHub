<?php

// Pruefstand fuer WPHub (Muster: MeterHub/.tools/test-virtual.php).
// Bildet so viel IP-Symcon nach, dass Modul-Lebenszyklus, Variablenpflege
// und der EMS-Vertrag WIRKLICH ausgefuehrt werden -- php -l allein reicht
// nachweislich nicht (siehe MeterHub-Historie). Kein Netzzugriff: der
// Cloud-Client wird durch eine Attrappe ersetzt.
//
// Aufruf:  php .tools/test-module.php     (0 = alle Pruefungen bestanden)

error_reporting(E_ALL & ~E_DEPRECATED);

$failures = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $failures;
    if ($ok) {
        echo "  ✅ $name\n";
    } else {
        echo "  ❌ $name" . ($detail !== '' ? " — $detail" : '') . "\n";
        $failures++;
    }
}

// ---------------------------------------------------------------------------
// Mini-IPS: nur was WPHub wirklich benutzt.
// ---------------------------------------------------------------------------

const VARIABLETYPE_BOOLEAN = 0;
const VARIABLETYPE_INTEGER = 1;
const VARIABLETYPE_FLOAT   = 2;
const VARIABLETYPE_STRING  = 3;
const KL_WARNING = 10205;
const KL_NOTIFY  = 10204;

$GLOBALS['ips'] = [
    'profiles'   => [],
    'variables'  => [],   // ident => ['name','type','profile','value','id']
    'nextVarId'  => 10000,
    'properties' => [],
    'log'        => [],
];

function IPS_VariableProfileExists(string $name): bool
{
    return isset($GLOBALS['ips']['profiles'][$name]);
}
function IPS_CreateVariableProfile(string $name, int $type): void
{
    $GLOBALS['ips']['profiles'][$name] = ['type' => $type, 'suffix' => '', 'digits' => 0];
}
function IPS_SetVariableProfileText(string $name, string $prefix, string $suffix): void
{
    $GLOBALS['ips']['profiles'][$name]['suffix'] = $suffix;
}
function IPS_SetVariableProfileDigits(string $name, int $digits): void
{
    $GLOBALS['ips']['profiles'][$name]['digits'] = $digits;
}
function IPS_SetVariableProfileAssociation(string $name, float $value, string $valueText, string $valueIcon, int $valueColor): void
{
    $GLOBALS['ips']['profiles'][$name]['associations'][] = $value;
}
function IPS_SetProperty(int $id, string $name, $value): void
{
    $GLOBALS['ips']['properties'][$name] = $value;
}
function IPS_ApplyChanges(int $id): void
{
    $GLOBALS['ips']['applied'] = true;
}
function GetValue(int $id)
{
    foreach ($GLOBALS['ips']['variables'] as $v) {
        if ($v['id'] === $id) {
            return $v['value'];
        }
    }
    return null;
}

class IPSModule
{
    public $InstanceID = 12345;
    protected $attributes = [];
    protected $timers = [];
    public $status = 0;

    public function __construct()
    {
    }
    public function Create()
    {
    }
    public function ApplyChanges()
    {
    }
    protected function RegisterPropertyBoolean(string $name, bool $default): void
    {
        if (!isset($GLOBALS['ips']['properties'][$name])) {
            $GLOBALS['ips']['properties'][$name] = $default;
        }
    }
    protected function RegisterPropertyInteger(string $name, int $default): void
    {
        if (!isset($GLOBALS['ips']['properties'][$name])) {
            $GLOBALS['ips']['properties'][$name] = $default;
        }
    }
    protected function RegisterPropertyString(string $name, string $default): void
    {
        if (!isset($GLOBALS['ips']['properties'][$name])) {
            $GLOBALS['ips']['properties'][$name] = $default;
        }
    }
    protected function RegisterAttributeString(string $name, string $default): void
    {
        if (!isset($this->attributes[$name])) {
            $this->attributes[$name] = $default;
        }
    }
    protected function RegisterTimer(string $ident, int $interval, string $script): void
    {
        $this->timers[$ident] = $interval;
    }
    protected function SetTimerInterval(string $ident, int $interval): void
    {
        $this->timers[$ident] = $interval;
    }
    public function GetTimerInterval(string $ident): int
    {
        return $this->timers[$ident] ?? -1;
    }
    protected function ReadPropertyBoolean(string $name): bool
    {
        return (bool)$GLOBALS['ips']['properties'][$name];
    }
    protected function ReadPropertyInteger(string $name): int
    {
        return (int)$GLOBALS['ips']['properties'][$name];
    }
    protected function ReadPropertyString(string $name): string
    {
        return (string)$GLOBALS['ips']['properties'][$name];
    }
    protected function ReadAttributeString(string $name): string
    {
        return (string)($this->attributes[$name] ?? '');
    }
    protected function WriteAttributeString(string $name, string $value): void
    {
        $this->attributes[$name] = $value;
    }
    protected function SetStatus(int $status): void
    {
        $this->status = $status;
    }
    protected function GetStatus(): int
    {
        return $this->status;
    }
    protected function SendDebug(string $topic, string $text, int $format): void
    {
    }
    protected function LogMessage(string $text, int $type): void
    {
        $GLOBALS['ips']['log'][] = $text;
    }
    protected function UpdateFormField(string $field, string $key, $value): void
    {
    }
    protected function MaintainVariable(string $ident, string $name, int $type, string $profile, int $pos, bool $keep): void
    {
        if (!$keep) {
            unset($GLOBALS['ips']['variables'][$ident]);
            return;
        }
        if (!isset($GLOBALS['ips']['variables'][$ident])) {
            $GLOBALS['ips']['variables'][$ident] = [
                'name'    => $name,
                'type'    => $type,
                'profile' => $profile,
                'value'   => null,
                'id'      => $GLOBALS['ips']['nextVarId']++,
            ];
        }
    }
    protected function SetValue(string $ident, $value): void
    {
        if (isset($GLOBALS['ips']['variables'][$ident])) {
            $GLOBALS['ips']['variables'][$ident]['value'] = $value;
        }
    }
    protected function EnableAction(string $ident): void
    {
        if (isset($GLOBALS['ips']['variables'][$ident])) {
            $GLOBALS['ips']['variables'][$ident]['actionEnabled'] = true;
        }
    }
    protected function GetIDForIdent(string $ident)
    {
        if (!isset($GLOBALS['ips']['variables'][$ident])) {
            trigger_error("Ident $ident not found", E_USER_WARNING);
            return false;
        }
        return $GLOBALS['ips']['variables'][$ident]['id'];
    }
}

require __DIR__ . '/../WPHub/module.php';

// Cloud-Client-Attrappe: liefert eine Gruppe mit einem Klimageraet (wird
// uebersprungen) und einer Aquarea-Waermepumpe mit Zone + Speicher.
class FakeCC extends WPHUB_ComfortCloudClient
{
    public $groupsResult;
    public $statusResult;
    public $consumptionResult;
    public $rejectFirstGroups = false;       // erste getGroups-Antwort: 4106
    public $agreementRejectFirstGroups = false; // erste getGroups-Antwort: 4103
    public $refreshedVersion = null;    // was refreshAppVersion liefern soll
    public $groupCalls = 0;
    private $fakeRejected = false;
    private $fakeAgreementReq = false;

    public function getGroups(array $bundle): ?array
    {
        $this->groupCalls++;
        if ($this->rejectFirstGroups) {
            $this->rejectFirstGroups = false;
            $this->fakeRejected = true;
            return null;
        }
        if ($this->agreementRejectFirstGroups) {
            $this->agreementRejectFirstGroups = false;
            $this->fakeAgreementReq = true;
            return null;
        }
        $this->fakeRejected = false;
        $this->fakeAgreementReq = false;
        return $this->groupsResult;
    }
    public function agreementRequired(): bool
    {
        return $this->fakeAgreementReq;
    }
    public function versionRejected(): bool
    {
        return $this->fakeRejected;
    }
    public function refreshAppVersion(): ?string
    {
        return $this->refreshedVersion;
    }

    public $documentsByType = [];    // typeId => Liste [['type'=>int,'version'=>str]] | null
    public $documentsAllNull = false; // erzwingt null fuer jeden Typ
    public $putResult = true;        // was putAgreementStatus liefert
    public $putCalls = [];           // aufgezeichnete PUT-Listen

    public $reauthCalls = 0;         // wie oft nach dem PUT neu angemeldet wurde

    public function getAgreementDocuments(array $bundle, ?int $typeId = null): ?array
    {
        if ($this->documentsAllNull) {
            return null;
        }
        return array_key_exists($typeId, $this->documentsByType) ? $this->documentsByType[$typeId] : [];
    }
    public function putAgreementStatus(array $bundle, array $items): bool
    {
        $this->putCalls[] = $items;
        return $this->putResult;
    }
    // Frische Sitzung nach dem PUT -- im Test ohne Netz nachgebildet.
    public function refresh(array $bundle): ?array
    {
        $this->reauthCalls++;
        return $bundle;
    }
    public function accLogin(array &$bundle): bool
    {
        return true;
    }
    public function getDeviceStatus(array $bundle, string $guid): ?array
    {
        return $this->statusResult;
    }
    public function getDeviceConsumptionToday(array $bundle, string $guid): ?array
    {
        return $this->consumptionResult;
    }

    // Steuerung: erfolgreiche/fehlgeschlagene Antwort per Attrappe steuerbar,
    // jeder Aufruf wird mit seinen Parametern aufgezeichnet.
    public $controlResult = true;
    public $controlCalls = [];

    public function setQuietMode(array $bundle, string $guid, int $mode): bool
    {
        $this->controlCalls[] = ['setQuietMode', $guid, $mode];
        return $this->controlResult;
    }
    public function setPowerfulTime(array $bundle, string $guid, int $mode): bool
    {
        $this->controlCalls[] = ['setPowerfulTime', $guid, $mode];
        return $this->controlResult;
    }
    public function setForceDHW(array $bundle, string $guid, bool $on): bool
    {
        $this->controlCalls[] = ['setForceDHW', $guid, $on];
        return $this->controlResult;
    }
    public function setForceHeater(array $bundle, string $guid, bool $on): bool
    {
        $this->controlCalls[] = ['setForceHeater', $guid, $on];
        return $this->controlResult;
    }
    public function setHolidayTimer(array $bundle, string $guid, bool $on): bool
    {
        $this->controlCalls[] = ['setHolidayTimer', $guid, $on];
        return $this->controlResult;
    }
    public function setTankTemperature(array $bundle, string $guid, float $temperature): bool
    {
        $this->controlCalls[] = ['setTankTemperature', $guid, $temperature];
        return $this->controlResult;
    }
    public function setZoneTemperature(array $bundle, string $guid, int $zoneId, float $temperature, string $key): bool
    {
        $this->controlCalls[] = ['setZoneTemperature', $guid, $zoneId, $temperature, $key];
        return $this->controlResult;
    }
    public function getLastError(): string
    {
        return 'Attrappen-Fehler';
    }
}

// ---------------------------------------------------------------------------
echo "Block 1: Lebenszyklus und Status\n";
// ---------------------------------------------------------------------------

$mod = new WPHub();
$mod->Create();
$mod->ApplyChanges();
check('Inaktiv → Status 104', $mod->status === 104, 'Status ' . $mod->status);

$GLOBALS['ips']['properties']['WPHUB_Active'] = true;
$mod->ApplyChanges();
check('Aktiv ohne Anmeldung → Status 201', $mod->status === 201, 'Status ' . $mod->status);
check('Timer bleibt aus ohne Anmeldung', $mod->GetTimerInterval('WPHUB_UpdateTimer') === 0);

$mod->Update();
check('Update ohne Anmeldung → Status 201, kein Absturz', $mod->status === 201);

// Token-Buendel simulieren (gueltig bis weit in der Zukunft).
$setAttr = new ReflectionMethod(WPHub::class, 'WriteAttributeString');
$setAttr->setAccessible(true);
$setAttr->invoke($mod, 'CC_Token', json_encode([
    'accessToken'  => 'test-token',
    'refreshToken' => 'test-refresh',
    'expiresAt'    => time() + 86400,
    'scope'        => 'openid',
    'clientId'     => 'test-client',
]));
$mod->ApplyChanges();
check('Aktiv mit Token → Status 102', $mod->status === 102, 'Status ' . $mod->status);
check('Timer laeuft (60 s)', $mod->GetTimerInterval('WPHUB_UpdateTimer') === 60000);
check('NRG.Celsius wurde angelegt', IPS_VariableProfileExists('NRG.Celsius'));

// Fremd angelegtes NRG.Celsius darf nicht ueberschrieben werden.
$GLOBALS['ips']['profiles']['NRG.Celsius']['digits'] = 3;
$mod->ApplyChanges();
check('Fremdes NRG.Celsius bleibt unangetastet', $GLOBALS['ips']['profiles']['NRG.Celsius']['digits'] === 3);

// ---------------------------------------------------------------------------
echo "Block 2: Geraeteabruf und Variablenpflege\n";
// ---------------------------------------------------------------------------

// device/group liefert die A2W-Betriebsdaten inline (Struktur wie am echten
// Konto): ein Klimageraet (deviceType 1, mit parameters -> uebersprungen) und
// eine Aquarea-Waermepumpe (deviceType 2, Zonen/Speicher inline).
$fake = new FakeCC();
$fake->groupsResult = ['groupList' => [
    [
        'groupName'  => 'My House',
        'deviceList' => [
            ['deviceGuid' => 'AC-1', 'deviceType' => '1', 'deviceName' => 'Klima Buero', 'parameters' => ['operate' => 1]],
        ],
    ],
    [
        'groupName'  => 'AQUAREA',
        'deviceList' => [
            [
                'deviceGuid'       => 'B270592026',
                'deviceType'       => '2',
                'deviceName'       => 'Heizung',
                'connectionStatus' => 1,
                'operationMode'    => 2,
                'zoneStatus'       => [
                    ['zoneId' => 1, 'operationStatus' => 1, 'temperature' => 19],
                    ['zoneId' => 2], // ungenutzte Zone, keine Temperatur
                ],
                'tankStatus'       => ['operationStatus' => 1, 'temperature' => 43, 'temperatureNow' => 42],
            ],
        ],
    ],
]];

$refresh = new ReflectionMethod(WPHub::class, 'refreshDevices');
$refresh->setAccessible(true);
$devices = $refresh->invoke($mod, ['accessToken' => 'x'], $fake);

check('Genau eine Waermepumpe erkannt (Klimageraet uebersprungen)', is_array($devices) && count($devices) === 1, json_encode($devices));
check('Name aus deviceName uebernommen', $devices[0]['name'] === 'Heizung');
check('Geraet als erreichbar markiert (in device/group vorhanden)', $devices[0]['reachable'] === true);

$prefix = $devices[0]['prefix'];
$vars = $GLOBALS['ips']['variables'];
check('Variable Erreichbar = true', ($vars[$prefix . 'Erreichbar']['value'] ?? null) === true);
check('Betriebsart = 2', ($vars[$prefix . 'Betriebsart']['value'] ?? null) === 2);
check('Betriebsart nutzt WPHUB.Betriebsart-Profil', ($vars[$prefix . 'Betriebsart']['profile'] ?? '') === 'WPHUB.Betriebsart');
check('Warmwasser Ist 42 °C (temperatureNow)', ($vars[$prefix . 'Warmwasser']['value'] ?? null) === 42.0);
check('Warmwasser Soll 43 °C (temperature)', ($vars[$prefix . 'WarmwasserSoll']['value'] ?? null) === 43.0);
check('Warmwasser nutzt NRG.Celsius', ($vars[$prefix . 'Warmwasser']['profile'] ?? '') === 'NRG.Celsius');
check('Warmwasser Betrieb = true', ($vars[$prefix . 'WarmwasserBetrieb']['value'] ?? null) === true);
check('Zone 1 Soll 19 °C', ($vars[$prefix . 'Zone1Soll']['value'] ?? null) === 19.0);
check('Zone 1 Betrieb = true', ($vars[$prefix . 'Zone1Betrieb']['value'] ?? null) === true);
check('Zone 2 ohne Temperatur legt KEINE Soll-Variable an', !isset($vars[$prefix . 'Zone2Soll']));

// connectionStatus:0 ist Normalzustand -> weiterhin erreichbar (die Cloud liefert
// aktuelle Werte, die App zeigt das Geraet nie als offline).
$fake->groupsResult['groupList'][1]['deviceList'][0]['connectionStatus'] = 0;
$devices = $refresh->invoke($mod, ['accessToken' => 'x'], $fake);
check('connectionStatus 0 bleibt erreichbar (Normalzustand)', $devices[0]['reachable'] === true);
check('Erreichbar-Variable = true', ($GLOBALS['ips']['variables'][$prefix . 'Erreichbar']['value'] ?? null) === true);

// Cloud-Ausfall: markAllUnreachable() setzt Erreichbar=false, Werte bleiben.
$markAllTmp = new ReflectionMethod(WPHub::class, 'markAllUnreachable');
$markAllTmp->setAccessible(true);
$markAllTmp->invoke($mod);
check('Nach Cloud-Ausfall Erreichbar=false', ($GLOBALS['ips']['variables'][$prefix . 'Erreichbar']['value'] ?? null) === false);
check('Messwerte bleiben trotz Ausfall erhalten', ($GLOBALS['ips']['variables'][$prefix . 'Warmwasser']['value'] ?? null) === 42.0);

// ---------------------------------------------------------------------------
echo "Block 2b: Reichhaltiger A2W-Status (Transfer-Proxy)\n";
// ---------------------------------------------------------------------------

// Antwortform wie am echten Konto ueber getDeviceStatus() (Transfer-Proxy,
// deviceDirect=1/0). $status ist bereits das entpackte 'status'-Objekt.
$fake->statusResult = [
    'operationMode' => 2,
    'direction'     => 1,
    'quietMode'     => 3,
    'powerful'      => 0,
    'forceDHW'      => 1,
    'forceHeater'   => 0,
    'outdoorNow'    => 25,
    'holidayTimer'  => 1,
    'deiceStatus'   => 1,
    'specialStatus' => 2,
    'faultStatus'   => [
        ['errorCode' => 'H12', 'errorMessage' => 'Kommunikationsfehler Außeneinheit'],
    ],
    'zoneStatus'    => [
        ['zoneId' => 1, 'zoneName' => 'HK1', 'temperatureNow' => 18],
    ],
];
$fake->consumptionResult = ['heat' => 3.5, 'cool' => 0.0, 'tank' => 1.2, 'total' => 4.7];
$devices = $refresh->invoke($mod, ['accessToken' => 'x'], $fake);
$vars = $GLOBALS['ips']['variables'];
check('Außentemperatur = 25 °C', ($vars[$prefix . 'Aussentemperatur']['value'] ?? null) === 25.0);
check('Außentemperatur nutzt NRG.Celsius', ($vars[$prefix . 'Aussentemperatur']['profile'] ?? '') === 'NRG.Celsius');
check('Zone 1 Ist = 18 °C (aus zoneStatus/temperatureNow)', ($vars[$prefix . 'Zone1Ist']['value'] ?? null) === 18.0);
check('Zonenname aus Status uebernommen (HK1 statt "Zone 1")', ($vars[$prefix . 'Zone1Ist']['name'] ?? '') === 'Heizung: HK1 Isttemperatur');
check('Flüsterbetrieb = 3 (Stufe 3)', ($vars[$prefix . 'Fluesterbetrieb']['value'] ?? null) === 3);
check('Flüsterbetrieb nutzt WPHUB.Fluesterbetrieb-Profil', ($vars[$prefix . 'Fluesterbetrieb']['profile'] ?? '') === 'WPHUB.Fluesterbetrieb');
check('Leistungsbetrieb = 0 (Aus)', ($vars[$prefix . 'Leistungsbetrieb']['value'] ?? null) === 0);
check('Urlaubstimer = true', ($vars[$prefix . 'Urlaubstimer']['value'] ?? null) === true);
check('Notbetrieb Warmwasser = true (forceDHW=1)', ($vars[$prefix . 'NotbetriebWarmwasser']['value'] ?? null) === true);
check('Not-Heizbetrieb = false (forceHeater=0)', ($vars[$prefix . 'NotHeizbetrieb']['value'] ?? null) === false);
check('Abtaubetrieb = true (deiceStatus=1)', ($vars[$prefix . 'Abtaubetrieb']['value'] ?? null) === true);
check('Betriebsrichtung = 1 (Umwälzpumpe)', ($vars[$prefix . 'Betriebsrichtung']['value'] ?? null) === 1);
check('Eco/Komfort = 2 (Komfort)', ($vars[$prefix . 'EcoKomfort']['value'] ?? null) === 2);
check('Fehleranzahl = 1', ($vars[$prefix . 'Fehleranzahl']['value'] ?? null) === 1);
check('Fehlertext enthält Fehlermeldung', ($vars[$prefix . 'Fehlertext']['value'] ?? '') === 'Kommunikationsfehler Außeneinheit');
check('Energie Heizen heute = 3.5 kWh', ($vars[$prefix . 'EnergieHeizenHeute']['value'] ?? null) === 3.5);
check('Energie Heizen nutzt NRG.kWh', ($vars[$prefix . 'EnergieHeizenHeute']['profile'] ?? '') === 'NRG.kWh');
check('Energie Kühlen heute = 0.0 kWh', ($vars[$prefix . 'EnergieKuehlenHeute']['value'] ?? null) === 0.0);
check('Energie Warmwasser heute = 1.2 kWh', ($vars[$prefix . 'EnergieWarmwasserHeute']['value'] ?? null) === 1.2);
check('Energie gesamt heute = 4.7 kWh', ($vars[$prefix . 'EnergieGesamtHeute']['value'] ?? null) === 4.7);

// Keine Fehler: leere Liste -> Fehleranzahl 0, Fehlertext leer.
$fake->statusResult['faultStatus'] = [];
$refresh->invoke($mod, ['accessToken' => 'x'], $fake);
$vars = $GLOBALS['ips']['variables'];
check('Ohne Fehler: Fehleranzahl = 0', ($vars[$prefix . 'Fehleranzahl']['value'] ?? null) === 0);
check('Ohne Fehler: Fehlertext leer', ($vars[$prefix . 'Fehlertext']['value'] ?? null) === '');

// Schlaegt der Zusatzabruf fehl (statusResult=null), bleiben die
// Basisdaten unangetastet und es werden keine Reichdaten-Variablen
// neu angelegt bzw. die vorhandenen bleiben auf dem letzten Stand.
$fake->statusResult = null;
$fake->consumptionResult = null;
$refresh->invoke($mod, ['accessToken' => 'x'], $fake);
$vars = $GLOBALS['ips']['variables'];
check('Ohne Statusabruf bleibt Außentemperatur erhalten (letzter Stand)', ($vars[$prefix . 'Aussentemperatur']['value'] ?? null) === 25.0);
check('Basisdaten (Betriebsart) weiterhin korrekt', ($vars[$prefix . 'Betriebsart']['value'] ?? null) === 2);
check('Ohne Verbrauchsabruf bleibt Energie Heizen erhalten (letzter Stand)', ($vars[$prefix . 'EnergieHeizenHeute']['value'] ?? null) === 3.5);

// ---------------------------------------------------------------------------
echo "Block 3: EMS-Vertrag (GetFunctions)\n";
// ---------------------------------------------------------------------------

// Erneuter Abruf -> Geraet wieder erreichbar fuer den reachable=true-Pfad.
$refresh->invoke($mod, ['accessToken' => 'x'], $fake);

$functions = $mod->GetFunctions();
check('Ein Vertragseintrag', count($functions) === 1);
$f = $functions[0] ?? [];
check('Type = heatpump', ($f['Type'] ?? '') === 'heatpump');
check('contractVersion = 1.3', ($f['contractVersion'] ?? '') === '1.3');
check('Caption = Geraetename', ($f['Caption'] ?? '') === 'Heizung');
check('PowerID = 0 (Cloud liefert keine Leistung)', ($f['PowerID'] ?? -1) === 0);
check('EnergyID = 0 (keine kumulative Energie)', ($f['EnergyID'] ?? -1) === 0);
check('Measured = false', ($f['Measured'] ?? true) === false);
check('unit = W', ($f['unit'] ?? '') === 'W');
check('reachable = true (live aus Variable)', ($f['reachable'] ?? false) === true);

// Additive Vertragsfelder (contractVersion 1.3): vorhandene Werte liefern
// die echte Variablen-ID, fehlende (Zone 2 existiert bei diesem Geraet
// nicht) liefern 0 -- nie einen falschen Ident raten.
$vars = $GLOBALS['ips']['variables'];
check('outdoorTemperatureID zeigt auf Aussentemperatur-Variable', ($f['outdoorTemperatureID'] ?? 0) === ($vars[$prefix . 'Aussentemperatur']['id'] ?? -1));
check('z1WaterTempID zeigt auf Zone1Ist-Variable', ($f['z1WaterTempID'] ?? 0) === ($vars[$prefix . 'Zone1Ist']['id'] ?? -1));
check('z1WaterTargetTempID zeigt auf Zone1Soll-Variable', ($f['z1WaterTargetTempID'] ?? 0) === ($vars[$prefix . 'Zone1Soll']['id'] ?? -1));
check('z2WaterTempID = 0 (Zone 2 nicht vorhanden)', ($f['z2WaterTempID'] ?? -1) === 0);
check('z2WaterTargetTempID = 0 (Zone 2 nicht vorhanden)', ($f['z2WaterTargetTempID'] ?? -1) === 0);
check('dhwTempID zeigt auf Warmwasser-Variable', ($f['dhwTempID'] ?? 0) === ($vars[$prefix . 'Warmwasser']['id'] ?? -1));
check('dhwTargetTempID zeigt auf WarmwasserSoll-Variable', ($f['dhwTargetTempID'] ?? 0) === ($vars[$prefix . 'WarmwasserSoll']['id'] ?? -1));
check('quietModeID zeigt auf Fluesterbetrieb-Variable', ($f['quietModeID'] ?? 0) === ($vars[$prefix . 'Fluesterbetrieb']['id'] ?? -1));
check('ecoComfortModeID zeigt auf EcoKomfort-Variable', ($f['ecoComfortModeID'] ?? 0) === ($vars[$prefix . 'EcoKomfort']['id'] ?? -1));
check('holidayTimerID zeigt auf Urlaubstimer-Variable', ($f['holidayTimerID'] ?? 0) === ($vars[$prefix . 'Urlaubstimer']['id'] ?? -1));

// Cloud-Ausfall: alle Geraete unerreichbar, Variablen bleiben bestehen.
$markAll = new ReflectionMethod(WPHub::class, 'markAllUnreachable');
$markAll->setAccessible(true);
$markAll->invoke($mod);
$functions = $mod->GetFunctions();
check('Nach Cloud-Ausfall: reachable = false', ($functions[0]['reachable'] ?? true) === false);
check('Variablen bleiben nach Ausfall erhalten', isset($GLOBALS['ips']['variables'][$prefix . 'Warmwasser']));

// ---------------------------------------------------------------------------
echo "Block 4: Client-Hilfsfunktionen (ohne Netz)\n";
// ---------------------------------------------------------------------------

$client = new WPHUB_ComfortCloudClient('1.21.0');

$apiKey = new ReflectionMethod(WPHUB_ComfortCloudClient::class, 'apiKey');
$apiKey->setAccessible(true);
$key = $apiKey->invoke($client, '2026-08-10 12:00:00', 'dummy-token');
check('API-Schluessel: 67 Zeichen', strlen($key) === 67, strlen($key) . ' Zeichen');
check('API-Schluessel: "cfc" an Position 9', substr($key, 9, 3) === 'cfc');
check('API-Schluessel deterministisch', $key === $apiKey->invoke($client, '2026-08-10 12:00:00', 'dummy-token'));

$hidden = new ReflectionMethod(WPHUB_ComfortCloudClient::class, 'parseHiddenInputs');
$hidden->setAccessible(true);
$html = '<form><input type="hidden" name="wa" value="wsignin1.0"/><INPUT type="hidden" name="wresult" value="abc&quot;def"><input type="hidden" name="wctx" value="x=1&amp;y=2"></form>';
$parsed = $hidden->invoke($client, $html);
check('Versteckte Felder: 3 gefunden', count($parsed) === 3, json_encode($parsed));
check('HTML-Entities dekodiert (&quot;)', ($parsed['wresult'] ?? '') === 'abc"def');
check('HTML-Entities dekodiert (&amp;)', ($parsed['wctx'] ?? '') === 'x=1&y=2');

$qp = new ReflectionMethod(WPHUB_ComfortCloudClient::class, 'queryParam');
$qp->setAccessible(true);
check('Query-Parameter aus Redirect-URI', $qp->invoke($client, 'panasonic-iot-cfc://authglb.digital.panasonic.com/android/com.panasonic.ACCsmart/callback?code=ABC123&state=xyz', 'code') === 'ABC123');

$absUrl = new ReflectionMethod(WPHUB_ComfortCloudClient::class, 'absoluteAuthUrl');
$absUrl->setAccessible(true);
check('Relative Location mit Slash', $absUrl->invoke($client, '/login?x=1') === 'https://authglb.digital.panasonic.com/login?x=1');
check('Relative Location ohne Slash', $absUrl->invoke($client, 'login?x=1') === 'https://authglb.digital.panasonic.com/login?x=1');
check('Absolute Location unveraendert', $absUrl->invoke($client, 'https://example.org/a') === 'https://example.org/a');

// ---------------------------------------------------------------------------
echo "Block 4b: App-Version (4106) — automatische Erneuerung\n";
// ---------------------------------------------------------------------------

// Prioritaetskette der Version: Auto-Attribut > Formular-Notnagel > Standard.
$ccClient = new ReflectionMethod(WPHub::class, 'ccClient');
$ccClient->setAccessible(true);
check('Standard-Version ohne alles: 4.4.0', $ccClient->invoke($mod)->getAppVersion() === '4.4.0');
$GLOBALS['ips']['properties']['CC_AppVersion'] = '5.0.1';
check('Formular-Notnagel greift', $ccClient->invoke($mod)->getAppVersion() === '5.0.1');
$setAttr->invoke($mod, 'CC_AppVersionAuto', '6.0.0');
check('Automatisch ermittelte Version hat Vorrang', $ccClient->invoke($mod)->getAppVersion() === '6.0.0');
$GLOBALS['ips']['properties']['CC_AppVersion'] = '';

// 4106 beim Geraeteabruf: einmal neu ermitteln, dann genau EIN Wiederholungsversuch.
$fake2 = new FakeCC();
$fake2->groupsResult = $fake->groupsResult;
$fake2->statusResult = $fake->statusResult;
$fake2->rejectFirstGroups = true;
$fake2->refreshedVersion = '9.9.9';
$devices2 = $refresh->invoke($mod, ['accessToken' => 'x'], $fake2);
check('Nach 4106: Wiederholung erfolgreich', is_array($devices2) && count($devices2) === 1);
check('Genau zwei getGroups-Aufrufe', $fake2->groupCalls === 2, $fake2->groupCalls . ' Aufrufe');
$getAttr = new ReflectionMethod(WPHub::class, 'ReadAttributeString');
$getAttr->setAccessible(true);
check('Neue Version im Auto-Attribut gemerkt', $getAttr->invoke($mod, 'CC_AppVersionAuto') === '9.9.9');

// 4106, aber Versionsermittlung schlaegt fehl: kein Endlos-Retry, sauberes null.
$fake3 = new FakeCC();
$fake3->groupsResult = $fake->groupsResult;
$fake3->rejectFirstGroups = true;
$fake3->refreshedVersion = null;
$devices3 = $refresh->invoke($mod, ['accessToken' => 'x'], $fake3);
check('Ermittlung fehlgeschlagen → Abruf liefert null', $devices3 === null);
check('Kein zweiter getGroups-Versuch ohne neue Version', $fake3->groupCalls === 1, $fake3->groupCalls . ' Aufrufe');

// ---------------------------------------------------------------------------
echo "Block 4c: Zustimmung zu aktualisierten Bedingungen (4103)\n";
// ---------------------------------------------------------------------------

$sayMessages = [];
$say = function (string $m) use (&$sayMessages) {
    $sayMessages[] = $m;
};
$doAccept = new ReflectionMethod(WPHub::class, 'doAcceptAgreements');
$doAccept->setAccessible(true);

// Je Typ ein Dokument mit Version: Typen 1+2 offen, Typ 3 (Tuerkei) liefert
// nichts. Beide werden in EINEM PUT mit genau diesen Versionen bestaetigt,
// danach laedt die Geraeteliste (102).
$fake4 = new FakeCC();
$fake4->groupsResult = $fake->groupsResult;
$fake4->statusResult = $fake->statusResult;
$fake4->documentsByType = [
    1 => [['type' => 1, 'version' => '2026-05-01']],
    2 => [['type' => 2, 'version' => '2026-05-02']],
    3 => [],
];
$mod->status = 202;
$doAccept->invoke($mod, $fake4, ['accessToken' => 'x'], $say);
check('Genau ein PUT mit beiden Dokumenten', count($fake4->putCalls) === 1 && count($fake4->putCalls[0]) === 2, json_encode($fake4->putCalls));
check('PUT enthaelt die gelieferten Versionen', ($fake4->putCalls[0][0]['version'] ?? '') === '2026-05-01' && ($fake4->putCalls[0][1]['version'] ?? '') === '2026-05-02');
check('Gleiche Sitzung nutzt Geraeteliste (keine Erneuerung)', $fake4->reauthCalls === 0, $fake4->reauthCalls . ' Aufrufe');
check('Danach Status 102', $mod->status === 102, 'Status ' . $mod->status);
check('Erfolgsmeldung mit Geraeteliste', strpos(end($sayMessages), 'Heizung') !== false, end($sayMessages));

// Fallback: erste Geraeteliste nach PUT noch 4103 -> frische Sitzung, 2. Versuch klappt.
$fakeFb = new FakeCC();
$fakeFb->groupsResult = $fake->groupsResult;
$fakeFb->statusResult = $fake->statusResult;
$fakeFb->documentsByType = [1 => [['type' => 1, 'version' => 'v1']], 2 => [], 3 => []];
$fakeFb->agreementRejectFirstGroups = true;
$mod->status = 202;
$sayMessages = [];
$doAccept->invoke($mod, $fakeFb, ['accessToken' => 'x'], $say);
check('Fallback stellt frische Sitzung her', $fakeFb->reauthCalls === 1, $fakeFb->reauthCalls . ' Aufrufe');
check('Fallback laedt Geraeteliste (Status 102)', $mod->status === 102, 'Status ' . $mod->status);
check('Fallback: zwei getGroups-Versuche', $fakeFb->groupCalls === 2, $fakeFb->groupCalls . ' Aufrufe');

// PUT von der Cloud abgelehnt: sauberer Abbruch mit Fehlermeldung, kein 102.
$fake5 = new FakeCC();
$fake5->documentsByType = [1 => [['type' => 1, 'version' => 'v9']]];
$fake5->putResult = false;
$mod->status = 202;
$sayMessages = [];
$doAccept->invoke($mod, $fake5, ['accessToken' => 'x'], $say);
check('Ablehnung bricht ab (ein PUT-Versuch)', count($fake5->putCalls) === 1);
check('Status bleibt 202', $mod->status === 202, 'Status ' . $mod->status);
check('Fehlermeldung ausgegeben', strpos(end($sayMessages), '❌') === 0, end($sayMessages));

// Kein Typ abrufbar (alle null): Abbruch, gar kein PUT.
$fake6 = new FakeCC();
$fake6->documentsAllNull = true;
$mod->status = 202;
$sayMessages = [];
$doAccept->invoke($mod, $fake6, ['accessToken' => 'x'], $say);
check('Ohne Dokumente kein PUT', count($fake6->putCalls) === 0);
check('Fehlermeldung ausgegeben', strpos(end($sayMessages), '❌') === 0, end($sayMessages));

// ---------------------------------------------------------------------------
echo "Block 4d: Steuerung (RequestAction)\n";
// ---------------------------------------------------------------------------
// applyControl() ist der testbare Kern von RequestAction() (Client per
// Parameter injizierbar, wie bei refreshDevices()). Die Variablen existieren
// bereits aus Block 2/2b (gleicher $prefix).

$applyControl = new ReflectionMethod(WPHub::class, 'applyControl');
$applyControl->setAccessible(true);
$ctrl = new FakeCC();
$bundle = ['accessToken' => 'x'];
$devHeat = ['guid' => 'B270592026', 'operationMode' => 1]; // Heizen -> heatSet
$devCool = ['guid' => 'B270592026', 'operationMode' => 2]; // Kuehlen -> coolSet

$applyControl->invoke($mod, $prefix . 'Fluesterbetrieb', 'Fluesterbetrieb', 2, $devCool, $bundle, $ctrl);
check('Flüsterbetrieb: Variable auf neuen Wert gesetzt', ($GLOBALS['ips']['variables'][$prefix . 'Fluesterbetrieb']['value'] ?? null) === 2);
check('Flüsterbetrieb: setQuietMode mit Modus 2 aufgerufen', end($ctrl->controlCalls) === ['setQuietMode', 'B270592026', 2]);

$applyControl->invoke($mod, $prefix . 'Leistungsbetrieb', 'Leistungsbetrieb', 1, $devCool, $bundle, $ctrl);
check('Leistungsbetrieb: Variable gesetzt', ($GLOBALS['ips']['variables'][$prefix . 'Leistungsbetrieb']['value'] ?? null) === 1);
check('Leistungsbetrieb: setPowerfulTime aufgerufen', end($ctrl->controlCalls) === ['setPowerfulTime', 'B270592026', 1]);

$applyControl->invoke($mod, $prefix . 'Urlaubstimer', 'Urlaubstimer', true, $devCool, $bundle, $ctrl);
check('Urlaubstimer: Variable gesetzt', ($GLOBALS['ips']['variables'][$prefix . 'Urlaubstimer']['value'] ?? null) === true);
check('Urlaubstimer: setHolidayTimer(true) aufgerufen', end($ctrl->controlCalls) === ['setHolidayTimer', 'B270592026', true]);

$applyControl->invoke($mod, $prefix . 'NotbetriebWarmwasser', 'NotbetriebWarmwasser', true, $devCool, $bundle, $ctrl);
check('Notbetrieb Warmwasser: setForceDHW(true) aufgerufen', end($ctrl->controlCalls) === ['setForceDHW', 'B270592026', true]);

$applyControl->invoke($mod, $prefix . 'NotHeizbetrieb', 'NotHeizbetrieb', false, $devCool, $bundle, $ctrl);
check('Not-Heizbetrieb: setForceHeater(false) aufgerufen', end($ctrl->controlCalls) === ['setForceHeater', 'B270592026', false]);

$applyControl->invoke($mod, $prefix . 'WarmwasserSoll', 'WarmwasserSoll', 45.0, $devCool, $bundle, $ctrl);
check('Warmwasser Soll: Variable auf 45.0 gesetzt', ($GLOBALS['ips']['variables'][$prefix . 'WarmwasserSoll']['value'] ?? null) === 45.0);
check('Warmwasser Soll: setTankTemperature(45.0) aufgerufen', end($ctrl->controlCalls) === ['setTankTemperature', 'B270592026', 45.0]);

// Zonen-Solltemperatur: je nach aktueller Betriebsart heatSet oder coolSet.
$applyControl->invoke($mod, $prefix . 'Zone1Soll', 'Zone1Soll', 21.0, $devCool, $bundle, $ctrl);
check('Zone Soll (Kühlen=2): coolSet verwendet', end($ctrl->controlCalls) === ['setZoneTemperature', 'B270592026', 1, 21.0, 'coolSet']);

$applyControl->invoke($mod, $prefix . 'Zone1Soll', 'Zone1Soll', 21.0, $devHeat, $bundle, $ctrl);
check('Zone Soll (Heizen=1): heatSet verwendet', end($ctrl->controlCalls) === ['setZoneTemperature', 'B270592026', 1, 21.0, 'heatSet']);

// Fehlschlag: Variable bleibt auf dem letzten bekannten Stand, Protokollzeile erklärt warum.
$ctrl->controlResult = false;
$GLOBALS['ips']['log'] = [];
$applyControl->invoke($mod, $prefix . 'Fluesterbetrieb', 'Fluesterbetrieb', 0, $devCool, $bundle, $ctrl);
check('Fehlschlag: Variable bleibt auf altem Wert (2, nicht 0)', ($GLOBALS['ips']['variables'][$prefix . 'Fluesterbetrieb']['value'] ?? null) === 2);
check('Fehlschlag: Protokollzeile mit Fehlertext', count($GLOBALS['ips']['log']) === 1 && strpos($GLOBALS['ips']['log'][0], 'Attrappen-Fehler') !== false);

// Unbekanntes Feld: kein Cloud-Aufruf, kein SetValue, nur eine Protokollzeile.
$ctrl->controlResult = true;
$ctrl->controlCalls = [];
$GLOBALS['ips']['log'] = [];
$applyControl->invoke($mod, $prefix . 'UnbekanntesFeld', 'UnbekanntesFeld', 1, $devCool, $bundle, $ctrl);
check('Unbekanntes Feld: kein Client-Aufruf', count($ctrl->controlCalls) === 0);
check('Unbekanntes Feld: Protokollzeile', count($GLOBALS['ips']['log']) === 1);

// ---------------------------------------------------------------------------
echo "Block 5: Vollstaendigkeit der Methodenaufrufe\n";
// ---------------------------------------------------------------------------
// Lehre aus Build 2: login() rief $this->resetCookies() auf, die Methode
// fehlte aber -- der Pruefstand deckt Netzpfade nicht aus, php -l sieht
// undefinierte Methoden nicht. Deshalb hier statisch: jeder $this->x()-
// Aufruf muss in der Klasse (oder ihrer Basis) existieren.

foreach ([
    ['WPHub/libs/ComfortCloudClient.php', WPHUB_ComfortCloudClient::class],
    ['WPHub/module.php', WPHub::class],
] as [$file, $class]) {
    $src = file_get_contents(__DIR__ . '/../' . $file);
    preg_match_all('/\$this->([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/', $src, $m);
    $missing = [];
    foreach (array_unique($m[1]) as $method) {
        if (!method_exists($class, $method)) {
            $missing[] = $method;
        }
    }
    check("Alle \$this->…()-Aufrufe in $file definiert", count($missing) === 0, 'fehlt: ' . implode(', ', $missing));
}

// ---------------------------------------------------------------------------
echo "\n";
if ($failures === 0) {
    echo "Alle Pruefungen bestanden.\n";
    exit(0);
}
echo "$failures Pruefung(en) FEHLGESCHLAGEN.\n";
exit(1);
