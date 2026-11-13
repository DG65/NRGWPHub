<?php
/** Offline regression tests; run `php tests/test_kronoterm_v3.php` from repo root. */
error_reporting(E_ALL);
const VARIABLETYPE_BOOLEAN = 0;
const VARIABLETYPE_INTEGER = 1;
const VARIABLETYPE_FLOAT = 2;
const VARIABLETYPE_STRING = 3;
const KL_WARNING = 2;
$mockVars = [];
$mockIdents = [];
$mockArchive = [];
$mockProfiles = [];
$mockApply = 0;
function IPS_GetInstanceListByModuleID($guid) { return [999]; }
function IPS_VariableExists($id) { global $mockVars; return isset($mockVars[$id]); }
function IPS_SetHidden($id, $hidden) { global $mockVars; $mockVars[$id]['hidden'] = $hidden; }
function IPS_VariableProfileExists($name) { global $mockProfiles; return isset($mockProfiles[$name]); }
function IPS_CreateVariableProfile($name, $type) { global $mockProfiles; $mockProfiles[$name] = ['type'=>$type]; }
function IPS_SetVariableProfileText($name, $prefix, $suffix) { global $mockProfiles; $mockProfiles[$name]['suffix'] = $suffix; }
function IPS_SetVariableProfileDigits($name, $digits) { global $mockProfiles; $mockProfiles[$name]['digits'] = $digits; }
function IPS_SetVariableProfileAssociation($name, $code, $label, $icon, $color) { global $mockProfiles; $mockProfiles[$name]['choices'][(string)$code] = $label; }
function AC_GetLoggingStatus($id, $variable) { global $mockArchive; return $mockArchive[$variable] ?? false; }
function AC_SetLoggingStatus($id, $variable, $active) { global $mockArchive; $mockArchive[$variable] = $active; }
function IPS_ApplyChanges($id) { global $mockApply; ++$mockApply; }
function GetValue($id) { global $mockVars; return $mockVars[$id]['value'] ?? false; }
class IPSModule
{
    public int $InstanceID;
    public array $props = [];
    public array $attrs = [];
    public array $ids = [];
    public function __construct($id) { $this->InstanceID = $id; }
    public function Translate($text) { return $text; }
    public function ReadPropertyString($name) { return (string)($this->props[$name] ?? ''); }
    public function ReadPropertyInteger($name) { return (int)($this->props[$name] ?? 0); }
    public function ReadPropertyBoolean($name) { return (bool)($this->props[$name] ?? false); }
    public function ReadAttributeString($name) { return (string)($this->attrs[$name] ?? ''); }
    public function ReadAttributeInteger($name) { return (int)($this->attrs[$name] ?? 0); }
    public function ReadAttributeBoolean($name) { return (bool)($this->attrs[$name] ?? false); }
    public function GetIDForIdent($ident) { return $this->ids[$ident] ?? false; }
    public function MaintainVariable($ident,$label,$type,$profile,$position,$active) {
        global $mockVars;
        if (!isset($this->ids[$ident])) $this->ids[$ident] = 1000 + count($this->ids);
        $id = $this->ids[$ident];
        $mockVars[$id] = array_merge($mockVars[$id] ?? [], ['ident'=>$ident,'label'=>$label,'type'=>$type,'profile'=>$profile]);
    }
    public function SetValue($ident,$value) { global $mockVars; $mockVars[$this->ids[$ident]]['value'] = $value; }
    public function LogMessage($text,$level) { throw new Exception($text); }
}
require_once __DIR__ . '/../WPHub/libs/KronotermDataMapper.php';
require_once __DIR__ . '/../WPHub/libs/KronotermMeasurementCatalog.php';
require_once __DIR__ . '/../WPHub/libs/KronotermExtraCatalog.php';
require_once __DIR__ . '/../WPHub/libs/KronotermModbusProfiles.php';
// The upstream baseline's unchanged libraries are not part of the v2 patch
// bundle; the module is evaluated without its require statements for offline tests.
class WPHUB_Text {
    public static function setzen($translate) {}
    public static function mitMerkliste($translate) { return $translate; }
    public static function ausFormular($s) { return $s; }
    public static function t($text, ...$args) { return $args ? sprintf($text,...$args) : $text; }
}
$source = file_get_contents(__DIR__ . '/../WPHub/module.php');
$source = preg_replace('/^<\?php\s*/', '', $source);
$source = preg_replace('/^require_once __DIR__.*?;\R/m', '', $source);
// eval() resolves __DIR__ to this test directory, so point form loading to
// the actual module's form.json for this isolated harness only.
$source = str_replace("__DIR__ . '/form.json'", "__DIR__ . '/../WPHub/form.json'", $source);
$source = str_replace("__DIR__ . '/../library.json'", "__DIR__ . '/../library.json'", $source);
eval($source);
function ok($condition, $message) { if (!$condition) throw new RuntimeException('FAIL: ' . $message); echo "OK: $message\n"; }

