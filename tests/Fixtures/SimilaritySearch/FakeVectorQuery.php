<?php
declare(strict_types=1);

namespace Crustum\Ai\Test\Fixtures\SimilaritySearch;

use Cake\Datasource\ResultSetInterface;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\ResultSet;
use Cake\ORM\Table;

/**
 * Fake query for similarity search tests.
 */
class FakeVectorQuery extends SelectQuery
{
    /**
     * @param \Cake\ORM\Table $table The table instance.
     * @param array<mixed> $options SimilarTo finder arguments.
     */
    public function __construct(Table $table, protected array $options = [])
    {
        parent::__construct($table);
    }

    /**
     * Execute the query, returning canned results.
     *
     * @return \Cake\Datasource\ResultSetInterface
     */
    public function all(): ResultSetInterface
    {
        return new ResultSet([
            new FakeVectorEntity(['id' => 1, 'content' => 'First document', 'embedding' => [0.1, 0.2, 0.3]]),
            new FakeVectorEntity(['id' => 2, 'content' => 'Second document', 'embedding' => [0.4, 0.5, 0.6]]),
        ]);
    }
}
