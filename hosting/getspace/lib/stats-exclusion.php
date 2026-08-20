<?php
declare(strict_types=1);

const HGO_STATS_EXCLUSION_COOKIE = 'hgo_stats_excluded';
const HGO_STATS_EXCLUSION_TTL = 31536000; // 1 year

function stats_browser_is_excluded(): bool
{
    return (string)($_COOKIE[HGO_STATS_EXCLUSION_COOKIE] ?? '') === '1';
}

function stats_exclusion_cookie_options(int $expires): array
{
    $host = strtolower((string)preg_replace('/:\\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? '')));
    $isProductionDomain = $host === 'mgoutlet.pl' || $host === 'www.mgoutlet.pl';
    $isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

    $options = [
        'expires' => $expires,
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ];
    if ($isProductionDomain) {
        $options['domain'] = 'mgoutlet.pl';
    }
    return $options;
}

function set_stats_browser_excluded(bool $excluded): void
{
    setcookie(
        HGO_STATS_EXCLUSION_COOKIE,
        $excluded ? '1' : '',
        stats_exclusion_cookie_options($excluded ? time() + HGO_STATS_EXCLUSION_TTL : time() - 3600)
    );
}
