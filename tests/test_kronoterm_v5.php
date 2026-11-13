<?php
/** Offline regression for documented BMS descriptions, code profiles and bitmask strings. */
require_once __DIR__ . '/test_kronoterm_v4.php';

$defs = json_decode((string)file_get_contents(__DIR__ . '/../WPHub/libs/KronotermModbusDefinitions.json'), true, 512, JSON_THROW_ON_ERROR);
$hints = json_decode((string)file_get_contents(__DIR__ . '/../WPHub/libs/KronotermFieldDescriptions.json'), true, 512, JSON_THROW_ON_ERROR);
ok(count($hints) === 891, 'All 891 field identifiers from the actual Cloud inventory retained in description catalog');
foreach ($hints as $path => $hint) {
    [$meaning, $evidence] = WPHUB_KronotermExtraCatalog::documentedPurpose((string)$path, (array)$hint);
    if (trim($meaning) === '' || trim($evidence) === '') throw new RuntimeException('Missing evidence for: ' . $path);
}
ok(true, 'Every inventoried field has non-empty purpose and traceable evidence/classification');

$manual = WPHUB_KronotermExtraCatalog::documentedPurpose('main.ModbusReg[2117]', $hints['main.ModbusReg[2117]']);
ok(str_contains($manual[1], 'BMS 17-20-28-4023-04') && str_contains($manual[1], 'S. 22'), 'BMS documentation and detail page identified for R2117');
$unknown = WPHUB_KronotermExtraCatalog::documentedPurpose('main.ModbusReg[2192]', $hints['main.ModbusReg[2192]']);
ok(str_contains($unknown[0], 'nicht belegt'), 'Undocumented raw register not assigned speculative meaning');
$api = WPHUB_KronotermExtraCatalog::documentedPurpose('info.TemperaturesAndConfig.some_future_field');
ok(str_contains($api[0], 'nicht dokumentiert'), 'Unknown future Cloud path safely described');
$inferred = WPHUB_KronotermExtraCatalog::documentedPurpose('loop1.HeatingCircleData.circle_temp', $hints['loop1.HeatingCircleData.circle_temp']);
ok(str_contains($inferred[1], 'keine offizielle API-Felddefinition'), 'Inference from Cloud key clearly distinguished from official BMS evidence');

// Mapped raw codes must receive an INTEGER association profile, preserving id.
$statusSnap = [
    'pages' => ['main' => ['ModbusReg' => [
        ['address'=>2001,'value'=>5], ['address'=>2006,'value'=>3], ['address'=>2007,'value'=>1],
        ['address'=>2101,'value'=>'33.3 °C'],['address'=>2104,'value'=>'35.0 °C']
    ]]], 'inventory'=>[]
];
$statusMapped = WPHUB_KronotermDataMapper::mapSnapshot($statusSnap);
ok(($statusMapped['Fehlerstatus']['value'] ?? null) === 3, 'BMS status 3=Benachrichtigung is no longer rejected');
$instance->props['Manufacturer'] = 'kronoterm';
$mappedMethod = new ReflectionMethod(WPHub::class, 'maintainKronotermMeasurements');
$mappedMethod->invoke($instance, 'K_', 'Kronoterm Test', $statusMapped);
$expect = [
    ['Fehlerstatus', 2006, 3, 'Benachrichtigung'],
    ['KTBetriebsfunktion', 2001, 5, 'Standby'],
    ['KTBetriebsrichtung', 2007, 1, 'Heizen'],
];
foreach ($expect as [$ident, $reg, $code, $caption]) {
    $id = $instance->ids['K_'.$ident] ?? 0;
    $profile = WPHUB_KronotermModbusProfiles::profileName('main.ModbusReg['.$reg.']', '') . '.Integer';
    ok($id > 0 && ($mockVars[$id]['profile'] ?? '') === $profile, "Mapped R$reg has dedicated integer status profile");
    ok(($mockProfiles[$profile]['type'] ?? -1) === VARIABLETYPE_INTEGER, "Mapped R$reg profile has proper integer type");
    ok(($mockProfiles[$profile]['choices'][(string)$code] ?? '') === $caption, "Mapped R$reg accurately decodes $code");
}
$before = $instance->ids['K_Fehlers' . 'tatus'];
$mappedMethod->invoke($instance, 'K_', 'Kronoterm Test', $statusMapped);
ok($before === $instance->ids['K_Fehlers' . 'tatus'], 'Existing mapped status variable IDs remain stable');

