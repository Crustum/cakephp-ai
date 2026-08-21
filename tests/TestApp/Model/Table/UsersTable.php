<?php
declare(strict_types=1);

namespace TestApp\Model\Table;

use Cake\ORM\Table;
use Cake\Validation\Validator;
use Override;

/**
 * Users table for conversation relationship tests.
 *
 * @property \Cake\ORM\Association\HasMany $Conversations
 * @method \TestApp\Model\Entity\User newEmptyEntity()
 * @method \TestApp\Model\Entity\User newEntity(array<string, mixed> $data, array<string, mixed> $options = [])
 * @method \TestApp\Model\Entity\User saveOrFail(\Cake\Datasource\EntityInterface $entity, array<string, mixed> $options = [])
 */
class UsersTable extends Table
{
    /**
     * @param array<string, mixed> $config Table configuration.
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('users');
        $this->setPrimaryKey('id');
        $this->setDisplayField('name');
        $this->setEntityClass('TestApp.User');

        $this->addBehavior('Crustum/Ai.HasConversations');
    }

    /**
     * @param \Cake\Validation\Validator $validator Validator instance.
     * @return \Cake\Validation\Validator
     */
    #[Override]
    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->scalar('name')
            ->maxLength('name', 255)
            ->requirePresence('name', 'create')
            ->notEmptyString('name');

        return $validator;
    }
}
