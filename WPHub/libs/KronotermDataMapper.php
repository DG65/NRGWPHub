<?php
/**
 * Kronoterm Cloud snapshot -> typed, quality-checked WPHub variables.
 * Source: Favio25/Kronoterm-homeassistant, const.py, climate.py, binary_sensor.py.
 * Uses Cloud's already-decoded main.ModbusReg (not a local Modbus connection).
 * Does not infer physical buffer installation or thermal vs electric power.
 */
final class WPHUB_KronotermDataMapper
{
    /** @return array<string,array{value:bool|int|float,type:string,profile:string,label:string,source:string}> */
    public static function mapSnapshot(array $snapshot): array
    {
        $pages = is_array($snapshot['pages'] ?? null) ? $snapshot['pages'] : [];
        $main = is_array($pages['main'] ?? null) ? $pages['main'] : [];
        $inventory = is_array($snapshot['inventory'] ?? null) ? $snapshot['inventory'] : [];
        $registers = self::registers($main['ModbusReg'] ?? []);
        $out = [];
        $float = static function (string $ident, string $label, string $source, $raw, string $profile = '', ?float $min = null, ?float $max = null) use (&$out): void {
            $value = self::number($raw);
            if ($value === null || ($min !== null && $value < $min) || ($max !== null && $value > $max)) {
                return;
            }
            $out[$ident] = ['value' => $value, 'type' => 'float', 'profile' => $profile, 'label' => $label, 'source' => $source];
        };
        $integer = static function (string $ident, string $label, string $source, $raw, ?int $min = null) use (&$out): void {
            $value = self::integer($raw);
            if ($value === null || ($min !== null && $value < $min)) {
                return;
            }
            $out[$ident] = ['value' => $value, 'type' => 'integer', 'profile' => '', 'label' => $label, 'source' => $source];
        };
        $boolean = static function (string $ident, string $label, string $source, $raw) use (&$out): void {
            $value = self::boolean($raw);
            if ($value === null) {
                return;
            }
            $out[$ident] = ['value' => $value, 'type' => 'boolean', 'profile' => '', 'label' => $label, 'source' => $source];
        };
        $r = static fn(int $address) => $registers[$address] ?? null;
        $float('Aussentemperatur', 'Outdoor temperature', 'main.ModbusReg[2103]', $r(2103), 'NRG.Celsius', -60, 80);
        // WP inlet/outlet are NOT necessarily the mixed heating-circuit flow/return.
        $float('KTWPEintritt', 'Kronoterm HP inlet temperature', 'main.ModbusReg[2101]', $r(2101), 'NRG.Celsius', -60, 150);
        $float('KTWPAustritt', 'Kronoterm HP outlet temperature', 'main.ModbusReg[2104]', $r(2104), 'NRG.Celsius', -60, 150);
        // Verified upper reservoir sensor in this VERSI-X installation.
        // Never substitute reservoir.HeatingCircleData.circle_calc_temp (setpoint).
        $info = is_array($pages['info'] ?? null) ? $pages['info'] : [];
        $float('Puffertemperatur', 'Kronoterm upper buffer sensor',
            'info.TemperaturesAndConfig.reservoir_temp',
            self::path($info, ['TemperaturesAndConfig', 'reservoir_temp']), 'NRG.Celsius', -40, 120);
        $float('KTVerdichterEintritt', 'Kronoterm compressor inlet temperature', 'main.ModbusReg[2105]', $r(2105), 'NRG.Celsius', -60, 150);
        $float('KTVerdichterAustritt', 'Kronoterm compressor outlet temperature', 'main.ModbusReg[2106]', $r(2106), 'NRG.Celsius', -60, 150);
        $float('Systemdruck', 'System pressure', 'main.ModbusReg[2326]', $r(2326), '', 0, 15);
        $float('WPAuslastung', 'Kronoterm HP load', 'main.ModbusReg[2327]', $r(2327), 'NRG.Percent', 0, 100);
        // Additional documented Cloud registers. Values are not automatically
        // assigned to EMS PowerID/EnergyID; their semantics must first be
        // checked at the actual controller.
        $float('KTThermischeLeistung', 'Kronoterm thermal output', 'main.ModbusReg[2329]', $r(2329), '', 0, 100000);
        $float('KTSystemdruckGrenze', 'Kronoterm pressure limit', 'main.ModbusReg[2325]', $r(2325), '', 0, 15);
        $integer('KTZusatzquelleBetriebsstunden', 'Kronoterm additional source operating hours', 'main.ModbusReg[2095]', $r(2095), 0);
        $integer('KTZusatzkesselStarts', 'Kronoterm boiler starts', 'main.ModbusReg[2157]', $r(2157), 0);
        foreach ([2371 => ['KTCOP','Kronoterm COP'], 2372 => ['KTSCOP','Kronoterm SCOP']] as $addr => [$ident, $label]) {
            $factor = self::performanceFactor($r($addr));
            if ($factor !== null) $float($ident, $label, 'main.ModbusReg[' . $addr . ']', $factor, '', 0, 20);
        }
        // Intentionally no Cloud electrical power/energy: PowerID/EnergyID
        // must be supplied solely by external, verified meters.
        $thermalCounter = self::combineWords($r(2363), $r(2364));
        if ($thermalCounter !== null) {
            $float('KTEnergieThermischKumuliert', 'Kronoterm thermal energy counter (verify)',
                'main.ModbusReg[2363:2364]', $thermalCounter * 0.1, 'NRG.kWh', 0);
        }
        $pumpBits = self::integer($r(2028));
        if ($pumpBits !== null && $pumpBits >= 0) {
            $boolean('KTUmwaelzpumpe', 'Kronoterm circulation pump', 'main.ModbusReg[2028].bit0', (bool)($pumpBits & 1));
            if (($inventory['dhw'] ?? null) !== false) {
                $boolean('KTWarmwasserUmwaelzpumpe', 'Kronoterm hot water circulation pump',
                    'main.ModbusReg[2028].bit1', (bool)($pumpBits & 2));
            }
        }
        $additional = self::integer($r(2002));
        if ($additional !== null && $additional >= 0) {
            $boolean('KTZusatzquelleAktiv', 'Kronoterm additional heat source active',
                'main.ModbusReg[2002].bit0', (bool)($additional & 1));
        }
        $boolean('KTAlternativeQuellenpumpe', 'Kronoterm alternative source pump', 'main.ModbusReg[2088]', $r(2088));
        $integer('KTVerdichterstundenHeizen', 'Kronoterm compressor heating hours', 'main.ModbusReg[2090]', $r(2090), 0);
        $integer('KTVerdichterstundenWW', 'Kronoterm compressor DHW hours', 'main.ModbusReg[2091]', $r(2091), 0);
        $integer('KTVerdichterstartsHeizen', 'Kronoterm compressor heating starts', 'main.ModbusReg[2155]', $r(2155), 0);
        $integer('KTVerdichterstartsKuehlen', 'Kronoterm compressor cooling starts', 'main.ModbusReg[2156]', $r(2156), 0);
        $integer('KTAbtauungen', 'Kronoterm defrost cycles', 'main.ModbusReg[2158]', $r(2158), 0);
        $boolean('Abtaubetrieb', 'Defrost operation', 'main.ModbusReg[2011]', $r(2011));
        $boolean('KTHauptpumpe', 'Kronoterm main pump', 'main.ModbusReg[2038]', $r(2038));
        $boolean('KTReservewaermequelle', 'Kronoterm reserve heat source', 'main.ModbusReg[2003]', $r(2003));
        $boolean('KTAlternativeWaermequelle', 'Kronoterm alternative heat source', 'main.ModbusReg[2004]', $r(2004));
        $error = self::integer($r(2006));
        if ($error !== null && $error >= 0 && $error <= 3) {
            $integer('Fehlerstatus', 'Kronoterm error status (0-3)', 'main.ModbusReg[2006]', $error);
        }
        $operation = self::integer($r(2001));
        if ($operation !== null && $operation >= 0 && $operation <= 8) {
            $integer('KTBetriebsfunktion', 'Kronoterm working function (raw)', 'main.ModbusReg[2001]', $operation);
            // NRG normalized contract: 0=standby, 1=heat, 2=cool, 3=dhw; -1 otherwise.
            $norm = [0 => 1, 1 => 3, 2 => 2, 5 => 0][$operation] ?? -1;
            $out['BetriebsartNorm'] = ['value' => $norm, 'type' => 'integer', 'profile' => 'WPHUB.BetriebsartNorm', 'label' => 'Operating mode (normalized)', 'source' => 'main.ModbusReg[2001]'];
        }
        $regime = self::integer($r(2007));
        if ($regime !== null && $regime >= 0 && $regime <= 2) {
            $integer('KTBetriebsrichtung', 'Kronoterm operation direction (raw)', 'main.ModbusReg[2007]', $regime);
        }
        foreach (['loop1', 'loop2', 'loop3', 'loop4'] as $key) {
            $n = (int) substr($key, 4);
            if (($inventory[$key] ?? null) === false || !is_array($pages[$key] ?? null)) {
                continue;
            }
            $page = $pages[$key];
            $boolean('KTKreis' . $n . 'Pumpe', 'Kronoterm loop %d circulation pump',
                'main.ModbusReg[' . (2035 + 10 * $n) . ']', $r(2035 + 10 * $n));
            $float('KTKreis' . $n . 'Raumtemperatur', 'Kronoterm loop %d thermostat temperature',
                'main.ModbusReg[' . (2159 + $n) . ']', $r(2159 + $n), 'NRG.Celsius', -40, 70);
            $float('KTKreis' . $n . 'Temperatur', 'Kronoterm loop %d current temperature', $key . '.TemperaturesAndConfig.heating_circle_' . $n . '_temp', self::path($page, ['TemperaturesAndConfig', 'heating_circle_' . $n . '_temp']), 'NRG.Celsius', -60, 150);
            $float('KTKreis' . $n . 'Soll', 'Kronoterm loop %d target temperature', $key . '.HeatingCircleData.circle_temp', self::path($page, ['HeatingCircleData', 'circle_temp']), 'NRG.Celsius', -60, 150);
            // This Cloud field is not proven to be the water temperature used by zNWaterTempID.
        }
        if (($inventory['dhw'] ?? null) !== false && is_array($pages['dhw'] ?? null)) {
            $page = $pages['dhw'];
            $float('Warmwasser', 'Hot water temperature', 'dhw.TemperaturesAndConfig.tap_water_temp', self::path($page, ['TemperaturesAndConfig', 'tap_water_temp']), 'NRG.Celsius', 0, 100);
            $float('WarmwasserSoll', 'Hot water target temperature', 'dhw.HeatingCircleData.circle_temp', self::path($page, ['HeatingCircleData', 'circle_temp']), 'NRG.Celsius', 0, 100);
        }
        if (($inventory['pool_active'] ?? null) === true && is_array($pages['pool'] ?? null)) {
            $float('KTPoolTemperatur', 'Kronoterm pool temperature', 'pool.TemperaturesAndConfig.pool_temp', self::path($pages['pool'], ['TemperaturesAndConfig', 'pool_temp']), 'NRG.Celsius', 1, 50);
        }
        if (($inventory['alternative_source_sensor_visible'] ?? null) !== false) {
            $float('KTAlternativeQuelleTemperatur', 'Kronoterm alternative source temperature', 'main.ModbusReg[2107]', $r(2107), 'NRG.Celsius', -60, 150);
        }
        // Do not infer reservoir measurement from circle_calc_temp or register 2101.
        // Do not attach calculated power or unconfirmed cumulative registers to PowerID/EnergyID.
        return $out;
    }

