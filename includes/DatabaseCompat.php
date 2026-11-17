<?php
declare(strict_types=1);

/** Small mysqli-shaped adapter so existing pages can run on PDO MySQL or PostgreSQL. */
final class SynapseConnection
{
    private string|false $lastInsertId = false;

    public function __construct(private PDO $pdo, private string $driver) {}

    public function pdo(): PDO { return $this->pdo; }

    public function prepare(string $sql): SynapseStatement
    {
        return new SynapseStatement($this->pdo, $sql, $this);
    }

    public function query(string $sql): SynapseResult
    {
        return new SynapseResult($this->pdo->query($sql));
    }

    public function begin_transaction(): bool { return $this->pdo->beginTransaction(); }
    public function commit(): bool { return $this->pdo->commit(); }
    public function rollback(): bool { return $this->pdo->rollBack(); }

    public function captureInsertId(string $sql): void
    {
        if (!preg_match('/^\s*INSERT\s+INTO\s+"?([A-Za-z_][A-Za-z0-9_]*)/i', $sql, $match)) return;
        if ($this->driver === 'pgsql') {
            // Some relation tables use composite keys rather than generated IDs.
            // Do not ask PostgreSQL for a sequence that those tables do not have.
            $table = $match[1];
            $column = $this->pdo->prepare(
                'SELECT column_default FROM information_schema.columns '
                . 'WHERE table_schema = current_schema() AND table_name = :table AND column_name = :column'
            );
            $column->execute(['table' => $table, 'column' => 'id']);
            $default = $column->fetchColumn();
            if (!is_string($default) || !str_contains($default, 'nextval(')) {
                $this->lastInsertId = false;
                return;
            }

            $statement = $this->pdo->prepare("SELECT currval(pg_get_serial_sequence(?, 'id'))");
            $statement->execute([$table]);
            $id = $statement->fetchColumn();
            $this->lastInsertId = $id === false ? false : (string) $id;
        } else {
            $this->lastInsertId = $this->pdo->lastInsertId();
        }
    }

    public function __get(string $name): mixed
    {
        return match ($name) {
            'insert_id' => $this->lastInsertId,
            'errno' => 0,
            default => null,
        };
    }
}

final class SynapseStatement
{
    private array $values = [];
    private ?PDOStatement $statement = null;
    public int $affected_rows = 0;

    public function __construct(private PDO $pdo, private string $sql, private SynapseConnection $connection) {}

    public function bind_param(string $types, &...$values): bool
    {
        $this->values = [];
        foreach ($values as &$value) $this->values[] = &$value;
        return true;
    }

    public function execute(): bool
    {
        $this->statement = $this->pdo->prepare($this->sql);
        $values = [];
        foreach ($this->values as $value) $values[] = $value;
        $result = $this->statement->execute($values);
        $this->affected_rows = $this->statement->rowCount();
        if ($result && preg_match('/^\s*INSERT\s+INTO\b/i', $this->sql)) {
            $this->connection->captureInsertId($this->sql);
        }
        return $result;
    }

    public function get_result(): SynapseResult
    {
        if ($this->statement === null) throw new LogicException('Execute the statement before reading its result.');
        return new SynapseResult($this->statement);
    }

    public function close(): void { $this->statement = null; }
}

final class SynapseResult
{
    private array $rows;
    private int $position = 0;
    public int $num_rows;

    public function __construct(PDOStatement $statement)
    {
        $this->rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $this->num_rows = count($this->rows);
    }

    public function fetch_assoc(): array|false
    {
        return $this->rows[$this->position++] ?? false;
    }

    public function fetch_row(): array|false
    {
        $row = $this->fetch_assoc();
        return $row === false ? false : array_values($row);
    }

    public function fetch_all(int $mode = 0): array
    {
        $remaining = array_slice($this->rows, $this->position);
        $this->position = count($this->rows);
        return $remaining;
    }
}
