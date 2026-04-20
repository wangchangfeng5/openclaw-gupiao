<?php

declare(strict_types=1);

use App\Controllers\AlertsController;
use App\Controllers\AuditController;
use App\Controllers\AuthController;
use App\Controllers\DataQualityController;
use App\Controllers\DashboardController;
use App\Controllers\InternalIngestController;
use App\Controllers\MarketController;
use App\Controllers\OpenClawCronController;
use App\Controllers\PositionsController;
use App\Controllers\StrategyTemplateController;
use App\Controllers\StreamController;
use App\Controllers\SuggestionsController;
use App\Controllers\SystemOpsController;
use App\Controllers\WatchlistController;
use App\Core\Router;

$router = new Router();

$auth = new AuthController();
$dashboard = new DashboardController();
$positions = new PositionsController();
$suggestions = new SuggestionsController();
$cron = new OpenClawCronController();
$market = new MarketController();
$stream = new StreamController();
$internal = new InternalIngestController();
$alerts = new AlertsController();
$watchlist = new WatchlistController();
$audit = new AuditController();
$system = new SystemOpsController();
$quality = new DataQualityController();
$strategy = new StrategyTemplateController();

$router->add('GET', '/api/health', static fn() => App\Support\JsonResponse::success(['status' => 'ok']), 'public');

$router->add('POST', '/api/auth/login', static fn() => $auth->login(), 'public');
$router->add('GET', '/api/auth/me', static fn() => $auth->me(), 'auth');
$router->add('POST', '/api/auth/logout', static fn() => $auth->logout(), 'auth');

$router->add('GET', '/api/dashboard/overview', static fn() => $dashboard->overview(), 'auth');

$router->add('GET', '/api/positions', static fn() => $positions->index(), 'auth');
$router->add('POST', '/api/positions', static fn() => $positions->store(), 'auth');
$router->add('PUT', '/api/positions', static fn() => $positions->update(), 'auth');
$router->add('DELETE', '/api/positions/{id}', static fn(array $p) => $positions->destroy($p), 'auth');
$router->add('GET', '/api/positions/analysis', static fn() => $positions->analysis(), 'auth');
$router->add('GET', '/api/positions/{id}/detail', static fn(array $p) => $positions->detail($p), 'auth');
$router->add('GET', '/api/positions/{id}/trades', static fn(array $p) => $positions->trades($p), 'auth');
$router->add('POST', '/api/positions/{id}/trades', static fn(array $p) => $positions->recordTrade($p), 'auth');
$router->add('POST', '/api/positions/{id}/trades/{tradeId}/undo', static fn(array $p) => $positions->undoTrade($p), 'auth');
$router->add('GET', '/api/positions/{id}/notes', static fn(array $p) => $positions->notes($p), 'auth');
$router->add('POST', '/api/positions/{id}/notes', static fn(array $p) => $positions->addNote($p), 'auth');

$router->add('GET', '/api/openclaw/suggestions', static fn() => $suggestions->index(), 'auth');
$router->add('POST', '/api/openclaw/suggestions/manual', static fn() => $suggestions->manualStore(), 'auth');
$router->add('POST', '/api/openclaw/suggestions/{id}/feedback', static fn(array $p) => $suggestions->feedback($p), 'auth');
$router->add('GET', '/api/openclaw/suggestions/metrics', static fn() => $suggestions->metrics(), 'auth');

$router->add('GET', '/api/openclaw/cron/jobs', static fn() => $cron->listJobs(), 'auth');
$router->add('POST', '/api/openclaw/cron/jobs', static fn() => $cron->createJob(), 'auth');
$router->add('PATCH', '/api/openclaw/cron/jobs/{id}', static fn(array $p) => $cron->patchJob($p), 'auth');
$router->add('POST', '/api/openclaw/cron/jobs/{id}/run', static fn(array $p) => $cron->runJob($p), 'auth');
$router->add('GET', '/api/openclaw/cron/jobs/{id}/runs', static fn(array $p) => $cron->runs($p), 'auth');
$router->add('POST', '/api/openclaw/cron/queue/flush', static fn() => $cron->flushQueue(), 'auth');

