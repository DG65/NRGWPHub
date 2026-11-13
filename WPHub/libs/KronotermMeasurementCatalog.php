<?php
require_once __DIR__ . '/WPHUB_Text.php';
/**
 * Sanitized, read-only inventory of scalar values actually received from the
 * Kronoterm Cloud. Never store whole API replies, credentials or device IDs.
 *
 * Paths are stable relative to the named page. ModbusReg uses register address
 * rather than a volatile array index. All unknown units remain "?".
 */
final class WPHUB_KronotermMeasurementCatalog
{
    private const MAX_ENTRIES = 6000;
    private const PRIVATE_KEY = '/(?:pass(?:word)?|secret|token|auth|cookie|session|user(?:name)?|serial|imei|device_id|hp_id|email|credential|api[_-]?key|access[_-]?key)/i';
    private const NON_MEASURED = '/(?:^|_)(?:target|setpoint|set|desired|calc|calculated|soll|offset|hysteresis|threshold|minimum|maximum|limit|delta)(?:_|$)/i';
    private const REGISTERS = [
        2101 => ['WP inlet (not a confirmed buffer sensor)', '°C'],
        2103 => ['Outdoor temperature', '°C'],
        2104 => ['WP outlet (not a confirmed buffer sensor)', '°C'],
        2105 => ['Compressor inlet', '°C'],
        2106 => ['Compressor outlet', '°C'],
        2107 => ['Alternative source temperature', '°C'],
    ];

    /** @return array{entries:array<string,array<string,mixed>>,truncated:bool} */
    public static function collect(array $snapshot): array
    {
        $result = ['entries' => [], 'truncated' => false];
        $pages = is_array($snapshot['pages'] ?? null) ? $snapshot['pages'] : [];
        foreach ($pages as $pageName => $page) {
            if (!is_string($pageName) || $pageName === 'menu' ||
                !preg_match('/^[a-z][a-z0-9_]*$/D', $pageName) || !is_array($page)) continue;
            self::walk($page, $pageName, $result, 0);
            if ($result['truncated']) break;
        }
        ksort($result['entries'], SORT_STRING);
        return $result;
    }

    /** Classify which fields are mapped into WPHub and which remain unused. */
    public static function report(array $snapshot, array $measurements, ?array $previous = null, int $timestamp = 0): array
    {
        $timestamp = $timestamp > 0 ? $timestamp : time();
        $catalog = self::collect($snapshot);
        $entries = $catalog['entries'];
        $usedSources = self::usedSources($measurements);
        $configuration = self::configurationSources($snapshot['inventory'] ?? []);

        // The energy endpoint is queried on a different schedule. Carry over its
        // LAST received values without representing them as freshly fetched.
        $prior = is_array($previous['entries'] ?? null) ? $previous['entries'] : [];
        foreach ($prior as $path => $entry) {
            if (!is_string($path) || !str_starts_with($path, 'consumption.') || isset($entries[$path]) ||
                !is_array($entry) || !isset($entry['seen_at'])) continue;
            if (count($entries) >= self::MAX_ENTRIES) { $catalog['truncated'] = true; break; }
            $entry['fresh'] = false;
            $entries[$path] = $entry;
        }
        $counts = ['mapped' => 0, 'buffer' => 0, 'configuration' => 0, 'unused' => 0];
        foreach ($entries as $path => &$entry) {
            $entry['fresh'] = !isset($entry['fresh']) || $entry['fresh'] !== false;
            if ($entry['fresh']) $entry['seen_at'] = $timestamp;
            $assignments = self::matches($path, $usedSources);
            if ($assignments !== []) {
                $bufferAssignments = array_filter($assignments, static fn($i) => str_starts_with($i, 'KTHeizpuffer') || str_starts_with($i, 'KTKuehlpuffer') || str_starts_with($i, 'KTKombipuffer'));
                $entry['status'] = $bufferAssignments !== [] ? 'buffer' : 'mapped';
                $entry['used_by'] = array_values(array_unique($assignments));
            } elseif (isset($configuration[$path])) {
                $entry['status'] = 'configuration';
                $entry['used_by'] = ['Reglerinventar'];
            } else {
                if (!$entry['fresh'] && in_array(($entry['status'] ?? ''), ['mapped','buffer','configuration'], true)) {
                    // A last-seen energy reading remains mapped even when this
                    // particular polling cycle did not query that endpoint.
                    $entry['used_by'] = is_array($entry['used_by'] ?? null) ? $entry['used_by'] : [];
                } else {
                    $entry['status'] = 'unused';
                    $entry['used_by'] = [];
                }
            }
            $counts[$entry['status']]++;
        }
        unset($entry);
        ksort($entries, SORT_STRING);
        return [
            'timestamp' => $timestamp,
            'page_status' => is_array($snapshot['page_status'] ?? null) ? $snapshot['page_status'] : [],
            'entries' => $entries,
            'counts' => $counts,
            'truncated' => $catalog['truncated'] || (bool)($previous['truncated'] ?? false),
        ];
    }

