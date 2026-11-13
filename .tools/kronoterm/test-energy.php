<?php
require_once __DIR__ . '/../../WPHub/libs/KronotermEnergyMapper.php';
require_once __DIR__ . '/../../WPHub/libs/KronotermClient.php';
function energyCheck(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException('FAILED ' . $message);
    echo 'OK  ' . $message . "\n";
}
$all = ['trend_consumption' => [
    'CompHeating' => [0.4, 1.2], 'CompTapWater' => [0.2],
    'CPLoops' => [0.1], 'CPAddSource' => [0.3],
]];
$mapped = WPHUB_KronotermEnergyMapper::mapDaily($all);
energyCheck(abs($mapped['EnergieHeizenHeute']['value'] - 1.2) < 0.001, 'Last cumulative daily sample, not sum');
energyCheck(abs($mapped['EnergieGesamtHeute']['value'] - 1.8) < 0.001, 'All four components included once');
energyCheck(!isset($mapped['EnergieKuehlenHeute']), 'No fictitious cooling energy');
$partial = $all; unset($partial['trend_consumption']['CPLoops']);
energyCheck(!isset(WPHUB_KronotermEnergyMapper::mapDaily($partial)['EnergieGesamtHeute']), 'Incomplete data does not become total');
$partial['trend_consumption']['CPLoops'] = [];
energyCheck(!isset(WPHUB_KronotermEnergyMapper::mapDaily($partial)['KTPumpenEnergieHeute']), 'Missing is not zero');
energyCheck(WPHUB_KronotermEnergyMapper::mapDaily(['trend_consumption'=>['CompHeating'=>['bad']]]) === [], 'Malformed values discarded');
energyCheck(WPHUB_KronotermEnergyMapper::mapDaily(['trend_consumption'=>['CompHeating'=>[-1]]]) === [], 'Negative daily energy discarded');
energyCheck(WPHUB_KronotermEnergyMapper::mapDaily(['trend_consumption'=>['CompHeating'=>['1,25']]])['EnergieHeizenHeute']['value'] === 1.25, 'Decimal comma accepted');
energyCheck(!isset(WPHUB_KronotermEnergyMapper::mapDaily($all)['EnergyID']), 'Daily consumption never becomes EnergyID');
$requests = [];
$transport = static function(array $r) use (&$requests): array {
    $requests[] = $r;
    if (str_contains($r['url'],'Menu=1')) return ['status'=>200,'body'=>'{"hp_id":1}'];
    if (str_contains($r['url'],'Action=4')) return ['status'=>200,'body'=>'{"trend_consumption":{"CompHeating":[2]}}'];
    return ['status'=>200,'body'=>'{}'];
};
$c = new WPHUB_KronotermClient('test','secret',$transport);
$d = new DateTimeImmutable('2026-11-11T15:00:00+01:00');
$consumption = $c->getDailyConsumption($d);
energyCheck(isset($consumption['trend_consumption']['CompHeating']), 'Consumption received as read-only POST');
$posts = array_values(array_filter($requests, static fn($r) => $r['method'] === 'POST'));
energyCheck(count($posts) === 1 && str_contains($posts[0]['url'],'Action=4'), 'Only POST is consumption query');
energyCheck(str_contains($posts[0]['formEncoded'],'d1=315') && str_contains($posts[0]['formEncoded'],'year=2026'), 'Date year and 1-based day-of-year');
energyCheck(substr_count($posts[0]['formEncoded'],'dValues%5B%5D=') === 8, 'Repeated form keys preserved');
energyCheck(!str_contains($posts[0]['url'],'secret'), 'Credential absent from URL');
echo "All Kronoterm-energy tests passed.\n";
