<?php
$env=[];
$lines=file(__DIR__.'/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
foreach($lines as $line){
  $line=trim($line);
  if($line===''||$line[0]==='#') continue;
  $pos=strpos($line,'=');
  if($pos===false) continue;
  $k=trim(substr($line,0,$pos));
  $v=trim(substr($line,$pos+1));
  if(strlen($v)>=2 && (($v[0]==='"'&&$v[strlen($v)-1]==='"')||($v[0]==="'"&&$v[strlen($v)-1]==="'"))) $v=substr($v,1,-1);
  $env[$k]=$v;
}
$dsn='mysql:host='.$env['DB_HOST'].';port='.$env['DB_PORT'].';dbname='.$env['DB_DATABASE'].';charset=utf8mb4';
$pdo=new PDO($dsn,$env['DB_USERNAME'],$env['DB_PASSWORD']??'', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db=$env['DB_DATABASE'];
$tables=['openclaw_suggestions','market_quotes','sector_strength','news_feed','market_close_rankings'];
foreach($tables as $t){
  $colsStmt=$pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=?");
  $colsStmt->execute([$db,$t]);
  $cols=$colsStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
  $timeCol=null;
  foreach(['updated_at','created_at','captured_at','published_at','trade_date','snapshot_time'] as $c){ if(in_array($c,$cols,true)){ $timeCol=$c; break; } }
  $sql="SELECT COUNT(*) AS c";
  if($timeCol){ $sql.=
", MAX(`$timeCol`) AS mt"; }
  $sql.=" FROM `$t`";
  $r=$pdo->query($sql)->fetch(PDO::FETCH_ASSOC);
  echo $t . ': count=' . $r['c'] . ' max_time=' . ($timeCol?($r['mt']??'NULL'):'N/A') . ' time_col=' . ($timeCol??'N/A') . PHP_EOL;
}
