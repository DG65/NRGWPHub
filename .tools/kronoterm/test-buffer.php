<?php
require_once __DIR__ . '/../../WPHub/libs/KronotermBufferMapper.php';
function testBuffer(bool $ok, string $name): void {
    if (!$ok) { throw new RuntimeException('FAILED: ' . $name); }
    echo 'OK  ' . $name . "\n";
}
$snapshot = ['inventory' => ['heating_buffer' => null, 'cooling_buffer' => null], 'pages' => [
    'reservoir' => ['TemperaturesAndConfig' => ['top_temperature' => 40.5, 'bottom_temp' => '32,6', 'top_calc_temp' => 500, 'target_temp' => 50]],
    'main' => ['ModbusReg' => [['address' => 2101, 'value' => 34.0]]],
]];
$read = static fn(int $id) => [102 => 43.2, 103 => 33.5, 203 => 18.5, 204 => 21.2][$id] ?? null;
$channels = [
    'heat_top' => ['source' => 'external', 'external_id' => 102],
    'heat_bottom' => ['source' => 'cloud', 'cloud_path' => 'reservoir.TemperaturesAndConfig.bottom_temp'],
    'cool_top' => ['source' => 'external', 'external_id' => 203],
    'cool_bottom' => ['source' => 'external', 'external_id' => 204],
];
$map = static fn($layout, $ch = null, $s = null) => WPHUB_KronotermBufferMapper::mapSnapshot($s ?? $snapshot, ['layout' => $layout, 'channels' => $ch ?? $channels], $read);
testBuffer($map('auto') === [], 'Unknown inventory does not invent a buffer');
testBuffer($map('none') === [], 'No buffer creates no variables');
$h = $map('heating');
testBuffer(count($h) === 2 && $h['KTHeizpufferOben']['value'] === 43.2, 'Heating top from external source');
testBuffer($h['KTHeizpufferUnten']['value'] === 32.6, 'Heating bottom from cloud source');
testBuffer(!isset($h['KTKuehlpufferOben']), 'Heating only does not create cooling buffer variables');
$c = $map('cooling');
testBuffer(count($c) === 2 && $c['KTKuehlpufferOben']['value'] === 18.5, 'Cooling only');
$s = $map('separate');
testBuffer(count($s) === 4, 'Separate heating and cooling buffers');
$b = $map('combined');
testBuffer(count($b) === 2 && isset($b['KTKombipufferOben']), 'Combined buffer has one physical pair');
testBuffer(!isset($b['KTHeizpufferOben']) && !isset($b['KTKuehlpufferOben']), 'Combined buffer does not duplicate sensors');
$invalid = $channels; $invalid['heat_bottom']['cloud_path'] = 'reservoir.HeatingCircleData.circle_calc_temp';
testBuffer(!isset($map('heating', $invalid)['KTHeizpufferUnten']), 'Calculated/setpoint field rejected');
$invalid['heat_bottom']['cloud_path'] = 'reservoir.TemperaturesAndConfig.target_temp';
testBuffer(!isset($map('heating', $invalid)['KTHeizpufferUnten']), 'Target field rejected');
$invalid = $channels; $invalid['heat_top']['source'] = 'off';
testBuffer(!isset($map('heating', $invalid)['KTHeizpufferOben']), 'Disabled channel omitted');
$invalid = $channels; $invalid['heat_top']['external_id'] = 0;
testBuffer(!isset($map('heating', $invalid)['KTHeizpufferOben']), 'Unselected external variable omitted');
$invalid = $channels; $invalid['heat_top']['source'] = 'cloud'; $invalid['heat_top']['cloud_path'] = 'main.ModbusReg[2101]';
testBuffer($map('heating', $invalid)['KTHeizpufferOben']['value'] === 34.0, 'Explicit register path allowed');
$invalid['heat_top']['cloud_path'] = '';
testBuffer(!isset($map('heating', $invalid)['KTHeizpufferOben']), 'No guessed Cloud register');
$invalid['heat_top']['cloud_path'] = 'reservoir.TemperaturesAndConfig.top_calc_temp';
testBuffer(!isset($map('heating', $invalid)['KTHeizpufferOben']), 'Calculated source suppressed');
$inventory = $snapshot; $inventory['inventory'] = ['heating_buffer' => true, 'cooling_buffer' => false];
testBuffer(count($map('auto', null, $inventory)) === 2, 'Auto detects reliable heating-only flags');
$inventory['inventory'] = ['heating_buffer' => true, 'cooling_buffer' => true];
testBuffer($map('auto', null, $inventory) === [], 'Both installed does not assume combined vs separate');
$inventory['inventory'] = ['heating_buffer' => false, 'cooling_buffer' => false];
testBuffer($map('auto', null, $inventory) === [], 'Confirmed no buffers');
$invalidSnapshot = $snapshot; $invalidSnapshot['pages']['reservoir']['TemperaturesAndConfig']['bottom_temp'] = 500;
testBuffer(!isset($map('heating', null, $invalidSnapshot)['KTHeizpufferUnten']), 'Out-of-range temperature rejected');
testBuffer(WPHUB_KronotermBufferMapper::resolveLayout('other', []) === null, 'Unsupported layout rejected');
echo "All Kronoterm-buffer tests passed.\n";
