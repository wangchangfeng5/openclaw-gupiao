<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

final class WatchlistRankingService
{
    /**
     * @var array<string, array<string, mixed>>
     */
    private array $sectorSignalCache = [];

    /**
     * @var array<int, array<string, mixed>>|null
     */
    private ?array $recentNewsCache = null;

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    public function enrichRows(array $rows): array
    {
        $avgVolumeCache = [];
        $this->recentNewsCache = $this->loadRecentNews(260, 72);

        foreach ($rows as &$row) {
            $symbol = strtoupper(trim((string) ($row['symbol'] ?? '')));
            $market = (string) ($row['market'] ?? 'A_STOCK_MAIN');
            $name = trim((string) ($row['name'] ?? ''));
            $sectorName = trim((string) ($row['sector_name'] ?? ''));

            $current = $this->toFloat($row['current_price'] ?? null);
            if ($current === null) {
                $current = $this->toFloat($row['latest_price'] ?? null);
            }
            $support = $this->toFloat($row['support_price'] ?? null);
            $resistance = $this->toFloat($row['resistance_price'] ?? null);
            $confidence = $this->toFloat($row['confidence'] ?? null) ?? 60.0;

            $trend = strtolower(trim((string) ($row['trend_direction'] ?? '')));
            if ($trend === '') {
                $trend = $this->resolveTrend($this->toFloat($row['change_pct'] ?? null));
            }
            $zone = strtolower(trim((string) ($row['position_zone'] ?? 'unknown')));

            $latestVolume = $this->toFloat($row['latest_volume'] ?? null);
            $cacheKey = $symbol . '|' . $market;
            if (!array_key_exists($cacheKey, $avgVolumeCache)) {
                $avgVolumeCache[$cacheKey] = $this->fetchAvgVolume20($symbol, $market);
            }
            $avgVolume = $avgVolumeCache[$cacheKey];
            $volumeRatio = null;
            if ($latestVolume !== null && $latestVolume > 0 && $avgVolume !== null && $avgVolume > 0) {
                $volumeRatio = $latestVolume / $avgVolume;
            }

            $sectorSignal = $this->getSectorSignal($sectorName);
            $newsSignal = $this->getNewsSignal($symbol, $name, $sectorName);

            $zoneScore = $this->scoreZone($zone);
            $trendScore = $this->scoreTrend($trend);
            $volumeScore = $this->scoreVolume($volumeRatio);
            $confidenceScore = max(-4, min(10, (int) round(($confidence - 60.0) / 4.0)));

            $locationScore = 0;
            $distanceToSupportPct = null;
            $distanceToResistancePct = null;

            if ($current !== null
                && $support !== null
                && $support > 0
                && $resistance !== null
                && $resistance > $support) {
                $distanceToSupportPct = (($current - $support) / $support) * 100.0;
                $distanceToResistancePct = (($resistance - $current) / max($current, 0.00001)) * 100.0;

                $range = max(0.0001, $resistance - $support);
                $positionInRange = ($current - $support) / $range;

                if ($positionInRange <= 0.25) {
                    $locationScore += 10;
                } elseif ($positionInRange >= 0.85) {
                    $locationScore -= 6;
                } else {
                    $locationScore += 2;
                }

                if ($current < $support * 0.99) {
                    $locationScore -= 12;
                }

                if ($current > $resistance * 1.01 && ($volumeRatio ?? 0.0) >= 1.3) {
                    $locationScore += 8;
                }
            }

            $rawScore = 50
                + $zoneScore
                + $trendScore
                + $volumeScore
                + $confidenceScore
                + $locationScore
                + (float) ($sectorSignal['score'] ?? 0)
                + (float) ($newsSignal['score'] ?? 0);

            $score = $this->clampScore($rawScore);
            [$grade, $tag] = $this->resolveGradeTag($score);

            $sectorScore = (float) ($sectorSignal['score'] ?? 0);
            $newsScore = (float) ($newsSignal['score'] ?? 0);

            $row['ranking_score'] = $score;
            $row['ranking_grade'] = $grade;
            $row['ranking_tag'] = $tag;
            $row['ranking_action'] = $this->resolveAction($zone, $trend, $volumeRatio, $sectorScore, $newsScore);

            $row['avg_volume_20'] = $avgVolume !== null ? round($avgVolume, 2) : null;
            $row['volume_ratio'] = $volumeRatio !== null ? round($volumeRatio, 2) : null;
            $row['distance_to_support_pct'] = $distanceToSupportPct !== null ? round($distanceToSupportPct, 2) : null;
            $row['distance_to_resistance_pct'] = $distanceToResistancePct !== null ? round($distanceToResistancePct, 2) : null;

            $row['sector_signal_score'] = round($sectorScore, 2);
            $row['sector_strength_score'] = $this->toFloat($sectorSignal['strength_score'] ?? null);
            $row['sector_change_pct'] = $this->toFloat($sectorSignal['change_pct'] ?? null);
            $row['sector_leading_symbol'] = (string) ($sectorSignal['leading_symbol'] ?? '');
            $row['sector_sample_time'] = (string) ($sectorSignal['sample_time'] ?? '');

            $row['news_signal_score'] = round($newsScore, 2);
            $row['news_hit_count_72h'] = (int) ($newsSignal['hits'] ?? 0);
            $row['news_latest_at'] = (string) ($newsSignal['latest_at'] ?? '');
        }
        unset($row);

        return $rows;
    }