$router->add('GET', '/api/market/sectors/strength', static fn() => $market->sectorsStrength(), 'auth');
$router->add('GET', '/api/market/watchlist/candidates', static fn() => $market->watchlistCandidates(), 'auth');
$router->add('GET', '/api/market/close-rankings', static fn() => $market->closeRankings(), 'auth');
$router->add('GET', '/api/market/themes/overview', static fn() => $market->themesOverview(), 'auth');
$router->add('POST', '/api/market/themes', static fn() => $market->storeTheme(), 'auth');
$router->add('PUT', '/api/market/themes/{id}', static fn(array $p) => $market->updateTheme($p), 'auth');
$router->add('DELETE', '/api/market/themes/{id}', static fn(array $p) => $market->destroyTheme($p), 'auth');
$router->add('GET', '/api/news', static fn() => $market->news(), 'auth');
$router->add('GET', '/api/stream/events', static fn() => $stream->events(), 'auth');

$router->add('GET', '/api/alerts', static fn() => $alerts->index(), 'auth');
$router->add('PATCH', '/api/alerts/{id}/read', static fn(array $p) => $alerts->markRead($p), 'auth');

$router->add('GET', '/api/watchlist', static fn() => $watchlist->index(), 'auth');
$router->add('POST', '/api/watchlist', static fn() => $watchlist->store(), 'auth');
$router->add('PUT', '/api/watchlist/{id}', static fn(array $p) => $watchlist->update($p), 'auth');
$router->add('DELETE', '/api/watchlist/{id}', static fn(array $p) => $watchlist->destroy($p), 'auth');
$router->add('POST', '/api/watchlist/{id}/analyze', static fn(array $p) => $watchlist->analyze($p), 'auth');
$router->add('POST', '/api/watchlist/analyze-all', static fn() => $watchlist->analyzeAll(), 'auth');
$router->add('POST', '/api/watchlist/{id}/review', static fn(array $p) => $watchlist->review($p), 'auth');
$router->add('POST', '/api/watchlist/review-all', static fn() => $watchlist->reviewAll(), 'auth');
$router->add('POST', '/api/watchlist/review/snapshot', static fn() => $watchlist->snapshot(), 'auth');
$router->add('GET', '/api/watchlist/review/snapshots/global', static fn() => $watchlist->globalSnapshots(), 'auth');
$router->add('GET', '/api/watchlist/{id}/detail', static fn(array $p) => $watchlist->detail($p), 'auth');
$router->add('GET', '/api/watchlist/{id}/insights', static fn(array $p) => $watchlist->insights($p), 'auth');
$router->add('GET', '/api/watchlist/{id}/reviews', static fn(array $p) => $watchlist->reviews($p), 'auth');
$router->add('GET', '/api/watchlist/{id}/review/snapshots', static fn(array $p) => $watchlist->symbolSnapshots($p), 'auth');

$router->add('GET', '/api/strategy/templates', static fn() => $strategy->index(), 'auth');
$router->add('POST', '/api/strategy/templates', static fn() => $strategy->store(), 'auth');
$router->add('POST', '/api/strategy/templates/{id}/instantiate', static fn(array $p) => $strategy->instantiate($p), 'auth');

$router->add('GET', '/api/audit/logs', static fn() => $audit->index(), 'auth');
$router->add('GET', '/api/quality/logs', static fn() => $quality->index(), 'auth');
$router->add('POST', '/api/system/backup', static fn() => $system->backup(), 'auth');
$router->add('POST', '/api/system/risk/snapshot', static fn() => $system->snapshotRisk(), 'auth');
$router->add('POST', '/api/system/ingest/once', static fn() => $system->runIngestOnce(), 'auth');

$router->add('POST', '/api/internal/ingest/suggestions', static fn() => $internal->ingestSuggestions(), 'internal');
$router->add('POST', '/api/internal/ingest/sectors', static fn() => $internal->ingestSectors(), 'internal');
$router->add('POST', '/api/internal/ingest/news', static fn() => $internal->ingestNews(), 'internal');
$router->add('POST', '/api/internal/ingest/quotes', static fn() => $internal->ingestQuotes(), 'internal');
$router->add('POST', '/api/internal/ingest/close-rankings', static fn() => $internal->ingestCloseRankings(), 'internal');
$router->add('POST', '/api/internal/quality/log', static fn() => $internal->qualityLog(), 'internal');
$router->add('GET', '/api/internal/market/symbols', static fn() => $internal->marketSymbols(), 'internal');

return $router;
