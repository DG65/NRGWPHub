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
    public $rejectFirstGroups = false;  // erste getGroups-Antwort: 4106
    public $refreshedVersion = null;    // was refreshAppVersion liefern soll
    public $groupCalls = 0;
    private $fakeRejected = false;

    public function getGroups(array $bundle): ?array
    {
        $this->groupCalls++;
        if ($this->rejectFirstGroups) {
            $this->rejectFirstGroups = false;
            $this->fakeRejected = true;
            return null;
        }
        $this->fakeRejected = false;
        return $this->groupsResult;
    }
    public function getAquareaStatus(array $bundle, string $gwid, bool $direct = true): ?array
    {
        return $this->statusResult;
    }
    public function versionRejected(): bool
    {
        return $this->fakeRejected;
    }
    public function refreshAppVersion(): ?string
    {
        return $this->refreshedVersion;
    }

    public $agreementStatuses = [];  // typeId => Status laut Cloud
    public $acceptCalls = [];        // aufgezeichnete acceptAgreement-Typen
    public $acceptResult = true;

    public function getAgreementStatus(array $bundle, int $typeId): ?int
    {
        return $this->agreementStatuses[$typeId] ?? null;
    }
    public function acceptAgreement(array $bundle, int $typeId): bool
    {
        $this->acceptCalls[] = $typeId;
        return $this->acceptResult;
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

$fake = new FakeCC();
$fake->groupsResult = ['groupList' => [[
    'groupName'  => 'My House',
    'deviceList' => [
        ['deviceGuid' => 'AC-1', 'deviceName' => 'Klima Buero', 'parameters' => ['operate' => 1]],
        ['deviceGuid' => 'B25XXXXXX-1', 'deviceName' => 'Aquarea'],
    ],
]]];
$fake->statusResult = [
    'operation' => '1',
    'ownerFlg'  => true,
    'a2wName'   => 'Aquarea Zuhause',
    'status'    => [
        'operationMode' => 1,
        'outdoorNow'    => 17,
        'tank'          => 1,
        'tankStatus'    => ['operationStatus' => 1, 'temperatureNow' => 48, 'heatMin' => 40, 'heatMax' => 65, 'heatSet' => 52],
        'zoneStatus'    => [
            ['zoneId' => 1, 'zoneName' => 'Haus', 'zoneType' => 0, 'zoneSensor' => 1, 'operationStatus' => 1, 'temperatureNow' => 22, 'heatSet' => 21, 'coolSet' => 25, 'heatMin' => 5, 'heatMax' => 30, 'coolMin' => 16, 'coolMax' => 30, 'ecoHeat' => -2, 'ecoCool' => 2, 'comfortHeat' => 2, 'comfortCool' => -2],
            ['zoneId' => 2, 'zoneName' => '', 'zoneType' => 0, 'zoneSensor' => 1, 'operationStatus' => 0, 'temperatureNow' => 126, 'heatSet' => 126],
        ],
    ],
];

$refresh = new ReflectionMethod(WPHub::class, 'refreshDevices');
$refresh->setAccessible(true);
$devices = $refresh->invoke($mod, ['accessToken' => 'x'], $fake);

check('Genau eine Waermepumpe erkannt (Klimageraet uebersprungen)', is_array($devices) && count($devices) === 1, json_encode($devices));
check('Name aus a2wName uebernommen', $devices[0]['name'] === 'Aquarea Zuhause');
check('Geraet als erreichbar markiert', $devices[0]['reachable'] === true);

$prefix = $devices[0]['prefix'];
$vars = $GLOBALS['ips']['variables'];
check('Variable Erreichbar = true', ($vars[$prefix . 'Erreichbar']['value'] ?? null) === true);
check('Variable Betrieb = true', ($vars[$prefix . 'Betrieb']['value'] ?? null) === true);
check('Aussentemperatur 17 °C', ($vars[$prefix . 'Aussentemperatur']['value'] ?? null) === 17.0);
check('Aussentemperatur nutzt NRG.Celsius', ($vars[$prefix . 'Aussentemperatur']['profile'] ?? '') === 'NRG.Celsius');
check('Warmwasser 48 °C', ($vars[$prefix . 'Warmwasser']['value'] ?? null) === 48.0);
check('Warmwasser-Soll 52 °C', ($vars[$prefix . 'WarmwasserSoll']['value'] ?? null) === 52.0);
check('Zone 1 Ist 22 °C', ($vars[$prefix . 'Zone1Ist']['value'] ?? null) === 22.0);
check('Zone 1 Soll 21 °C', ($vars[$prefix . 'Zone1Soll']['value'] ?? null) === 21.0);
check('Zone 2 (Marker 126) legt KEINE Ist-Variable an', !isset($vars[$prefix . 'Zone2Ist']));

// ---------------------------------------------------------------------------
echo "Block 3: EMS-Vertrag (GetFunctions)\n";
// ---------------------------------------------------------------------------

$functions = $mod->GetFunctions();
check('Ein Vertragseintrag', count($functions) === 1);
$f = $functions[0] ?? [];
check('Type = heatpump', ($f['Type'] ?? '') === 'heatpump');
check('contractVersion = 1.2', ($f['contractVersion'] ?? '') === '1.2');
check('Caption = Geraetename', ($f['Caption'] ?? '') === 'Aquarea Zuhause');
check('PowerID = 0 (Cloud liefert keine Leistung)', ($f['PowerID'] ?? -1) === 0);
check('EnergyID = 0 (keine kumulative Energie)', ($f['EnergyID'] ?? -1) === 0);
check('Measured = false', ($f['Measured'] ?? true) === false);
check('unit = W', ($f['unit'] ?? '') === 'W');
check('reachable = true (live aus Variable)', ($f['reachable'] ?? false) === true);

// Cloud-Ausfall: alle Geraete unerreichbar, Variablen bleiben bestehen.
$markAll = new ReflectionMethod(WPHub::class, 'markAllUnreachable');
$markAll->setAccessible(true);
$markAll->invoke($mod);
$functions = $mod->GetFunctions();
check('Nach Cloud-Ausfall: reachable = false', ($functions[0]['reachable'] ?? true) === false);
check('Variablen bleiben nach Ausfall erhalten', isset($GLOBALS['ips']['variables'][$prefix . 'Aussentemperatur']));

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

// Nutzungsbedingungen offen (Status 0), Datenschutz bereits bestaetigt (1):
// genau Typ 1 wird akzeptiert, danach laedt die Geraeteliste, Status 102.
$fake4 = new FakeCC();
$fake4->groupsResult = $fake->groupsResult;
$fake4->statusResult = $fake->statusResult;
$fake4->agreementStatuses = [1 => 0, 2 => 1];
$mod->status = 202;
$doAccept->invoke($mod, $fake4, ['accessToken' => 'x'], $say);
check('Nur die offene Bedingung (Typ 1) bestaetigt', $fake4->acceptCalls === [1], json_encode($fake4->acceptCalls));
check('Danach Status 102', $mod->status === 102, 'Status ' . $mod->status);
check('Erfolgsmeldung mit Geraeteliste', strpos(end($sayMessages), 'Aquarea Zuhause') !== false, end($sayMessages));

// Ablehnung durch die Cloud: sauberer Abbruch mit Fehlermeldung, kein 102.
$fake5 = new FakeCC();
$fake5->agreementStatuses = [1 => 0, 2 => 0];
$fake5->acceptResult = false;
$mod->status = 202;
$sayMessages = [];
$doAccept->invoke($mod, $fake5, ['accessToken' => 'x'], $say);
check('Fehlgeschlagene Zustimmung bricht ab', $fake5->acceptCalls === [1], json_encode($fake5->acceptCalls));
check('Status bleibt 202', $mod->status === 202, 'Status ' . $mod->status);
check('Fehlermeldung ausgegeben', strpos(end($sayMessages), '❌') === 0, end($sayMessages));

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