    private function scoreTrend(string $trend): int
    {
        return match ($trend) {
            'strong_up' => 16,
            'up_bias' => 10,
            'range' => 4,
            'down_bias' => -8,
            'strong_down' => -14,
            default => 0,
        };
    }

    private function scoreZone(string $zone): int
    {
        return match ($zone) {
            'support_zone' => 15,
            'middle_zone' => 8,
            'resistance_zone' => -8,
            default => 0,
        };
    }

    private function scoreVolume(?float $ratio): int
    {
        if ($ratio === null) {
            return 0;
        }
        if ($ratio >= 2.0) {
            return 16;
        }
        if ($ratio >= 1.4) {
            return 10;
        }
        if ($ratio >= 1.1) {
            return 6;
        }
        if ($ratio >= 0.8) {
            return 0;
        }
        return -5;
    }

    private function clampScore(float $score): float
    {
        if ($score < 0) {
            return 0.0;
        }
        if ($score > 100) {
            return 100.0;
        }
        return round($score, 2);
    }

    /**
     * @return array{0:string,1:string}
     */
    private function resolveGradeTag(float $score): array
    {
        if ($score >= 78) {
            return ['A+', '优先关注'];
        }
        if ($score >= 65) {
            return ['A', '重点观察'];
        }
        if ($score >= 50) {
            return ['B', '跟踪观察'];
        }
        return ['C', '谨慎等待'];
    }

    private function resolveAction(string $zone, string $trend, ?float $volumeRatio, float $sectorScore, float $newsScore): string
    {
        $upTrends = ['strong_up', 'up_bias'];
        $downTrends = ['strong_down', 'down_bias'];

        if ($zone === 'support_zone' && in_array($trend, $upTrends, true)) {
            return '靠近支撑，可小仓试错，跌破支撑下方1%-2%止损';
        }
        if ($zone === 'resistance_zone' && in_array($trend, $downTrends, true)) {
            return '接近压力且偏弱，先减仓/观望，等放量突破再跟';
        }
        if (($volumeRatio ?? 0.0) >= 1.4 && in_array($trend, $upTrends, true)) {
            return '量能放大且趋势向上，可分批跟随';
        }
        if ($sectorScore >= 8 && in_array($trend, $upTrends, true)) {
            return '板块强度高且趋势偏强，优先关注回踩承接机会';
        }
        if ($newsScore >= 5 && in_array($trend, $upTrends, true)) {
            return '消息热度提升，关注放量突破与回踩确认';
        }
        if ($sectorScore <= -4 && in_array($trend, $downTrends, true)) {
            return '板块走弱叠加个股偏弱，谨慎等待止跌信号';
        }
        if (in_array($trend, $downTrends, true)) {
            return '趋势偏弱，优先等待止跌再考虑';
        }
        return '等待更清晰信号';
    }

    private function resolveTrend(?float $changePct): string
    {
        if ($changePct === null) {
            return '';
        }
        if ($changePct >= 2.0) {
            return 'strong_up';
        }
        if ($changePct >= 0.5) {
            return 'up_bias';
        }
        if ($changePct <= -2.0) {
            return 'strong_down';
        }
        if ($changePct <= -0.5) {
            return 'down_bias';
        }
        return 'range';
    }

