<?php

declare(strict_types=1);

class Database
{
    private static ?PDO $instance = null;

    public static function get(): PDO
    {
        if (self::$instance === null) {
            $host = getenv('RENDEZVOX_DB_HOST') ?: 'postgres';
            $port = getenv('RENDEZVOX_DB_PORT') ?: '5432';
            $name = getenv('RENDEZVOX_DB_NAME') ?: 'rendezvox';
            $user = getenv('RENDEZVOX_DB_USER') ?: 'rendezvox';
            $pass = getenv('RENDEZVOX_DB_PASSWORD') ?: '';

            $dsn = "pgsql:host={$host};port={$port};dbname={$name}";

            self::$instance = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        }

        return self::$instance;
    }

    public static function reconnect(): void
    {
        self::$instance = null;
    }

    public static function ensureRotationState(): void
    {
        self::get()->exec("
            INSERT INTO rotation_state (id, is_playing, is_emergency, current_position, current_cycle, playback_offset_ms, songs_since_jingle, last_artist_ids)
            VALUES (1, false, false, 0, 0, 0, 0, '{}')
            ON CONFLICT (id) DO NOTHING
        ");
    }

    private function __construct() {}
}
