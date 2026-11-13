<?php
/** Run from the repository root: php tests/test_kronoterm_v4.php */
require_once __DIR__ . '/test_kronoterm_v3.php';

$definitions = json_decode((string)file_get_contents(__DIR__ . '/../WPHub/libs/KronotermModbusDefinitions.json'), true);
ok(count($definitions) === 168, '167 populated BMS table rows plus R2116 from the BMS fault-detail section');
foreach ($definitions as $register => $def) {
    ok(isset($def['label'], $def['kind'], $def['page'], $def['unit'], $def['factor'])
        && $def['page'] >= 6 && $def['page'] <= 22, 'Documented BMS metadata for R' . $register);
}
ok(WPHUB_KronotermModbusProfiles::definition('main.ModbusReg[2101]')['unit'] === '°C', 'PDF: HP inlet Celsius');
ok(WPHUB_KronotermModbusProfiles::definition('main.ModbusReg[2090]')['unit'] === 'h', 'PDF: compressor operating hours');
ok(WPHUB_KronotermModbusProfiles::definition('main.ModbusReg[2327]')['unit'] === '%', 'PDF: current HP load percent');
ok(WPHUB_KronotermModbusProfiles::definition('main.ModbusReg[2326]')['unit'] === 'bar', 'PDF: heating-system pressure');
ok(WPHUB_KronotermModbusProfiles::definition('main.ModbusReg[2116]')['page'] === 22, 'BMS fault register R2116 documented on p.22');
ok(WPHUB_KronotermModbusProfiles::definition('main.ModbusReg[2131]') === null, 'Undocumented 2131 does NOT get invented PDF unit');
ok(WPHUB_KronotermModbusProfiles::definition('main.ModbusReg[2092]') === null, 'PDF placeholder 2092 does NOT get a unit');

$read = fn(string $p, $v, string $kind='raw_register', string $unit='?') =>
    WPHUB_KronotermModbusProfiles::interpret($p, ['value'=>$v,'kind'=>$kind,'unit'=>$unit])['value'];
ok(abs($read('main.ModbusReg[2128]',374) - 37.4) < 0.00001, 'R2128 raw 374 -> 37.4 Celsius');
ok(abs($read('main.ModbusReg[2128]','37.4 °C') - 37.4) < 0.00001, 'R2128 formatted 37.4 Celsius NOT rescaled');
ok(abs($read('main.ModbusReg[2101]',33.3,'temperature','°C') - 33.3) < 0.00001, 'Already interpreted numeric temperature NOT rescaled');
ok(abs($read('main.ModbusReg[2326]',98) - 9.8) < 0.00001, 'R2326 raw pressure 98 -> 9.8 bar');
ok(abs($read('main.ModbusReg[2326]','9.8 bar') - 9.8) < 0.00001, 'R2326 formatted pressure NOT rescaled');
ok(abs($read('main.ModbusReg[2371]',354) - 3.54) < 0.00001, 'R2371 COP 354 -> 3.54');
ok(abs($read('main.ModbusReg[2372]',260) - 2.6) < 0.00001, 'R2372 SCOP 260 -> 2.60');
ok($read('main.ModbusReg[2362]',6955) === 6955.0, 'Energy low word stays an uncombined 16-bit register');
ok(WPHUB_KronotermModbusProfiles::definition('main.ModbusReg[2362]')['unit'] === '', 'Energy high/low word is NOT misleadingly labelled kWh');
ok(WPHUB_KronotermModbusProfiles::definition('main.ModbusReg[2026]')['choices']['2'] === 'Zeitprogramm', 'PDF operating code has meaningful association');
ok(WPHUB_KronotermModbusProfiles::definition('main.ModbusReg[2318]')['choices']['3'] === 'Beide Verdichter aktiv', 'Documented compressor bitmask meanings');
ok(str_contains(WPHUB_KronotermModbusProfiles::displayValue('main.ModbusReg[2002]', ['value'=>8,'unit'=>'?']), 'PV-Signal aktiv'), 'BMS bitfield PV status is decoded in preview');
ok(WPHUB_KronotermModbusProfiles::effectiveUnit('main.ModbusReg[2101]', '') === '°C', 'BMS unit selected automatically');
ok(WPHUB_KronotermModbusProfiles::effectiveUnit('main.ModbusReg[2101]', 'K') === 'K', 'Explicit user-selected unit remains allowed');

