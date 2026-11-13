<?php
require_once __DIR__ . '/WPHUB_Text.php';
/**
 * Kronoterm Cloud daily electricity consumption, read-only, in kWh.
 * Source schema: trend_consumption in Favio25/Kronoterm-homeassistant.
 * An absent component is UNKNOWN, not zero; an incomplete response never
 * becomes a total. Daily values must never be reused as a cumulative meter.
 */
final class WPHUB_KronotermEnergyMapper
{
    /** Tabellenwerte rufen quelle() auf, daher Methode statt Konstante. */
    private static function keys(): array
    {
        return [
        'CompHeating'  => ['EnergieHeizenHeute', WPHUB_Text::quelle('Electrical energy heating today')],
        'CompTapWater' => ['EnergieWarmwasserHeute', WPHUB_Text::quelle('Electrical energy hot water today')],
        'CPLoops'      => ['KTPumpenEnergieHeute', WPHUB_Text::quelle('Kronoterm circulation pump energy today')],
        'CPAddSource'  => ['KTZusatzEnergieHeute', WPHUB_Text::quelle('Kronoterm auxiliary source energy today')],
        ];
    }

    /** @return array<string,array{value:float,type:string,profile:string,label:string,source:string}> */
    public static function mapDaily(array $response): array
    {
        $trend = $response['trend_consumption'] ?? null;
        if (!is_array($trend)) {
            return [];
        }
        $result = [];
        $values = [];
        foreach (self::keys() as $field => [$ident, $caption]) {
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
        if (count($values) === count(self::keys())) {
            $result['EnergieGesamtHeute'] = self::point(
                array_sum($values), WPHUB_Text::quelle('Electrical energy total today'), 'sum(trend_consumption:4 components)'
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