// Actual controller format: temperature strings with a trailing unit.
$pages = [
    'main' => ['ModbusReg' => [
        ['address'=>2101,'value'=>'33.3 °C'], ['address'=>2103,'value'=>'8.0 °C'],
        ['address'=>2104,'value'=>'35.0 °C'], ['address'=>2361,'value'=>0],
        ['address'=>2362,'value'=>6955], ['address'=>2329,'value'=>5880],
    ]],
    'info' => ['TemperaturesAndConfig' => [
        'reservoir_temp'=>34.6,'outside_temp'=>8.0, 'sensor_delta'=>1.0,
        'mode'=>1,'another_field'=>17], 'Sensor' => ['test_temp'=>27.25]],
    'loop1' => ['TemperaturesAndConfig' => [
        'reservoir_temp'=>34.6,'outside_temp'=>8.0, 'sensor_delta'=>1.0,
        'mode'=>1,'another_field'=>17], 'Sensor' => ['other_temp'=>27.25]],
];
$snapshot = ['pages'=>$pages,'inventory'=>['loop1'=>true], 'page_status'=>[]];
$mapped = WPHUB_KronotermDataMapper::mapSnapshot($snapshot);
ok(($mapped['KTWPEintritt']['value'] ?? null) === 33.3,'HP return Float from 33.3 °C');
ok(($mapped['KTWPAustritt']['value'] ?? null) === 35.0,'HP supply Float from 35.0 °C');
ok(($mapped['Aussentemperatur']['value'] ?? null) === 8.0,'Outside temperature parser');
ok(($mapped['Puffertemperatur']['value'] ?? null) === 34.6,'Upper reservoir source');
ok(WPHUB_KronotermExtraCatalog::normalizedValue('45.2 °C') === 45.2, 'Extra unit-bearing numeric values use Float');
ok(WPHUB_KronotermExtraCatalog::valueType('3.16-1') === 'string', 'Firmware text is not misparsed as Float');
ok(!isset($mapped['KTEnergieElektrischKumuliert']),'Cloud electrical meter not registered as WPHub mapped measurement');
$report = WPHUB_KronotermMeasurementCatalog::report($snapshot, $mapped);
$rows = WPHUB_KronotermExtraCatalog::displayRows($report);
$paths = array_column($rows,'Path');
ok(!in_array('info.TemperaturesAndConfig.reservoir_temp', $paths, true),'Mapped buffer field excluded');
ok(!in_array('info.TemperaturesAndConfig.outside_temp', $paths, true),'Mapped outside alias excluded');
ok(!in_array('loop1.TemperaturesAndConfig.sensor_delta', $paths, true),'Complete repeated block deduplicated');
ok(in_array('loop1.Sensor.other_temp', $paths, true) && in_array('info.Sensor.test_temp', $paths, true), 'Equal isolated sensor values NOT deduplicated');

