<?php
declare(strict_types=1);

namespace Crustum\Ai\Test\Fixtures\SimilaritySearch;

use Cake\Database\StatementInterface;

/**
 * Minimal in-memory statement for similarity search fixture queries.
 *
 * Yields canned associative rows so {@see \Cake\ORM\ResultSet} can hydrate them.
 */
class FakeVectorStatement implements StatementInterface
{
    /**
     * @param array<int, array<string, mixed>> $rows Canned result rows.
     */
    public function __construct(protected array $rows = [])
    {
    }

    /**
     * @inheritDoc
     */
    public function bindValue($column, $value, $type = 'string'): void
    {
    }

    /**
     * @inheritDoc
     */
    public function closeCursor(): void
    {
        $this->position = 0;
    }

    /**
     * @inheritDoc
     */
    public function columnCount(): int
    {
        return count($this->rows[0] ?? []);
    }

    /**
     * @inheritDoc
     */
    public function errorCode()
    {
        return '00000';
    }

    /**
     * @inheritDoc
     */
    public function errorInfo(): array
    {
        return ['00000', null, null];
    }

    /**
     * @inheritDoc
     */
    public function execute(?array $params = null): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function fetch($type = 'num')
    {
        if ($this->position >= count($this->rows)) {
            return false;
        }

        $row = $this->rows[$this->position];
        $this->position++;

        return $type === 'assoc' ? $row : array_values($row);
    }

    /**
     * @inheritDoc
     */
    public function fetchAll($type = 'num')
    {
        $all = [];

        while (($row = $this->fetch($type)) !== false) {
            $all[] = $row;
        }

        return $all;
    }

    /**
     * @inheritDoc
     */
    public function fetchColumn(int $position)
    {
        $row = $this->fetch('num');

        return $row[$position] ?? null;
    }

    /**
     * @inheritDoc
     */
    public function rowCount(): int
    {
        return count($this->rows);
    }

    /**
     * @inheritDoc
     */
    public function count(): int
    {
        return count($this->rows);
    }

    /**
     * @inheritDoc
     */
    public function bind(array $params, array $types): void
    {
    }

    /**
     * @inheritDoc
     */
    public function lastInsertId(?string $table = null, ?string $column = null)
    {
        return null;
    }

    /**
     * @var int
     */
    private int $position = 0;
}
