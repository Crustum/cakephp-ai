<?php
declare(strict_types=1);

namespace Crustum\Ai\Model\Table;

use Cake\Core\Configure;
use Cake\ORM\Table;
use Cake\Validation\Validator;
use Crustum\Ai\Model\Entity\Conversation;
use Override;

/**
 * Conversations table.
 *
 * @method \Crustum\Ai\Model\Entity\Conversation newEmptyEntity()
 * @method \Crustum\Ai\Model\Entity\Conversation newEntity(array<string, mixed> $data, array<string, mixed> $options = [])
 * @method \Crustum\Ai\Model\Entity\Conversation get(mixed $primaryKey, array<string, mixed> $options = [])
 * @method \Crustum\Ai\Model\Entity\Conversation saveOrFail(\Cake\Datasource\EntityInterface $entity, array<string, mixed> $options = [])
 */
class ConversationsTable extends Table
{
    /**
     * @return string
     */
    #[Override]
    public static function defaultConnectionName(): string
    {
        return (string)Configure::read('Ai.conversations.connection', 'default');
    }

    /**
     * @param array<string, mixed> $config Table configuration.
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable((string)Configure::read('Ai.conversations.tables.conversations', 'agent_conversations'));
        $this->setEntityClass(Conversation::class);
        $this->setPrimaryKey('id');
        $this->setDisplayField('title');

        $this->hasMany('ConversationMessages', [
            'className' => ConversationMessagesTable::class,
            'foreignKey' => 'conversation_id',
            'propertyName' => 'messages',
        ]);
    }

    /**
     * @param \Cake\Validation\Validator $validator Validator instance.
     * @return \Cake\Validation\Validator
     */
    #[Override]
    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->scalar('id')
            ->maxLength('id', 36)
            ->requirePresence('id', 'create')
            ->notEmptyString('id')
            ->scalar('title')
            ->requirePresence('title', 'create')
            ->notEmptyString('title');

        return $validator;
    }
}
