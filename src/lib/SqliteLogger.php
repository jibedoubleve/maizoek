<?php
require_once __DIR__ . '/psr/LogLevel.php';
require_once __DIR__ . '/psr/LoggerInterface.php';
require_once __DIR__ . '/psr/AbstractLogger.php';

use Psr\Log\AbstractLogger;

define('LOG_RETENTION_DAYS', 30);

class SqliteLogger extends AbstractLogger
{
    private PDO $db;

    public static function ensureTable(PDO $db): void
    {
        $db->exec('CREATE TABLE IF NOT EXISTS logs (
            id      INTEGER PRIMARY KEY AUTOINCREMENT,
            ts      INTEGER NOT NULL,
            level   TEXT    NOT NULL,
            ctx     TEXT,
            message TEXT,
            data    TEXT
        )');
        $db->exec('CREATE INDEX IF NOT EXISTS logs_ts    ON logs (ts)');
        $db->exec('CREATE INDEX IF NOT EXISTS logs_level ON logs (level)');
    }

    public function __construct(string $db_path)
    {
        $this->db = new PDO('sqlite:' . $db_path);
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        self::ensureTable($this->db);
        $this->pruneOldEntries();
    }

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $ctx = $context['ctx'] ?? null;
        unset($context['ctx']);

        $stmt = $this->db->prepare(
            'INSERT INTO logs (ts, level, ctx, message, data) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            time(),
            $level,
            $ctx,
            (string) $message,
            empty($context) ? null : json_encode($context, JSON_UNESCAPED_UNICODE),
        ]);
    }

    private function pruneOldEntries(): void
    {
        $cutoff = time() - (LOG_RETENTION_DAYS * 86400);
        $this->db->prepare('DELETE FROM logs WHERE ts < ?')->execute([$cutoff]);
    }
}
