<?php
declare(strict_types=1);

namespace Crustum\Ai\Test\Fixtures\SimilaritySearch;

use Cake\Database\Schema\TableSchema;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\Table;

/**
 * Fake table for similarity search tests.
 */
class FakeVectorTable extends Table
{
    /**
     * @inheritDoc
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setSchema(new TableSchema($this->getTable(), [
            'id' => 'integer',
            'content' => 'string',
            'embedding' => 'string',
        ]));
    }

    /**
     * Return a fake query for the `similarTo` finder without touching the database.
     *
     * @param string $type Finder type.
     * @param mixed ...$args Finder arguments.
     * @return \Cake\ORM\Query\SelectQuery
     */
    public function find(string $type = 'all', mixed ...$args): SelectQuery
    {
        if ($type === 'similarTo') {
            return new FakeVectorQuery($this, $args);
        }

        return parent::find($type, ...$args);
    }
}