$selection = [['Path'=>'info.Sensor.test_temp', 'Unit'=>'°C', 'Active'=>true, 'Archive'=>true]];
$saved = WPHUB_KronotermExtraCatalog::savedRows(json_encode($selection));
ok(isset(WPHUB_KronotermExtraCatalog::livePoints($report,$saved)['info.Sensor.test_temp']),'Selected live point available');

$instance = new WPHub(20198);
$instance->props = ['Manufacturer'=>'kronoterm','KR_ExtraValues'=>json_encode($selection),
    'Ext_PowerVariable'=>555,'Ext_EnergyVariable'=>556,
    'Ext_MainInletTempVariable'=>557,'Ext_MainOutletTempVariable'=>558,'Ext_BufferTempVariable'=>559];
$instance->attrs = ['KR_CloudCatalog'=>json_encode($report),'KR_DeviceList'=>json_encode([
    ['name'=>'Kronoterm Test','prefix'=>'K_', 'reachable'=>true]
])];
$method = new ReflectionMethod(WPHub::class,'synchronizeKronotermExtra');
$method->invoke($instance,false);
$id = $instance->ids[WPHUB_KronotermExtraCatalog::ident('info.Sensor.test_temp')] ?? null;
ok($id !== null && IPS_VariableExists($id), 'Active selection immediately creates instance variable');
ok($mockVars[$id]['profile'] === WPHUB_KronotermExtraCatalog::profileName('°C'), 'Variable uses proper dedicated °C profile');
ok(AC_GetLoggingStatus(999,$id), 'Archiving enabled only on explicit checkbox');
ok(!isset($mockVars[$id]['value']), 'ApplyChanges never writes stale cached Cloud readings');
$method->invoke($instance,true);
ok(($mockVars[$id]['value'] ?? null) === 27.25, 'Live poll updates selected variable');
$instance->props['KR_ExtraValues'] = json_encode([['Path'=>'info.Sensor.test_temp','Unit'=>'°C','Active'=>false,'Archive'=>false]]);
$method->invoke($instance,false);
ok(($mockVars[$id]['hidden'] ?? false) === true && !AC_GetLoggingStatus(999,$id), 'Deactivation preserves variable and stops archiving');

// Contract: external power/energy, but cloud sensors. Mock contract fields.
foreach (['K_KTWPEintritt','K_KTWPAustritt','K_Puffertemperatur'] as $ident) $instance->MaintainVariable($ident,$ident,VARIABLETYPE_FLOAT,'',0,true);
foreach ([555,556,557,558,559] as $external) $mockVars[$external] = ['value'=>17];
$functions = $instance->GetFunctions();
ok(count($functions) === 1,'One Kronoterm device returned');
$contract = $functions[0];
ok($contract['PowerID'] === 555 && $contract['EnergyID'] === 556, 'PowerID and EnergyID are external only');
ok($contract['mainInletTempID'] === $instance->ids['K_KTWPEintritt'], 'Inlet bound to Kronoterm WP sensor, not external');
ok($contract['mainOutletTempID'] === $instance->ids['K_KTWPAustritt'], 'Outlet bound to Kronoterm WP sensor, not external');
ok($contract['bufferTempID'] === $instance->ids['K_Puffertemperatur'], 'Buffer bound to verified upper sensor, not external');

$form = json_decode(file_get_contents(__DIR__.'/../WPHub/form.json'),true);
$names = array_column($form['elements'],'name');
ok(in_array('KronotermValuesPanel',$names,true),'Kronoterm values panel exists');
try {
    $actual = json_decode($instance->GetConfigurationForm(), true);
    ok(is_array($actual) && isset($actual['elements']), 'GetConfigurationForm returns valid JSON');
} catch (Throwable $e) {
    echo 'FORM TEST: ' . $e->getMessage() . "\n";
    throw $e;
}
echo "ALL OFFLINE TESTS PASSED\n";
