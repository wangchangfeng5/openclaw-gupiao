<?php
require __DIR__ . '/bootstrap/app.php';
$svc = new \App\Services\MarketOpportunityService();
foreach ([1,2] as $uid) {
  $d = $svc->build($uid, 30);
  $sum = $d['summary'] ?? [];
  echo 'uid=' . $uid
    . ' opportunities=' . ($sum['opportunity_count'] ?? 0)
    . ' strong_support=' . ($sum['strong_support_count'] ?? 0)
    . ' rise_pullback=' . ($sum['rise_pullback_count'] ?? 0)
    . ' volume_surge=' . ($sum['volume_surge_count'] ?? 0)
    . PHP_EOL;
}
$rows = ($svc->build(2, 30)['opportunities'] ?? []);
for ($i=0; $i < min(8, count($rows)); $i++) {
  $r = $rows[$i];
  $reasons = is_array($r['reasons'] ?? null) ? implode('/', $r['reasons']) : '';
  echo ($i+1) . '. ' . ($r['symbol'] ?? '-') . ' ' . ($r['name'] ?? '')
    . ' total=' . ($r['total_score'] ?? '-')
    . ' chg=' . ($r['change_pct'] ?? '-')
    . ' vr=' . ($r['volume_ratio'] ?? '-')
    . ' reasons=' . $reasons
    . PHP_EOL;
}
?>