$v4Snapshot = ['pages'=>['main'=>['ModbusReg'=>[
    ['address'=>2128,'value'=>374],
    ['address'=>2026,'value'=>2],
    ['address'=>2090,'value'=>'3509 h'],
    ['address'=>2140,'value'=>250],
    ['address'=>2326,'value'=>'9.8 bar'],
]]], 'inventory'=>[], 'page_status'=>[]];
$v4Mapped = WPHUB_KronotermDataMapper::mapSnapshot($v4Snapshot);
$v4Report = WPHUB_KronotermMeasurementCatalog::report($v4Snapshot, $v4Mapped);
$v4Rows = WPHUB_KronotermExtraCatalog::displayRows($v4Report);
$v4ByPath = array_column($v4Rows, null, 'Path');
// Some fields can be mapped to WPHub directly (and therefore excluded). For
// remaining fields, defaults must follow the manual, not a guessed unit.
$path = 'main.ModbusReg[2128]';
ok(isset($v4ByPath[$path]), 'Raw documented, unmapped R2128 is listed for opt-in');
ok($v4ByPath[$path]['Unit'] === '°C', 'Table automatically defaults to BMS Celsius');
ok($v4ByPath[$path]['LastValue'] === '37.4 °C', 'Table preview shows scaled physical value');
ok(str_contains($v4ByPath['main.ModbusReg[2026]']['LastValue'] ?? '', 'Zeitprogramm'), 'Table preview shows BMS meaning before activation');

$v4Selection = [
    ['Path'=>$path,'Unit'=>'','Active'=>true,'Archive'=>true],
    ['Path'=>'main.ModbusReg[2026]','Unit'=>'','Active'=>true,'Archive'=>false],
    ['Path'=>'main.ModbusReg[2090]','Unit'=>'','Active'=>true,'Archive'=>false],
    ['Path'=>'main.ModbusReg[2140]','Unit'=>'','Active'=>true,'Archive'=>false],
];
$instance->props['KR_ExtraValues'] = json_encode($v4Selection);
$instance->attrs['KR_CloudCatalog'] = json_encode($v4Report);
$method->invoke($instance, false);
$id = $instance->ids[WPHUB_KronotermExtraCatalog::ident($path)] ?? 0;
$profile = WPHUB_KronotermModbusProfiles::profileName($path, '°C');
ok($id > 0 && ($mockVars[$id]['profile'] ?? '') === $profile, 'Auto-BMS profile applied on activation, without manual unit');
ok(($mockProfiles[$profile]['suffix'] ?? null) === ' °C', 'Auto-profile shows correct Celsius suffix');
ok(($mockProfiles[$profile]['digits'] ?? null) === 1, 'BMS Celsius profile shows one decimal');
ok(AC_GetLoggingStatus(999,$id), 'Optional automatic archive activation remains functional');

$statusPath='main.ModbusReg[2026]';
$statusId=$instance->ids[WPHUB_KronotermExtraCatalog::ident($statusPath)] ?? 0;
$statusProfile=WPHUB_KronotermModbusProfiles::profileName($statusPath, '');
ok($statusId > 0 && ($mockVars[$statusId]['profile'] ?? '') === $statusProfile, 'Unitless operating mode receives a dedicated profile');
ok(($mockProfiles[$statusProfile]['choices']['2'] ?? null) === 'Zeitprogramm', 'Operating mode displays documented semantic meaning');
ok(!AC_GetLoggingStatus(999,$statusId), 'Code variable is not archived unless checkbox enabled');
$hoursPath='main.ModbusReg[2090]';
$hoursId=$instance->ids[WPHUB_KronotermExtraCatalog::ident($hoursPath)] ?? 0;
$hoursProfile=WPHUB_KronotermModbusProfiles::profileName($hoursPath, 'h');
ok(($mockVars[$hoursId]['profile'] ?? '') === $hoursProfile && $mockProfiles[$hoursProfile]['suffix'] === ' h', 'BMS hours auto-profile set');
$unknownId=$instance->ids[WPHUB_KronotermExtraCatalog::ident('main.ModbusReg[2140]')] ?? 0;
ok($unknownId > 0 && ($mockVars[$unknownId]['profile'] ?? 'none') === '', 'Undocumented register gets NO invented unit/profile');

$method->invoke($instance, true);
ok(abs(($mockVars[$id]['value'] ?? 0) - 37.4) < 0.00001, 'Live poll stores scaled 37.4 Celsius as Float');
ok(($mockVars[$hoursId]['value'] ?? null) === 3509.0, 'Live poll stores formatted operating hours without scaling');
ok(($mockVars[$statusId]['value'] ?? null) === 2.0, 'Live poll retains status code numeric value and association');
$instance->props['KR_ExtraValues'] = json_encode(array_map(
    static fn($r) => array_merge($r, ['Active'=>false,'Archive'=>false]), $v4Selection));
$method->invoke($instance,false);
ok(($mockVars[$id]['hidden'] ?? false) && !AC_GetLoggingStatus(999,$id), 'Deactivation still preserves history and disables logging');
echo "ALL V4 OFFLINE TESTS PASSED\n";
