<?php
require_once __DIR__ . '/WPHUB_Text.php';
/**
 * Read-only interpretations of Kronoterm Modbus values received through the
 * Cloud inventory. Source: Kronoterm BMS manual 17-20-28-4023-04,
 * printed pages 6-9 (register table) and 10-32 (codes, bits and energy words).
 *
 * NEVER apply a factor twice to values the API has already formatted with
 * a physical unit. NEVER label one 16-bit energy high/low word as kWh.
 */
final class WPHUB_KronotermModbusProfiles
{
    public static function definition(string $path): ?array
    {
        if (!preg_match('/^main\.ModbusReg\[(\d{4})\]$/D', $path, $match)) {
            return null;
        }
        static $definitions = null;
        if ($definitions === null) {
            $definitions = require __DIR__ . '/KronotermModbusDefinitions.php';
        }
        return $definitions[$match[1]] ?? null;
    }

    /** A non-empty manually chosen unit takes precedence over the PDF default. */
    public static function effectiveUnit(string $path, string $selected): string
    {
        if ($selected !== '') {
            return $selected;
        }
        return (string)(self::definition($path)['unit'] ?? '');
    }

    /**
     * Normalize and scale *only* documented register values. All other Cloud
     * values retain the existing v3 interpretation. Unit-bearing text is
     * already rendered in physical units by the Kronoterm API.
     */
    public static function interpret(string $path, array $entry): array
    {
        $source = $entry['value'] ?? null;
        $value = WPHUB_KronotermExtraCatalog::normalizedValue($source);
        $definition = self::definition($path);
        if ($definition === null || !is_numeric($value) || is_bool($value)) {
            return ['value' => $value, 'type' => WPHUB_KronotermExtraCatalog::valueType($value)];
        }
        $factor = (float)($definition['factor'] ?? 1.0);
        $alreadyFormatted = is_string($source)
            && preg_match('/\s(?:°C|K|%|kWh|Wh|kW|W|bar|Pa|Hz|rpm|A|V|h|min|s|m³|m³\/h|l\/min)\s*$/uD', trim($source));
        // The inventory's `temperature` kind with °C metadata denotes an
        // already converted physical value even if it is sent as a number.
        $alreadyFormatted = $alreadyFormatted
            || (($entry['kind'] ?? '') === 'temperature' && ($entry['unit'] ?? '') === '°C');
        if (!$alreadyFormatted && $factor !== 1.0) {
            $value = (float)$value * $factor;
        }
        // Codes, bit masks and raw words are whole numbers: Integer variables (a Float variable with fixed
        // value associations would break Symcon's presentation check, Stolperstein 29). Everything else is Float.
        if (in_array($definition['kind'] ?? '', ['code', 'bits', 'word'], true)) {
            return ['value' => (int)round((float)$value), 'type' => 'integer'];
        }
        return ['value' => (float)$value, 'type' => 'float'];
    }

    public static function profileName(string $path, string $unit): string
    {
        if (!preg_match('/\[(\d{4})\]$/D', $path, $match)) {
            return '';
        }
        // Unique by register and unit; multiple WPHub instances cannot
        // accidentally alter each other's custom profile.
        return 'WPHUB.KRMB.R' . $match[1] . '.' . substr(hash('sha256', $unit), 0, 8);
    }

    public static function digits(array $definition, string $unit): int
    {
        if (isset($definition['digits'])) {
            return (int)$definition['digits'];
        }
        if (($definition['kind'] ?? '') === 'code' || ($definition['kind'] ?? '') === 'bits'
            || ($definition['kind'] ?? '') === 'word') {
            return 0;
        }
        if (in_array($unit, ['°C', 'K', 'bar'], true)) {
            return 1;
        }
        if (in_array($unit, ['h', 'd', 'min', '%', 'W', 'count'], true)) {
            return 0;
        }
        return WPHUB_KronotermExtraCatalog::formatDigits($unit);
    }

    public static function variableLabel(string $path): string
    {
        $definition = self::definition($path);
        if ($definition === null) {
            return WPHUB_Text::t('Kronoterm additional value: %s', $path);
        }
        $number = (string)substr($path, strlen('main.ModbusReg['), -1);
        $description = (string)$definition['label'];
        if (strlen($description) > 90) {
            $description = substr($description, 0, 87) . '...';
        }
        return WPHUB_Text::t('Kronoterm: %s (R%s)', $description, $number);
    }

    /** Bit fields are masks, never a single exclusive status code.
     * Unknown active bits remain visible and are NOT given speculative labels.
     */
    public static function bitfieldText(string $path, $value): ?string
    {
        $def = self::definition($path);
        if ($def === null || ($def['kind'] ?? '') !== 'bits') return null;
        if (!is_numeric($value) || (float)$value < 0 || (float)$value > 65535
            || floor((float)$value) !== (float)$value) return null;
        $bits = (int)$value;
        if ($bits === 0) return WPHUB_Text::t('No bits set');
        $labels = [];
        $known = 0;
        foreach (($def['bits'] ?? []) as $index => $description) {
            $mask = 1 << (int)$index;
            $known |= $mask;
            if (($bits & $mask) !== 0) {
                $labels[] = 'b' . $index . ': ' . $description;
            }
        }
        for ($index = 0; $index < 16; $index++) {
            if (($bits & (1 << $index)) !== 0 && ($known & (1 << $index)) === 0) {
                $labels[] = 'b' . $index . ': ' . WPHUB_Text::t('meaning not documented');
            }
        }
        return implode('; ', $labels);
    }

    /** Documented single-code enums. No guesses for undocumented values. */
    public static function codeText(string $path, $value): ?string
    {
        $def = self::definition($path);
        if ($def === null || ($def['kind'] ?? '') !== 'code') return null;
        if (!is_numeric($value) || floor((float)$value) !== (float)$value) return null;
        $key = (string)(int)$value;
        return isset($def['choices'][$key]) ? (string)$def['choices'][$key] : WPHUB_Text::t('Undocumented code %s', $key);
    }

    public static function displayValue(string $path, array $entry): string
    {
        $interpreted = self::interpret($path, $entry);
        $value = $interpreted['value'];
        if ($value === null) return 'null';
        if (is_bool($value)) return $value ? 'true' : 'false';
        if (is_array($value)) return '-';
        if ((is_float($value) && is_finite($value)) || is_int($value)) {
            $value = (float)$value;
            // Use the profile's intended precision without sacrificing raw
            // fraction digits where metadata has no defined precision.
            $def = self::definition($path);
            $unit = (string)($def['unit'] ?? '');
            $digits = $def !== null ? self::digits($def, $unit) : 3;
            $valueText = number_format($value, max(0, min(4, $digits)), '.', '');
            if (($def['kind'] ?? '') === 'bits') {
                $meaning = self::bitfieldText($path, $value);
                if ($meaning !== null) return $valueText . ' (' . $meaning . ')';
            }
            $meaning = self::codeText($path, $value);
            if ($meaning !== null) return $valueText . ' (' . $meaning . ')';
            return $valueText . ($unit !== '' ? ' ' . $unit : '');
        }
        return substr((string)$value, 0, 100);
    }
}