// Additional bitfields require companion strings; multiple bit meanings.
ok(count($defs['2117']['bits']) === 13, 'BMS S.22: 13 documented warning-bit meanings installed');
ok(($defs['2046']['bits']['8'] ?? '') !== '', 'BMS S.14: thermostat regulation bit b8 installed');
ok(($defs['2118']['bits']['15'] ?? '') !== '', 'BMS S.23: inverter fault bit b15 installed');
ok(str_contains(WPHUB_KronotermModbusProfiles::displayValue('main.ModbusReg[2017]', ['value'=>257]), 'Bit b8 gesetzt'), 'Documented operating-mode flag b8 combined with mode code without losing meaning');
ok(($defs['2339']['bits']['9'] ?? '') !== '', 'BMS S.31: upper/lower buffer sensor diagnostic bits installed');
$path = 'main.ModbusReg[2117]';
$bitval = (1 << 0) | (1 << 8) | (1 << 9); // warning high pressure, no flow, unknown bit9
$bitReport = WPHUB_KronotermMeasurementCatalog::report([
    'pages'=>['main'=>['ModbusReg'=>[['address'=>2117,'value'=>$bitval]]]],
    'inventory'=>[], 'page_status'=>[]], []);
$bitSaved = [['Path'=>$path, 'Unit'=>'', 'Active'=>true, 'Archive'=>true]];
$instance->props['KR_ExtraValues'] = json_encode($bitSaved);
$instance->attrs['KR_CloudCatalog'] = json_encode($bitReport);
$method->invoke($instance,false);
$rawID = $instance->ids[WPHUB_KronotermExtraCatalog::ident($path)] ?? 0;
$meaningID = $instance->ids[WPHUB_KronotermExtraCatalog::ident($path).'Bedeutung'] ?? 0;
ok($rawID > 0 && $meaningID > 0, 'Activating a documented bitfield creates numeric + readable variables');
ok(($mockVars[$rawID]['type'] ?? -1) === VARIABLETYPE_FLOAT && ($mockVars[$meaningID]['type'] ?? -1) === VARIABLETYPE_STRING, 'Raw bitmask remains numeric; meaning is a string');
$method->invoke($instance,true);
$readable = $mockVars[$meaningID]['value'] ?? '';
ok(str_contains($readable, 'High pressure - heating') && str_contains($readable, 'No flow'), 'Multiple active documented bits shown together');
ok(str_contains($readable, 'b9: Bedeutung nicht dokumentiert'), 'Unknown active bit not assigned an invented cause');
ok(($mockVars[$rawID]['value'] ?? 0) === (float)$bitval, 'Original bitmask remains preserved for technical consumers');
ok(AC_GetLoggingStatus(999, $rawID), 'Archive checkbox still archives original bitmask');
$oldID = $meaningID;
$method->invoke($instance, false);
ok($oldID === $instance->ids[WPHUB_KronotermExtraCatalog::ident($path).'Bedeutung'], 'Companion variable ID preserved after ApplyChanges');
$instance->props['KR_ExtraValues'] = json_encode([['Path'=>$path,'Unit'=>'','Active'=>false,'Archive'=>false]]);
$method->invoke($instance,false);
ok(($mockVars[$meaningID]['hidden'] ?? false) === true, 'Deactivation hides companion while preserving historic variables');

// The Symcon form actually renders the evidence column.
$form = json_decode((string)file_get_contents(__DIR__.'/../WPHub/form.json'),true,512,JSON_THROW_ON_ERROR);
$foundEvidence = false;
foreach (($form['elements'] ?? []) as $section) {
    foreach (($section['items'] ?? []) as $element) {
        if (($element['name'] ?? '') !== 'KR_ExtraValues') continue;
        foreach (($element['columns'] ?? []) as $column) {
            if (($column['name'] ?? '') === 'Evidence') $foundEvidence = true;
        }
    }
}
ok($foundEvidence, 'Source citation/evidence column is actually included in Symcon configuration form');
echo "ALL V5 OFFLINE TESTS PASSED\n";
