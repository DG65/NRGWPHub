<?php
require_once __DIR__ . '/KronotermMeasurementCatalog.php';
/**
 * User-controlled Kronoterm buffer inventory and measured temperatures.
 * The layout is independent from the Kronoterm controller's feature flags.
 * For unverified Cloud mappings no measurement is emitted.
 */
final class WPHUB_KronotermBufferMapper
{
    /**
     * @param array $snapshot Snapshot from WPHUB_KronotermClient::readSnapshot()
     * @param array $config ['layout' => string, 'channels' => [key => [source,external_id,cloud_path]]]
     * @param callable $externalReader fn(int $variableId): ?float
     * @return array<string,array{value:float,type:string,profile:string,label:string,source:string}>
     */
    public static function mapSnapshot(array $snapshot, array $config, callable $externalReader): array
    {
        $layout = self::resolveLayout((string)($config['layout'] ?? 'auto'), $snapshot['inventory'] ?? []);
        if ($layout === null || $layout === 'none') {
            return [];
        }
        $points = [];
        if ($layout === 'heating' || $layout === 'separate') {
            $points['heat_top'] = ['KTHeizpufferOben', 'Kronoterm heating buffer top temperature'];
            $points['heat_bottom'] = ['KTHeizpufferUnten', 'Kronoterm heating buffer bottom temperature'];
        }
        if ($layout === 'cooling' || $layout === 'separate') {
            $points['cool_top'] = ['KTKuehlpufferOben', 'Kronoterm cooling buffer top temperature'];
            $points['cool_bottom'] = ['KTKuehlpufferUnten', 'Kronoterm cooling buffer bottom temperature'];
        }
        if ($layout === 'combined') {
            $points['heat_top'] = ['KTKombipufferOben', 'Kronoterm combined buffer top temperature'];
            $points['heat_bottom'] = ['KTKombipufferUnten', 'Kronoterm combined buffer bottom temperature'];
        }
        $out = [];
        $channels = is_array($config['channels'] ?? null) ? $config['channels'] : [];
        foreach ($points as $key => [$ident, $label]) {
            $channel = is_array($channels[$key] ?? null) ? $channels[$key] : [];
            $source = $channel['source'] ?? 'off';
            $value = null;
            $provenance = '';
            if ($source === 'cloud') {
                $path = trim((string)($channel['cloud_path'] ?? ''));
                $value = self::readCloudMeasurement($snapshot, $path);
                $provenance = 'cloud:' . $path;
            } elseif ($source === 'external') {
                $id = (int)($channel['external_id'] ?? 0);
                if ($id > 0) {
                    $value = $externalReader($id);
                    $provenance = 'external:' . $id;
                }
            }
            $value = self::temperature($value);
            if ($value === null) {
                continue;
            }
            $out[$ident] = [
                'value' => $value,
                'type' => 'float',
                'profile' => 'NRG.Celsius',
                'label' => $label,
                'source' => $provenance,
            ];
        }
        return $out;
    }

    /** No deduction from a missing flag or from a pool/loop flag. */
    public static function resolveLayout(string $selected, $inventory): ?string
    {
        if (in_array($selected, ['none', 'heating', 'cooling', 'separate', 'combined'], true)) {
            return $selected;
        }
        if ($selected !== 'auto' || !is_array($inventory)) {
            return null;
        }
        $heat = $inventory['heating_buffer'] ?? null;
        $cool = $inventory['cooling_buffer'] ?? null;
        if ($heat === true && $cool === false) {
            return 'heating';
        }
        if ($heat === false && $cool === true) {
            return 'cooling';
        }
        if ($heat === false && $cool === false) {
            return 'none';
        }
        // When both are true, one combined vs two separate buffers is unknown.
        return null;
    }

    /**
     * An explicit Cloud selection is resolved against the actually returned
     * snapshot. Only numeric, plausible, non-calculated temperatures qualify.
     * Known HP/ambient registers remain selectable ONLY by the user; the
     * module never assumes that they are physical buffer sensors.
     */
    private static function readCloudMeasurement(array $snapshot, string $path): ?float
    {
        return WPHUB_KronotermMeasurementCatalog::readTemperature($snapshot, $path);
    }

    private static function temperature($value): ?float
    {
        if (is_bool($value) || $value === null) {
            return null;
        }
        if (is_string($value)) {
            $value = str_replace(',', '.', trim(preg_replace('/\s*°C\s*$/u', '', $value)));
        }
        if (!is_numeric($value)) {
            return null;
        }
        $n = (float)$value;
        return is_finite($n) && $n > -40 && $n < 120 ? $n : null;
    }
}