    /** Public pure helper for form Select options, preserving saved missing paths. */
    public static function temperatureOptions(array $report, string $selected = '', ?callable $translate = null): array
    {
        $t = $translate ?? static fn(string $caption): string => $caption;
        $options = [['caption' => $t(WPHUB_Text::quelle('— Select Cloud measurement —')), 'value' => '']];
        $entries = is_array($report['entries'] ?? null) ? $report['entries'] : [];
        foreach ($entries as $path => $entry) {
            if (!is_string($path) || !is_array($entry) || ($entry['selectable'] ?? false) !== true) continue;
            $value = $entry['value'];
            $caption = $path . ' · ' . $entry['label'] . ': ' . self::displayValue($value) . ' ' . $entry['unit'];
            if (($entry['fresh'] ?? true) === false) $caption .= ' ' . $t(WPHUB_Text::quelle('(last received)'));
            $options[] = ['caption' => substr($caption, 0, 300), 'value' => $path];
        }
        if ($selected !== '' && !array_filter($options, static fn($item) => $item['value'] === $selected)) {
            $options[] = ['caption' => $selected . ' ' . $t(WPHUB_Text::quelle('(currently unavailable)')), 'value' => $selected];
        }
        return $options;
    }

    /** Arrays for Symcon read-only Lists (all fields / only not mapped). */
    public static function rows(array $report, bool $unusedOnly = false): array
    {
        $rows = [];
        foreach (($report['entries'] ?? []) as $entry) {
            if (!is_array($entry) || ($unusedOnly && ($entry['status'] ?? '') !== 'unused')) continue;
            $rows[] = [
                'Path' => (string)$entry['path'],
                'Value' => self::displayValue($entry['value']),
                'Unit' => (string)$entry['unit'],
                'Type' => (string)($entry['kind'] ?? 'unknown'),
                'Status' => (string)($entry['status'] ?? 'unused'),
                'UsedBy' => implode(', ', $entry['used_by'] ?? []),
                'SeenAt' => date('d.m.Y H:i:s', (int)($entry['seen_at'] ?? 0)),
            ];
        }
        return $rows;
    }

    /** @return array<string,array<string,mixed>> */
    public static function temperatures(array $catalog): array
    {
        $entries = is_array($catalog['entries'] ?? null) ? $catalog['entries'] : [];
        return array_filter($entries, static fn($item) => is_array($item) && ($item['selectable'] ?? false) === true);
    }

    /** Read a user-selected numeric temperature only from a CURRENT snapshot. */
    public static function readTemperature(array $snapshot, string $path): ?float
    {
        if ($path === '' || strlen($path) > 250) return null;
        $entry = self::collect($snapshot)['entries'][$path] ?? null;
        return is_array($entry) && ($entry['selectable'] ?? false) === true ? (float)$entry['value'] : null;
    }

    private static function walk(array $node, string $prefix, array &$catalog, int $depth): void
    {
        if ($depth > 12 || $catalog['truncated']) return;
        foreach ($node as $key => $value) {
            if (count($catalog['entries']) >= self::MAX_ENTRIES) { $catalog['truncated'] = true; return; }
            if ($key === 'ModbusReg' && is_array($value)) {
                foreach ($value as $register) {
                    if (!is_array($register)) continue;
                    $address = $register['address'] ?? null;
                    if (!is_numeric($address) || (string)(int)$address !== (string)$address || (int)$address < 0 || (int)$address > 65535) continue;
                    $addr = (int)$address;
                    $known = self::REGISTERS[$addr] ?? null;
                    self::add($catalog, $prefix . '.ModbusReg[' . $addr . ']', $known[0] ?? ('Register ' . $addr), $register['value'] ?? null,
                        $known[1] ?? '?', $known === null ? 'raw_register' : 'temperature');
                }
                continue;
            }
            if (!is_int($key) && (!is_string($key) || preg_match(self::PRIVATE_KEY, $key))) continue;
            if (is_int($key)) {
                $path = $prefix . '[' . $key . ']';
            } elseif (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $key)) {
                $path = $prefix . '.' . $key;
            } else {
                // Preserve unusual vendor JSON keys without confusing them
                // with the dot-separated standard source paths.
                $path = $prefix . '[' . json_encode($key, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . ']';
            }
            if (is_array($value)) { self::walk($value, $path, $catalog, $depth + 1); continue; }
            if (!is_scalar($value) && $value !== null) continue;
            $isTemp = is_string($key) && preg_match('/(?:temp(?:erature)?|temperatur)/i', $key) &&
                !preg_match(self::NON_MEASURED, $key) && !preg_match(self::NON_MEASURED, $path);
            $kind = is_bool($value) ? 'state' : ($isTemp ? 'temperature' : 'value');
            self::add($catalog, $path, (string)$key, $value, $isTemp ? '°C?' : '?', $kind);
        }
    }