    /** @return array<int,mixed> */
    private static function registers($raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $item) {
            if (!is_array($item)) {
                continue;
            }
            $address = self::integer($item['address'] ?? null);
            if ($address !== null && $address >= 2000 && $address <= 2999) {
                $out[$address] = $item['value'] ?? null;
            }
        }
        return $out;
    }
    private static function path(array $page, array $path)
    {
        $value = $page;
        foreach ($path as $name) {
            if (!is_array($value) || !array_key_exists($name, $value)) {
                return null;
            }
            $value = $value[$name];
        }
        return $value;
    }
    private static function combineWords($high, $low): ?float
    {
        $h = self::integer($high);
        $l = self::integer($low);
        if ($h === null || $l === null || $h < 0 || $h > 65535 || $l < 0 || $l > 65535) return null;
        return (float)($h * 65536 + $l);
    }

    private static function performanceFactor($raw): ?float
    {
        $n = self::number($raw);
        if ($n === null || $n < 0) return null;
        if ($n === 0.0) return 0.0;
        // Mirrors reference normalization for controllers with different
        // scaling conventions; values outside a plausible COP are omitted.
        foreach ([1.0, 0.1, 0.01, 0.001] as $scale) {
            $candidate = $n * $scale;
            if ($candidate > 0 && $candidate <= 10) return round($candidate, 2);
        }
        foreach ([1.0, 0.1, 0.01, 0.001] as $scale) {
            $candidate = $n * $scale;
            if ($candidate > 0 && $candidate <= 20) return round($candidate, 2);
        }
        return null;
    }

    private static function number($raw): ?float
    {
        if (is_int($raw) || is_float($raw)) {
            $n = (float) $raw;
        } elseif (is_string($raw) && preg_match('/^\s*[-+]?(?:\d+(?:[\.,]\d*)?|[\.,]\d+)\s*(?:°C)?\s*$/Du', $raw)) {
            $n = (float) str_replace(',', '.', trim(preg_replace('/\s*°C\s*$/u', '', $raw)));
        } else {
            return null;
        }
        return is_finite($n) ? $n : null;
    }
    private static function integer($raw): ?int
    {
        $n = self::number($raw);
        return $n !== null && $n >= -2147483648 && $n <= 2147483647 && floor($n) === $n ? (int) $n : null;
    }
    private static function boolean($raw): ?bool
    {
        if ($raw === true || $raw === false) {
            return $raw;
        }
        $n = self::integer($raw);
        if ($n === 1) return true;
        if ($n === 0) return false;
        return null;
    }
}
