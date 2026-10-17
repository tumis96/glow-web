<?php
/**
 * Google Kalendář – rozhraní, které volá zbytek aplikace.
 * Když není nastaveno google_calendar_id nebo chybí klíč service accountu, všechno tiše přeskočí.
 *
 * gcalEnabled(): bool
 * gcalUpsertBooking(array $b): ?string   – vytvoří/aktualizuje událost pro potvrzenou rezervaci, vrátí ID události
 * gcalDeleteBooking(array $b): void      – smaže událost (zrušení/odmítnutí)
 * gcalPullBlocks(): int                  – stáhne cizí události z kalendáře do blocked_slots (source=gcal), vrátí počet
 *
 * Implementace je v gcal-impl.php (doplní se, až bude service account); tento soubor je bezpečný fallback.
 */
declare(strict_types=1);

if (is_file(__DIR__ . '/gcal-impl.php')) {
    require_once __DIR__ . '/gcal-impl.php';
}

if (!function_exists('gcalEnabled')) {
    function gcalEnabled(): bool { return false; }
    function gcalUpsertBooking(array $b): ?string { return null; }
    function gcalDeleteBooking(array $b): void {}
    function gcalPullBlocks(): int { return 0; }
}