    private function fetchAvgVolume20(string $symbol, string $market): ?float
    {
        if ($symbol === '') {
            return null;
        }

        try {
            $stmt = Database::connection()->prepare(
                'SELECT AVG(v) AS avg_volume
                 FROM (
                    SELECT volume AS v
                    FROM market_quotes
                    WHERE symbol = :symbol
                      AND market = :market
                      AND volume IS NOT NULL
                      AND volume > 0
                    ORDER BY id DESC
                    LIMIT 20
                 ) t'
            );

            $stmt->execute([
                'symbol' => $symbol,
                'market' => $market,
            ]);

            $value = $stmt->fetchColumn();
            if ($value === false || $value === null) {
                return null;
            }
            $num = (float) $value;
            return $num > 0 ? $num : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array{score:float,strength_score:float|null,change_pct:float|null,leading_symbol:string,sample_time:string}
     */
    private function getSectorSignal(string $sectorName): array
    {
        $key = trim($sectorName);
        if ($key === '') {
            return [
                'score' => 0.0,
                'strength_score' => null,
                'change_pct' => null,
                'leading_symbol' => '',
                'sample_time' => '',
            ];
        }

        if (isset($this->sectorSignalCache[$key])) {
            return $this->sectorSignalCache[$key];
        }

        $row = $this->fetchLatestSectorRow($key);
        if ($row === null) {
            $result = [
                'score' => 0.0,
                'strength_score' => null,
                'change_pct' => null,
                'leading_symbol' => '',
                'sample_time' => '',
            ];
            $this->sectorSignalCache[$key] = $result;
            return $result;
        }

        $strength = $this->toFloat($row['strength_score'] ?? null);
        $changePct = $this->toFloat($row['change_pct'] ?? null);

        $score = 0.0;
        if ($strength !== null) {
            if ($strength >= 80) {
                $score += 8;
            } elseif ($strength >= 60) {
                $score += 4;
            } elseif ($strength <= 30) {
                $score -= 3;
            }
        }

        if ($changePct !== null) {
            if ($changePct >= 2.0) {
                $score += 4;
            } elseif ($changePct >= 0.5) {
                $score += 2;
            } elseif ($changePct <= -2.0) {
                $score -= 4;
            } elseif ($changePct <= -0.5) {
                $score -= 2;
            }
        }

        $score = max(-8.0, min(12.0, $score));

        $result = [
            'score' => $score,
            'strength_score' => $strength,
            'change_pct' => $changePct,
            'leading_symbol' => (string) ($row['leading_symbol'] ?? ''),
            'sample_time' => (string) ($row['sample_time'] ?? ''),
        ];

        $this->sectorSignalCache[$key] = $result;
        return $result;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchLatestSectorRow(string $sectorName): ?array
    {
        try {
            $pdo = Database::connection();

            $exact = $pdo->prepare(
                'SELECT sector_name, strength_score, change_pct, leading_symbol, sample_time
                 FROM sector_strength
                 WHERE sector_name = :sector_name
                 ORDER BY sample_time DESC, id DESC
                 LIMIT 1'
            );
            $exact->execute(['sector_name' => $sectorName]);
            $row = $exact->fetch();
            if (is_array($row)) {
                return $row;
            }

            $keyword = mb_substr($sectorName, 0, max(2, min(6, mb_strlen($sectorName))));
            $fuzzy = $pdo->prepare(
                'SELECT sector_name, strength_score, change_pct, leading_symbol, sample_time
                 FROM sector_strength
                 WHERE sector_name LIKE :kw
                 ORDER BY sample_time DESC, strength_score DESC, id DESC
                 LIMIT 1'
            );
            $fuzzy->execute(['kw' => '%' . $keyword . '%']);
            $row = $fuzzy->fetch();
            return is_array($row) ? $row : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array{score:float,hits:int,latest_at:string}
     */
    private function getNewsSignal(string $symbol, string $name, string $sectorName): array
    {
        $newsRows = $this->recentNewsCache ?? [];
        if ($newsRows === []) {
            return [
                'score' => 0.0,
                'hits' => 0,
                'latest_at' => '',
            ];
        }

        $hits = 0;
        $latestAt = '';

        foreach ($newsRows as $news) {
            $title = (string) ($news['title'] ?? '');
            $summary = (string) ($news['summary'] ?? '');
            $publishedAt = (string) ($news['published_at'] ?? '');

            $match = false;
            if ($symbol !== '' && ($this->containsText($title, $symbol) || $this->containsText($summary, $symbol))) {
                $match = true;
            }
            if (!$match && $name !== '' && ($this->containsText($title, $name) || $this->containsText($summary, $name))) {
                $match = true;
            }
            if (!$match && $sectorName !== '' && ($this->containsText($title, $sectorName) || $this->containsText($summary, $sectorName))) {
                $match = true;
            }

            if ($match) {
                $hits++;
                if ($latestAt === '' || strcmp($publishedAt, $latestAt) > 0) {
                    $latestAt = $publishedAt;
                }
            }
        }

        $score = 0.0;
        if ($hits >= 8) {
            $score = 8.0;
        } elseif ($hits >= 4) {
            $score = 5.0;
        } elseif ($hits >= 2) {
            $score = 2.0;
        } elseif ($hits >= 1) {
            $score = 1.0;
        }

        return [
            'score' => $score,
            'hits' => $hits,
            'latest_at' => $latestAt,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadRecentNews(int $limit, int $hours): array
    {
        try {
            $stmt = Database::connection()->prepare(
                'SELECT id, title, summary, source, sentiment, published_at
                 FROM news_feed
                 WHERE published_at >= DATE_SUB(NOW(), INTERVAL :hours HOUR)
                 ORDER BY published_at DESC, id DESC
                 LIMIT :limit'
            );
            $stmt->bindValue(':hours', max(1, min(168, $hours)), \PDO::PARAM_INT);
            $stmt->bindValue(':limit', max(20, min(1000, $limit)), \PDO::PARAM_INT);
            $stmt->execute();

            return $stmt->fetchAll() ?: [];
        } catch (\Throwable) {
            return [];
        }
    }

    private function containsText(string $haystack, string $needle): bool
    {
        if ($haystack === '' || trim($needle) === '') {
            return false;
        }

        if (function_exists('mb_stripos')) {
            return mb_stripos($haystack, $needle) !== false;
        }

        return stripos($haystack, $needle) !== false;
    }

    private function toFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            return null;
        }
        return (float) $value;
    }
}