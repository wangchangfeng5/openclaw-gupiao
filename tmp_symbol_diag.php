<?php
require_once __DIR__ . '/bootstrap/app.php';
use App\Core\Database;
$pdo = Database::connection();
$symbols = ['600588','002156'];
foreach ($symbols as $s) {
    $st = $pdo->prepare('SELECT symbol,name,sector_name,price,change_pct,volume,quote_time FROM market_quotes_latest WHERE symbol=? LIMIT 1');
    $st->execute([$s]);
    $q = $st->fetch();
    if (!$q) { continue; }

    $st8 = $pdo->prepare("SELECT MIN(price) mn, MAX(price) mx FROM market_quotes WHERE symbol=? AND price>0 AND quote_time>=DATE_SUB(NOW(), INTERVAL 8 DAY)");
    $st8->execute([$s]);
    $r8 = $st8->fetch();

    $st5 = $pdo->prepare("SELECT MIN(price) mn, MAX(price) mx FROM market_quotes WHERE symbol=? AND price>0 AND quote_time>=DATE_SUB(NOW(), INTERVAL 5 DAY)");
    $st5->execute([$s]);
    $r5 = $st5->fetch();

    $stV = $pdo->prepare("SELECT AVG(volume) avgv FROM market_quotes WHERE symbol=? AND price>0 AND quote_time>=DATE_SUB(NOW(), INTERVAL 20 DAY)");
    $stV->execute([$s]);
    $rv = $stV->fetch();

    $p = (float)$q['price'];
    $mn8 = (float)($r8['mn'] ?? 0);
    $mx8 = (float)($r8['mx'] ?? 0);
    $mn5 = (float)($r5['mn'] ?? 0);
    $mx5 = (float)($r5['mx'] ?? 0);
    $pos8 = $mx8 > $mn8 ? round((($p-$mn8)/($mx8-$mn8))*100,2) : null;
    $pos5 = $mx5 > $mn5 ? round((($p-$mn5)/($mx5-$mn5))*100,2) : null;
    $vol = (float)($q['volume'] ?? 0);
    $avgv = (float)($rv['avgv'] ?? 0);
    $vr = $avgv > 0 ? round($vol/$avgv,2) : null;

    echo json_encode([
        'symbol'=>$q['symbol'],'name'=>$q['name'],'sector'=>$q['sector_name'],'price'=>$q['price'],'chg_pct'=>$q['change_pct'],'volume'=>$q['volume'],'quote_time'=>$q['quote_time'],
        'low5d'=>$mn5,'high5d'=>$mx5,'pos5d_pct'=>$pos5,
        'low8d'=>$mn8,'high8d'=>$mx8,'pos8d_pct'=>$pos8,
        'vol_ratio_20d'=>$vr
    ], JSON_UNESCAPED_UNICODE), PHP_EOL;
}
