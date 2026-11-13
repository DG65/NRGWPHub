<?php
require_once __DIR__ . '/WPHUB_Text.php';
/**
 * Optional Kronoterm diagnostic variables, independent of WPHub's mapped data.
 * This class is pure (no IPS dependencies). Never deduplicate by equal scalar
 * values. Only fully identical repeated API blocks may be collapsed.
 */
final class WPHUB_KronotermExtraCatalog
{
    public const UNITS = [
        '' => 'No unit / unknown', '°C' => '°C', 'K' => 'K',
        '%' => '%', 'W' => 'W', 'kW' => 'kW', 'Wh' => 'Wh', 'kWh' => 'kWh',
        'bar' => 'bar', 'Pa' => 'Pa', 'Hz' => 'Hz', 'rpm' => 'rpm',
        'A' => 'A', 'V' => 'V', 'h' => 'h', 'min' => 'min', 's' => 's',
        'd' => 'd', 'm³' => 'm³', 'm³/h' => 'm³/h', 'l/min' => 'l/min',
        'count' => 'Count',
    ];

    /** Config values stay separate from the received Cloud report. */
    public static function savedRows(string $json): array
    {
        $rows = json_decode($json, true);
        if (!is_array($rows)) return [];
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $path = (string)($row['Path'] ?? '');
            if (strlen($path) > 250 || !preg_match('/^[a-z][a-z0-9_]*(?:\.[A-Za-z0-9_]+|\[(?:\d+|"[^"\r\n]+")\])+$/D', $path)) continue;
            $unit = (string)($row['Unit'] ?? '');
            $out[$path] = [
                'Path' => $path,
                'Unit' => array_key_exists($unit, self::UNITS) ? $unit : '',
                'Active' => ($row['Active'] ?? false) === true,
            ];
        }
        return $out;
    }

    /**
     * Eliminate only repeated complete page blocks with identical schema AND
     * all matching values; e.g. 48-field TemperaturesAndConfig repeated 8x.
     * Never compare different Modbus addresses, single values, or different
     * blocks. Groups under 3 independent fields are not considered proof.
     * @return array<string,string> redundant path => canonical path
     */
    public static function redundantPaths(array $entries): array
    {
        $blocks = [];
        foreach ($entries as $path => $entry) {
            if (!is_string($path) || !is_array($entry)) continue;
            if (!preg_match('/^([a-z][a-z0-9_]*)\.([A-Za-z][A-Za-z0-9_]*)\.([A-Za-z0-9_]+)$/D', $path, $m)) continue;
            [$all, $page, $block, $field] = $m;
            if ($block === 'ModbusReg') continue;
            $blocks[$block][$page][$field] = [
                'value' => $entry['value'] ?? null,
                'kind' => $entry['kind'] ?? '',
                'unit' => $entry['unit'] ?? '',
            ];
        }
        $aliases = [];
        foreach ($blocks as $block => $pageValues) {
            $identical = [];
            foreach ($pageValues as $page => $fields) {
                if (count($fields) < 3) continue;
                ksort($fields, SORT_STRING);
                $digest = hash('sha256', json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
                $identical[$digest][] = $page;
            }
            foreach ($identical as $pages) {
                if (count($pages) < 2) continue;
                sort($pages, SORT_STRING);
                $canonical = in_array('info', $pages, true) ? 'info' : $pages[0];
                foreach ($pages as $page) {
                    if ($page === $canonical) continue;
                    foreach (array_keys($pageValues[$page]) as $field) {
                        $aliases[$page . '.' . $block . '.' . $field] = $canonical . '.' . $block . '.' . $field;
                    }
                }
            }
        }
        return $aliases;
    }

    public static function displayRows(array $report, array $saved = []): array
    {
        $entries = is_array($report['entries'] ?? null) ? $report['entries'] : [];
        $alias = self::redundantPaths($entries);
        // These sources are part of the corrected v3 fixed Kronoterm mapping.
        // Even a cached v2 report incorrectly marked them unused because v2
        // rejected register strings containing the °C suffix.
        $reserved = array_fill_keys([
            'main.ModbusReg[2101]', 'main.ModbusReg[2103]',
            'main.ModbusReg[2104]', 'info.TemperaturesAndConfig.reservoir_temp',
        ], true);
        foreach ($entries as $path => $entry) {
            if (!is_array($entry)) continue;
            if (in_array(($entry['status'] ?? ''), ['mapped', 'buffer', 'configuration'], true)) {
                $reserved[$path] = true;
                if (isset($alias[$path])) $reserved[$alias[$path]] = true;
            }
        }
        // Documented register-to-Cloud aliases are excluded from optional
        // entries only when both current numeric values confirm the scaling.
        // Unknown register correlations are deliberately NOT deduplicated.
        foreach (self::verifiedAliases() as [$reg, $other, $scale]) {
            $otherCanonical = $alias[$other] ?? $other;
            if (!isset($entries[$reg], $entries[$other])) continue;
            $raw = self::numericValue($entries[$reg]['value'] ?? null);
            $cloud = self::numericValue($entries[$other]['value'] ?? null);
            if ($raw === null || $cloud === null) continue;
            $alreadyScaledCelsius = ($entries[$reg]['unit'] ?? '') === '°C'
                || (is_string($entries[$reg]['value'] ?? null)
                    && str_contains((string)$entries[$reg]['value'], '°C'));
            $factor = $alreadyScaledCelsius ? 1.0 : (float)$scale;
            if (abs($raw * $factor - $cloud) > 0.005) continue;
            if (isset($reserved[$reg])) $reserved[$otherCanonical] = true;
            if (isset($reserved[$other]) || isset($reserved[$otherCanonical])) $reserved[$reg] = true;
        }
        $rows = [];
        foreach ($entries as $path => $entry) {
            if (!is_string($path) || !is_array($entry) || isset($alias[$path]) || isset($reserved[$path])) continue;
            if (!in_array(($entry['status'] ?? 'unused'), ['unused'], true)) continue;
            $existing = $saved[$path] ?? null;
            [$purpose, $evidence] = self::documentedPurpose($path);
            $selectedUnit = is_array($existing) ? (string)($existing['Unit'] ?? '') : '';
            $unit = WPHUB_KronotermModbusProfiles::effectiveUnit(
                $path, $selectedUnit !== '' ? $selectedUnit : self::suggestUnit($entry));
            $rows[] = [
                'Path' => $path,
                'Name' => $path,
                'Purpose' => $purpose,
                'Evidence' => $evidence,
                'Unit' => array_key_exists($unit, self::UNITS) ? $unit : '',
                'Active' => (bool)($existing['Active'] ?? false),
                'LastValue' => WPHUB_KronotermModbusProfiles::displayValue($path, $entry),
                'LastReceived' => (($entry['seen_at'] ?? 0) > 0) ? date(WPHUB_Text::t('Y-m-d H:i:s'), (int)$entry['seen_at']) : '-',
            ];
        }
        // Preserve saved configuration of intermittently missing fields without
        // making stale Cloud values appear current. Mapped/redundant are excluded.
        foreach ($saved as $path => $config) {
            if (isset($entries[$path]) || isset($reserved[$path]) || isset($alias[$path])) continue;
            [$purpose, $evidence] = self::documentedPurpose($path);
            $rows[] = [
                'Path' => $path, 'Name' => $path,
                'Purpose' => $purpose . ' ' . WPHUB_Text::t('(currently not received)'), 'Evidence' => $evidence,
                'Unit' => $config['Unit'], 'Active' => $config['Active'],
                'LastValue' => '-', 'LastReceived' => '-',
            ];
        }
        usort($rows, static fn(array $a,array $b): int => strcmp($a['Path'], $b['Path']));
        return $rows;
    }

    public static function livePoints(array $report, array $saved): array
    {
        $entries = is_array($report['entries'] ?? null) ? $report['entries'] : [];
        $aliases = self::redundantPaths($entries);
        $points = [];
        $allowed = array_fill_keys(array_column(self::displayRows($report, $saved), 'Path'), true);
        foreach ($saved as $path => $config) {
            if (empty($config['Active']) || !isset($allowed[$path]) || !isset($entries[$path]) || isset($aliases[$path])) continue;
            $entry = $entries[$path];
            if (!is_array($entry) || ($entry['status'] ?? '') !== 'unused' || ($entry['fresh'] ?? true) === false) continue;
            $interpreted = WPHUB_KronotermModbusProfiles::interpret($path, $entry);
            $value = $interpreted['value'];
            if ($value === null || $value === '[text omitted]') continue;
            if (is_string($value) && strlen($value) > 120) continue;
            $type = $interpreted['type'];
            if ($type === null) continue;
            $unit = WPHUB_KronotermModbusProfiles::effectiveUnit($path, (string)($config['Unit'] ?? ''));
            $points[$path] = ['value' => $value, 'type' => $type, 'unit' => $unit];
        }
        return $points;
    }

    /** Coerce explicitly numeric measurements with a supported physical suffix.
     * Free text, versions and statuses remain strings; no unit conversion occurs.
     */
    public static function normalizedValue($value)
    {
        if (!is_string($value)) return $value;
        $trimmed = trim(str_replace(',', '.', $value));
        if (preg_match('/^[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:\s*(?:°C|K|%|kWh|Wh|kW|W|bar|Pa|Hz|rpm|A|V|h|min|s|m³|m³\/h|l\/min))?$/uD', $trimmed)) {
            // Decode just the numeric part; attached unit was only source formatting.
            if (preg_match('/^[+-]?(?:\d+(?:\.\d*)?|\.\d+)/', $trimmed, $m)) {
                $number = (float)$m[0];
                if (is_finite($number)) return $number;
            }
        }
        return $value;
    }

    public static function valueType($value): ?string
    {
        $value = self::normalizedValue($value);
        if (is_bool($value)) return 'boolean';
        if (is_int($value) || is_float($value)) return 'float';
        if (is_string($value) && $value !== '' && $value !== '[text omitted]') return 'string';
        return null;
    }

    public static function ident(string $path): string
    {
        return 'KRExtra' . substr(hash('sha256', $path), 0, 20);
    }

    public static function profileName(string $unit): string
    {
        return 'WPHUB.KRExtra.' . substr(hash('sha256', $unit), 0, 12);
    }

    public static function formatDigits(string $unit): int
    {
        if (in_array($unit, ['kWh', 'Wh', 'm³', 'm³/h', 'l/min'], true)) return 3;
        if (in_array($unit, ['°C', 'K', 'bar', 'kW', 'A', 'V'], true)) return 2;
        return 1;
    }

    /**
     * A published BMS register description is verifiable. A Cloud JSON key is NOT an official API field
     * definition: its name alone never counts as a confirmed sensor assignment.
     * @return array{0:string,1:string} meaning, evidence
     */
    public static function documentedPurpose(string $path): array
    {
        $bms = WPHUB_KronotermModbusProfiles::definition($path);
        if ($bms !== null) {
            $number = substr($path, strlen('main.ModbusReg['), -1);
            $page = (int)($bms['page'] ?? 0);
            $source = WPHUB_Text::t('Kronoterm BMS manual 17-20-4023-04, register %s, page %d', $number, $page);
            $meaning = (string)($bms['label'] ?? '');
            if (!empty($bms['bits'])) {
                $source .= '; ' . WPHUB_Text::t('bits described on page %d', (int)($bms['bit_page'] ?? $page));
                $meaning .= '; ' . WPHUB_Text::t('bit field with %d documented meanings', count($bms['bits']));
            }
            if (!empty($bms['note'])) {
                $meaning .= '; ' . (string)$bms['note'];
            }
            return [$meaning, $source];
        }
        if (preg_match('/^main\.ModbusReg\[(\d+)\]$/D', $path, $match)) {
            return [
                WPHUB_Text::t('Register %s: meaning not documented in the BMS manual; do not read the raw value as a physical quantity', $match[1]),
                WPHUB_Text::t('Cloud raw inventory: %s; no definition for this register in the BMS table', $path),
            ];
        }
        return [
            WPHUB_Text::t('Field %s: function not documented', $path),
            WPHUB_Text::t('Cloud raw inventory: field name only, no verified API definition'),
        ];
    }

    private static function suggestUnit(array $entry): string
    {
        $unit = (string)($entry['unit'] ?? '');
        return ($unit !== '°C?' && array_key_exists($unit, self::UNITS)) ? $unit : '';
    }

    private static function numericValue($value): ?float
    {
        if (is_bool($value) || $value === null) return null;
        if (is_string($value)) {
            $value = trim(preg_replace('/\s*°C\s*$/u', '', $value));
            $value = str_replace(',', '.', $value);
        }
        return is_numeric($value) && is_finite((float)$value) ? (float)$value : null;
    }

    private static function verifiedAliases(): array
    {
        static $items = null;
        if ($items === null) {
            $items = require __DIR__ . '/KronotermVerifiedAliases.php';
        }
        return $items;
    }

    private static function valueLabel($value): string
    {
        if ($value === null) return 'null';
        if (is_bool($value)) return $value ? 'true' : 'false';
        if (is_array($value)) return '-';
        return substr((string)$value, 0, 100);
    }
}
