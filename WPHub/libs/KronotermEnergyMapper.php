<?php
/**
 * Kronoterm Cloud daily electricity consumption, read-only, in kWh.
 * Source schema: trend_consumption in Favio25/Kronoterm-homeassistant.
 * An absent component is UNKNOWN, not zero; an incomplete response never
 * becomes a total. Daily values must never be reused as a cumulative meter.
 */
final class WPHUB_KronotermEnergyMapper
{
    private const KEYS = [
        'CompHeating'  => ['EnergieHeizenHeute', 'Electrical energy heating today'],
        'CompTapWater' => ['EnergieWarmwasserHeute', 'Electrical energy hot water today'],
        'CPLoops'      => ['KTPumpenEnergieHeute', 'Kronoterm circulation pump energy today'],
        'CPAddSource'  => ['KTZusatzEnergieHeute', 'Kronoterm auxiliary source energy today'],
    ];

    /** @return array<string,array{value:float,type:string,profile:string,label:string,source:string}> */
    public static function mapDaily(array $response): array
    {
        $trend = $response['trend_consumption'] ?? null;
        if (!is_array($trend)) {
            return [];
        }
        $result = [];
        $values = [];
        foreach (self::KEYS as $field => [$ident, $caption]) {
            $series = $trend[$field] ?? null;
            if (!is_array($series) || $series === []) {
                continue;
            }
            // The last entry is the day's latest accumulated value, not the sum
            // of trend entries (which would double-count the energy).
            $raw = end($series);
            $value = self::kwh($raw);
            if ($value === null) {
                continue;
            }
            $values[$field] = $value;
            $result[$ident] = self::point($value, $caption, 'consumption.trend_consumption.' . $field);
        }
        if (count($values) === count(self::KEYS)) {
            $result['EnergieGesamtHeute'] = self::point(
                array_sum($values), 'Electrical energy total today', 'sum(trend_consumption:4 components)'
            );
        }
        return $result;
    }

    private static function point(float $value, string $label, string $source): array
    {
        return ['value' => round($value, 4), 'type' => 'float', 'profile' => 'NRG.kWh', 'label' => $label, 'source' => $source];
    }

    private static function kwh($raw): ?float
    {
        if (is_bool($raw) || $raw === null) return null;
        if (is_string($raw)) $raw = str_replace(',', '.', trim($raw));
        if (!is_numeric($raw)) return null;
        $value = (float)$raw;
        return is_finite($value) && $value >= 0 && $value < 10000000 ? $value : null;
    }
}
