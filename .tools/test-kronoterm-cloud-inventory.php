<?php
/** Pure offline checks for Cloud field inventory, unused-data reporting and buffer selection. */
require_once __DIR__ . '/../WPHub/libs/KronotermMeasurementCatalog.php';
require_once __DIR__ . '/../WPHub/libs/KronotermBufferMapper.php';
require_once __DIR__ . '/../WPHub/libs/KronotermDataMapper.php';
require_once __DIR__ . '/../WPHub/libs/KronotermEnergyMapper.php';

function verify(bool $condition, string $name): void
{
    if (!$condition) throw new RuntimeException('FAIL: ' . $name);
    echo "OK  {$name}\n";
}

$snapshot = [
    'inventory' => ['loop1' => true, 'dhw' => false, 'pool_active' => false, 'heating_buffer' => null],
    'pages' => [
        'info' => ['TemperaturesAndConfig' => ['circle_1_installed' => 'true', 'tap_water_installed' => false],
                   'InfoData' => ['pumpModel' => 'VERSI-X', 'serialNumber' => 'privateSerial', 'controlState' => null]],
        'main' => ['ModbusReg' => [
            ['address' => 2103, 'value' => 6.5],
            ['address' => 2028, 'value' => 3],
            ['address' => 2361, 'value' => 1],
            ['address' => 2362, 'value' => 2],
            ['address' => 2500, 'value' => 92],
        ], 'extraCloud' => 12.75, 'sessionId' => 'someSession'],
        'reservoir' => ['TemperaturesAndConfig' => [
            'buffer_top_temp' => 42.4,
            'buffer_bottom_temperature' => '36,5',
            'circle_calc_temp' => 500.0,
            'buffer_target_temp' => 48,
            'buffer_temp_visible' => true,
            'some_temp' => 9999,
        ]],
        'loop1' => ['TemperaturesAndConfig' => ['heating_circle_1_temp' => 31.8]],
        'consumption' => ['trend_consumption' => [
            'CompHeating' => [1.0, 2.0], 'CompTapWater' => [0.0, 0.4],
            'CPLoops' => [0.3], 'CPAddSource' => [0.0],
        ]],
    ],
    'page_status' => ['info'=>'available','main'=>'available','reservoir'=>'available','consumption'=>'available'],
];

$catalog = WPHUB_KronotermMeasurementCatalog::collect($snapshot);
$e = $catalog['entries'];
verify(isset($e['reservoir.TemperaturesAndConfig.buffer_top_temp']), 'Cloud top temperature discovered');
verify(isset($e['reservoir.TemperaturesAndConfig.buffer_bottom_temperature']), 'Cloud bottom temperature discovered');
verify(($e['reservoir.TemperaturesAndConfig.buffer_top_temp']['selectable'] ?? false) === true, 'Plausible temperature selectable');
verify(($e['reservoir.TemperaturesAndConfig.circle_calc_temp']['selectable'] ?? false) === false, 'Calculated temperature not selectable');
verify(($e['reservoir.TemperaturesAndConfig.buffer_target_temp']['selectable'] ?? false) === false, 'Target temperature not selectable');
verify(($e['reservoir.TemperaturesAndConfig.buffer_temp_visible']['selectable'] ?? false) === false, 'Boolean flag not selectable');
verify(($e['reservoir.TemperaturesAndConfig.some_temp']['selectable'] ?? false) === false, 'Implausible temperature not selectable');
verify(!isset($e['info.InfoData.serialNumber']) && !isset($e['main.sessionId']), 'Sensitive fields excluded');
verify(isset($e['info.InfoData.controlState']) && $e['info.InfoData.controlState']['value'] === null, 'Explicit null is inventoried');
verify(isset($e['main.ModbusReg[2500]']), 'Unknown Cloud register inventoried');
verify(!isset($e['main.ModbusReg[0]']), 'Register path is address, not array position');
verify(isset($e['consumption.trend_consumption.CompHeating[1]']), 'Energy trend indexed path inventoried');
verify(WPHUB_KronotermMeasurementCatalog::readTemperature($snapshot, 'reservoir.TemperaturesAndConfig.buffer_top_temp') === 42.4, 'Selected Cloud temperature resolved');
verify(WPHUB_KronotermMeasurementCatalog::readTemperature($snapshot, 'reservoir.TemperaturesAndConfig.circle_calc_temp') === null, 'Calculated value cannot be mapped');
verify(WPHUB_KronotermMeasurementCatalog::readTemperature($snapshot, 'main.ModbusReg[2500]') === null, 'Unknown raw register cannot masquerade as temperature');

$config = ['layout' => 'heating', 'channels' => [
    'heat_top' => ['source' => 'cloud', 'cloud_path' => 'reservoir.TemperaturesAndConfig.buffer_top_temp'],
    'heat_bottom' => ['source' => 'external', 'external_id' => 1234],
]];
$buffer = WPHUB_KronotermBufferMapper::mapSnapshot($snapshot, $config, static fn(int $id): ?float => $id === 1234 ? 35.9 : null);
verify(($buffer['KTHeizpufferOben']['value'] ?? null) === 42.4, 'Heating buffer top linked to Cloud path');
verify(($buffer['KTHeizpufferUnten']['value'] ?? null) === 35.9, 'Heating buffer bottom linked to external Symcon variable');
verify(!isset($buffer['KTKuehlpufferOben']), 'Heating-only layout creates no cooling buffer');

