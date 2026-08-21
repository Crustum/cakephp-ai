<?php
declare(strict_types=1);

namespace Crustum\Ai\Test\Fixtures\SimilaritySearch;

use Cake\Datasource\ResultSetInterface;
use Cake\ORM\Query;
use Cake\ORM\ResultSet;
use Cake\ORM\Table;

/**
 * Fake query for similarity search tests.
 */
class FakeVectorQuery extends Query
{
    /**
     * @param \Cake\ORM\Table $table The table instance.
     * @param array<mixed> $options SimilarTo finder arguments.
     */
    public function __construct(Table $table, protected array $options = [])
    {
        parent::__construct($table->getConnection(), $table);
    }

    /**
     * Execute the query, returning canned results.
     *
     * @return \Cake\Datasource\ResultSetInterface
     */
    public function all(): ResultSetInterface
    {
        $rows = [
            ['id' => 1, 'content' => 'First document', 'embedding' => [0.1, 0.2, 0.3]],
            ['id' => 2, 'content' => 'Second document', 'embedding' => [0.4, 0.5, 0.6]],
        ];

        // Associative select so the ResultSet column map uses string keys
        // (a numeric-list select yields int keys that trim() rejects).
        $this->select([
            'id' => 'id',
            'content' => 'content',
            'embedding' => 'embedding',
        ]);

        return new ResultSet($this, new FakeVectorStatement($rows));
    }
}
