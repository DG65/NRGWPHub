<?php
/** Offline tests: php .tools/test-kronoterm-mapper.php */
require_once __DIR__ . '/../../WPHub/libs/KronotermDataMapper.php';
function checkMap(bool $pass, string $name): void {
    if (!$pass) throw new RuntimeException('FAILED: ' . $name);
    echo 'OK  ' . $name . "\n";
}
$regs = [
    ['address' => 2103, 'value' => '-3.5'],
    ['address' => 2101, 'value' => '31.5'],
    ['address' => 2104, 'value' => '38.0'],
    ['address' => 2001, 'value' => 0],
    ['address' => 2011, 'value' => 0],
    ['address' => 2006, 'value' => 2],
    ['address' => 2327, 'value' => 55],
    ['address' => 2090, 'value' => 1200],
    ['address' => 2107, 'value' => 40.0],
    ['address' => 2105, 'value' => 5000],
    ['address' => 2106, 'value' => 'unknown'],
];
$pages = ['main' => ['ModbusReg' => $regs],
          'loop1' => ['TemperaturesAndConfig' => ['heating_circle_1_temp' => '23.1'], 'HeatingCircleData' => ['circle_temp' => '24']],
          'loop2' => ['TemperaturesAndConfig' => ['heating_circle_2_temp' => 25]],
          'dhw' => ['TemperaturesAndConfig' => ['tap_water_temp' => 48.5], 'HeatingCircleData' => ['circle_temp' => 52.0]],
          'pool' => ['TemperaturesAndConfig' => ['pool_temp' => 27]],
          'reservoir' => ['HeatingCircleData' => ['circle_calc_temp' => 500, 'circle_temp' => 40]],
];
$inventory = ['loop1' => true, 'loop2' => false, 'loop3' => null, 'dhw' => false, 'pool_active' => false, 'alternative_source_sensor_visible' => false];
$mapped = WPHUB_KronotermDataMapper::mapSnapshot(['pages' => $pages, 'inventory' => $inventory]);
checkMap($mapped['Aussentemperatur']['value'] === -3.5, 'Outdoor temperature decoded');
checkMap($mapped['KTWPEintritt']['value'] === 31.5, 'HP inlet decoded');
checkMap($mapped['KTWPAustritt']['value'] === 38.0, 'HP outlet decoded');
checkMap(!isset($mapped['Vorlauftemperatur']) && !isset($mapped['Puffertemperatur']), 'No assumed heating-circuit flow or buffer');
checkMap($mapped['KTBetriebsfunktion']['value'] === 0 && $mapped['BetriebsartNorm']['value'] === 1, 'Working function normalized');
checkMap($mapped['Abtaubetrieb']['value'] === false, 'False defrost preserved');
checkMap($mapped['Fehlerstatus']['value'] === 2, 'Error status 0-2');
checkMap($mapped['WPAuslastung']['value'] === 55.0, 'Load decoded');
checkMap($mapped['KTVerdichterstundenHeizen']['value'] === 1200, 'Compressor hours decoded');
checkMap(!isset($mapped['KTVerdichterEintritt']) && !isset($mapped['KTVerdichterAustritt']), 'Implausible measurements omitted');
checkMap(!isset($mapped['KTAlternativeQuelleTemperatur']), 'Unconfigured alternative source sensor suppressed');
checkMap(isset($mapped['KTKreis1Temperatur']) && !isset($mapped['KTKreis2Temperatur']), 'Loop flags honored');
checkMap(!isset($mapped['Zone1Ist']), 'Heating circuit semantic not guessed');
checkMap(!isset($mapped['Warmwasser']) && !isset($mapped['WarmwasserSoll']), 'Unconfigured DHW skipped');
checkMap(!isset($mapped['KTPoolTemperatur']), 'Pool active flag honored');
checkMap(!isset($mapped['PowerID']) && !isset($mapped['EnergyID']), 'Meter contract unchanged');
$inventory['dhw'] = true; $inventory['pool_active'] = true;
$mapped = WPHUB_KronotermDataMapper::mapSnapshot(['pages' => $pages, 'inventory' => $inventory]);
checkMap($mapped['Warmwasser']['value'] === 48.5 && $mapped['WarmwasserSoll']['value'] === 52.0, 'DHW values when enabled');
checkMap($mapped['KTPoolTemperatur']['value'] === 27.0, 'Pool temperature when active');
$pages['main']['ModbusReg'][3]['value'] = 7; // remote deactivation: not a heating mode
$mapped = WPHUB_KronotermDataMapper::mapSnapshot(['pages' => $pages, 'inventory' => $inventory]);
checkMap($mapped['BetriebsartNorm']['value'] === -1, 'Unmappable mode unknown');
$pages['main']['ModbusReg'][0]['value'] = '126';
$mapped = WPHUB_KronotermDataMapper::mapSnapshot(['pages' => $pages, 'inventory' => $inventory]);
checkMap(!isset($mapped['Aussentemperatur']), 'Implausible outside temperature omitted');

$extended = WPHUB_KronotermDataMapper::mapSnapshot([
    'pages' => [
        'main' => ['ModbusReg' => [
            ['address'=>2371,'value'=>420], ['address'=>2372,'value'=>350],
            ['address'=>2329,'value'=>2800], ['address'=>2361,'value'=>1],
            ['address'=>2362,'value'=>2], ['address'=>2363,'value'=>0],
            ['address'=>2364,'value'=>100], ['address'=>2028,'value'=>3],
            ['address'=>2045,'value'=>1], ['address'=>2160,'value'=>21.4],
        ]],
        'loop1' => ['TemperaturesAndConfig' => []],
    ],
    'inventory' => ['loop1'=>true,'dhw'=>false],
]);
checkMap(abs($extended['KTCOP']['value'] - 4.2) < 0.001, 'COP dynamically scaled');
checkMap(abs($extended['KTSCOP']['value'] - 3.5) < 0.001, 'SCOP dynamically scaled');
checkMap($extended['KTThermischeLeistung']['value'] === 2800.0, 'Thermal output not electric power');
checkMap(!isset($extended['KTEnergieElektrischKumuliert']), 'Cloud electric counter is NOT mapped (power/energy only from external meters)');
checkMap($extended['KTEnergieThermischKumuliert']['value'] === 10.0, 'Thermal counter scaling');
checkMap(!isset($extended['EnergyID']) && !isset($extended['PowerID']), 'No unverified direct contract mapping');
checkMap($extended['KTUmwaelzpumpe']['value'] === true && !isset($extended['KTWarmwasserUmwaelzpumpe']), 'Bit pump without unconfigured DHW');
checkMap($extended['KTKreis1Pumpe']['value'] === true, 'Configured loop pump');
checkMap($extended['KTKreis1Raumtemperatur']['value'] === 21.4, 'Configured loop thermostat');
echo "All Kronoterm-mapper tests passed.\n";