$maps = WPHUB_KronotermDataMapper::mapSnapshot($snapshot);
$energy = WPHUB_KronotermEnergyMapper::mapDaily($snapshot['pages']['consumption']);
$report = WPHUB_KronotermMeasurementCatalog::report($snapshot, array_merge($maps, $buffer, $energy), null, 1700000000);
$r = $report['entries'];
verify(($r['main.ModbusReg[2103]']['status'] ?? null) === 'mapped', 'Actual outdoor register classified mapped');
verify(($r['main.ModbusReg[2028]']['status'] ?? null) === 'mapped', 'Bitfield source classified mapped');
verify(($r['main.ModbusReg[2361]']['status'] ?? null) === 'mapped' && ($r['main.ModbusReg[2362]']['status'] ?? null) === 'mapped', 'Paired counter source expanded');
verify(($r['main.ModbusReg[2500]']['status'] ?? null) === 'unused', 'Unmapped register reported');
verify(($r['main.extraCloud']['status'] ?? null) === 'unused', 'Unused JSON value reported');
verify(($r['info.TemperaturesAndConfig.circle_1_installed']['status'] ?? null) === 'configuration', 'Inventory field classified internal use');
verify(($r['reservoir.TemperaturesAndConfig.buffer_top_temp']['status'] ?? null) === 'buffer', 'Selected Cloud buffer mapped');
verify(($r['reservoir.TemperaturesAndConfig.buffer_bottom_temperature']['status'] ?? null) === 'unused', 'Unselected temperature remains unused');
verify(($r['consumption.trend_consumption.CompHeating[1]']['status'] ?? null) === 'mapped', 'Daily energy trend recognized via mapper source');
verify(count(WPHUB_KronotermMeasurementCatalog::rows($report, true)) === $report['counts']['unused'], 'Unused list count matches report');
verify(count(WPHUB_KronotermMeasurementCatalog::rows($report)) === count($report['entries']), 'Full list includes all inventoried values');
$options = WPHUB_KronotermMeasurementCatalog::temperatureOptions($report, 'reservoir.TemperaturesAndConfig.old_temperature');
verify(count(array_filter($options, static fn($o) => $o['value'] === 'reservoir.TemperaturesAndConfig.buffer_top_temp')) === 1, 'Temperature dropdown contains measured path');
verify(count(array_filter($options, static fn($o) => $o['value'] === 'reservoir.TemperaturesAndConfig.circle_calc_temp')) === 0, 'Temperature dropdown omits calculated target');
verify(count(array_filter($options, static fn($o) => $o['value'] === 'reservoir.TemperaturesAndConfig.old_temperature')) === 1, 'Saved missing selection preserved');

$next = $snapshot;
unset($next['pages']['consumption']);
$next['page_status']['consumption'] = 'not_queried_this_cycle';
$after = WPHUB_KronotermMeasurementCatalog::report($next, array_merge($maps, $buffer), $report, 1700000300);
verify(($after['entries']['consumption.trend_consumption.CompHeating[1]']['fresh'] ?? true) === false, 'Previous daily energy retained as old reading');
verify(($after['entries']['consumption.trend_consumption.CompHeating[1]']['seen_at'] ?? 0) === 1700000000, 'Energy last-seen timestamp not falsified');
verify(($after['entries']['consumption.trend_consumption.CompHeating[1]']['status'] ?? '') === 'mapped', 'Stale energy still classified mapped');
verify(($after['entries']['main.extraCloud']['fresh'] ?? false) === true, 'Current non-energy measurement marked fresh');

// Contract and UI wiring checks without an IP-Symcon runtime.
$form = json_decode((string)file_get_contents(__DIR__ . '/../WPHub/form.json'), true, 512, JSON_THROW_ON_ERROR);
$search = function (array $nodes, string $name) use (&$search) {
    foreach ($nodes as $node) {
        if (($node['name'] ?? null) === $name) return $node;
        if (isset($node['items'])) {
            $item = $search($node['items'], $name);
            if ($item !== null) return $item;
        }
    }
    return null;
};
foreach (['KR_HeatTopCloudPath', 'KR_HeatBottomCloudPath', 'KR_CoolTopCloudPath', 'KR_CoolBottomCloudPath'] as $name) {
    verify(($search($form['elements'], $name)['type'] ?? null) === 'Select', $name . ' uses a dropdown');
}
foreach (['KR_CloudAllValues', 'KR_CloudUnusedValues'] as $name) {
    $node = $search($form['elements'], $name);
    verify(($node['type'] ?? null) === 'List' && ($node['loadValuesFromConfiguration'] ?? true) === false, $name . ' is a runtime list');
}
verify(($search($form['elements'], 'KronotermValuesPanel')['type'] ?? null) === 'ExpansionPanel', 'Cloud field inventory panel registered');
$module = (string)file_get_contents(__DIR__ . '/../WPHub/module.php');
verify(str_contains($module, "WriteAttributeString('KR_CloudCatalog'"), 'Module persists sanitized catalog');
verify(str_contains($module, "GetKronotermCloudInventory()"), 'Module exposes diagnostic report');
verify(str_contains($module, 'refreshKronotermCloudForm()'), 'Live form refresh integrated');

echo "All Kronoterm-cloud-inventory tests passed.\n";
