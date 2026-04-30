<?php
require_once __DIR__ . '/bootstrap/app.php';
use App\Core\Database;
$pdo=Database::connection();
$symbols=['600895','603650','300236','300576','688019','688037','688981','603005'];
$in=implode(',',array_fill(0,count($symbols),'?'));
$sql="SELECT symbol,market,name,sector_name,price,change_pct,volume,turnover,quote_time FROM market_quotes_latest WHERE symbol IN ($in) ORDER BY symbol";
$st=$pdo->prepare($sql);$st->execute($symbols);$rows=$st->fetchAll();
echo "[quotes_latest]\n";
echo json_encode($rows, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT),"\n";
$sql2="SELECT symbol,name,rank_no,change_1d_pct,change_3d_pct,change_5d_pct,change_10d_pct,trade_date,snapshot_time FROM market_close_rankings WHERE symbol IN ($in) AND rank_type='strong' ORDER BY trade_date DESC,rank_no ASC LIMIT 200";
$st2=$pdo->prepare($sql2);$st2->execute($symbols);$rows2=$st2->fetchAll();
echo "[close_rankings_strong]\n";
echo json_encode($rows2, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT),"\n";
$sql3="SELECT symbol,name,rank_no,change_1d_pct,change_3d_pct,change_5d_pct,change_10d_pct,trade_date,snapshot_time FROM market_close_rankings WHERE symbol IN ($in) AND rank_type='weak' ORDER BY trade_date DESC,rank_no ASC LIMIT 200";
$st3=$pdo->prepare($sql3);$st3->execute($symbols);$rows3=$st3->fetchAll();
echo "[close_rankings_weak]\n";
echo json_encode($rows3, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT),"\n";
