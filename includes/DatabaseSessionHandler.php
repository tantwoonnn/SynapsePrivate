<?php
declare(strict_types=1);

/** Stores hosted PHP sessions in PostgreSQL so requests can run on separate Vercel instances. */
final class DatabaseSessionHandler implements SessionHandlerInterface
{
    public function __construct(private PDO $pdo) {}

    public function open(string $path, string $name): bool { return true; }
    public function close(): bool { return true; }

    public function read(string $id): string|false
    {
        $statement = $this->pdo->prepare('SELECT payload FROM app_sessions WHERE id = :id AND last_activity >= :cutoff');
        $statement->execute(['id' => $id, 'cutoff' => time() - (int) ini_get('session.gc_maxlifetime')]);
        $payload = $statement->fetchColumn();
        return $payload === false ? '' : (string) $payload;
    }

    public function write(string $id, string $data): bool
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO app_sessions (id, payload, last_activity) VALUES (:id, :payload, :activity) '
            . 'ON CONFLICT (id) DO UPDATE SET payload = EXCLUDED.payload, last_activity = EXCLUDED.last_activity'
        );
        return $statement->execute(['id' => $id, 'payload' => $data, 'activity' => time()]);
    }

    public function destroy(string $id): bool
    {
        $statement = $this->pdo->prepare('DELETE FROM app_sessions WHERE id = :id');
        return $statement->execute(['id' => $id]);
    }

    public function gc(int $max_lifetime): int|false
    {
        $statement = $this->pdo->prepare('DELETE FROM app_sessions WHERE last_activity < :cutoff');
        $statement->execute(['cutoff' => time() - $max_lifetime]);
        return $statement->rowCount();
    }
}