    private static function add(array &$catalog, string $path, string $label, $raw, string $unit, string $kind): void
    {
        if (strlen($path) > 250 || isset($catalog['entries'][$path])) return;
        if (is_string($raw)) {
            $raw = trim($raw);
            // Do not retain unstructured or potentially sensitive strings.
            if (strlen($raw) > 120 || str_contains($raw, '@') || preg_match('/(?:https?:\/\/|bearer\s|<[^>]+>|[\r\n])|(?:^[A-Za-z0-9_\/-]{80,}$)/i', $raw)) {
                $raw = '[text omitted]';
            }
        }
        $n = self::number($raw);
        $value = is_bool($raw) ? $raw : ($n ?? $raw);
        $selectable = $kind === 'temperature' && !is_bool($value) && $n !== null && $n > -40 && $n < 120;
        // Underlying register values may have units/scaling that are still to be verified;
        // selection does not claim that the probe is a physical buffer sensor.
        $catalog['entries'][$path] = [
            'path' => $path,
            'label' => $label,
            'value' => $value,
            'unit' => $unit,
            'kind' => $kind,
            'selectable' => $selectable,
        ];
    }

    private static function usedSources(array $measurements): array
    {
        $sources = [];
        foreach ($measurements as $ident => $point) {
            if (!is_string($ident) || !is_array($point)) continue;
            $source = (string)($point['source'] ?? '');
            if (str_starts_with($source, 'cloud:')) $source = substr($source, 6);
            if (preg_match('/^(main\.ModbusReg)\[(\d+):(\d+)\]$/', $source, $m)) {
                foreach ([(int)$m[2], (int)$m[3]] as $addr) $sources['main.ModbusReg[' . $addr . ']'][] = $ident;
            } elseif (preg_match('/^(main\.ModbusReg\[\d+\])\.bit\d+$/', $source, $m)) {
                $sources[$m[1]][] = $ident;
            } elseif (preg_match('/^consumption\.trend_consumption\.[A-Za-z]+$/', $source)) {
                $sources[$source . '[*]'][] = $ident;
            } elseif ($source === 'sum(trend_consumption:4 components)') {
                foreach (['CompHeating','CompTapWater','CPLoops','CPAddSource'] as $item) {
                    $sources['consumption.trend_consumption.' . $item . '[*]'][] = $ident;
                }
            } elseif (preg_match('/^[a-z][a-z0-9_]*(?:\.[A-Za-z0-9_]+|\[\d+\])*$/D', $source)) {
                $sources[$source][] = $ident;
            }
        }
        return $sources;
    }

    private static function configurationSources($inventory): array
    {
        $result = [];
        if (!is_array($inventory)) return $result;
        foreach (['loop1' => 'circle_1_installed','loop2' => 'circle_2_installed',
                  'loop3' => 'circle_3_installed','loop4' => 'circle_4_installed',
                  'dhw' => 'tap_water_installed','pool_active' => 'pool_active',
                  'alternative_source_sensor_visible' => 'alt_source_temp_visible'] as $inventoryKey => $field) {
            if (($inventory[$inventoryKey] ?? null) !== null) $result['info.TemperaturesAndConfig.' . $field] = true;
        }
        return $result;
    }

    private static function matches(string $path, array $sources): array
    {
        $result = $sources[$path] ?? [];
        if (preg_match('/^(.*)\[\d+\]$/', $path, $m)) {
            $result = array_merge($result, $sources[$m[1] . '[*]'] ?? []);
        }
        return $result;
    }

    private static function displayValue($value): string
    {
        if ($value === null) return 'null';
        if (is_bool($value)) return $value ? 'true' : 'false';
        if (is_float($value)) return rtrim(rtrim(sprintf('%.5f', $value), '0'), '.');
        return (string)$value;
    }

    private static function number($value): ?float
    {
        if (is_bool($value) || $value === null) return null;
        if (is_string($value) && !preg_match('/^\s*[-+]?(?:\d+(?:[\.,]\d*)?|[\.,]\d+)\s*(?:°C)?\s*$/Du', $value)) return null;
        if (!is_numeric($value) && !is_string($value)) return null;
        $clean = is_string($value) ? preg_replace('/\s*°C\s*$/u', '', $value) : $value;
        $n = (float)str_replace(',', '.', (string)$clean);
        return is_finite($n) ? $n : null;
    }
}
