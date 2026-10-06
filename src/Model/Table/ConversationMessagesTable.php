<?php
declare(strict_types=1);

namespace Crustum\Ai\Model\Table;

use ArrayObject;
use Cake\Core\Configure;
use Cake\Database\Schema\TableSchemaInterface;
use Cake\Database\Type\EnumType;
use Cake\Event\EventInterface;
use Cake\ORM\Table;
use Cake\Validation\Validator;
use Crustum\Ai\Enums\MessageStatus;
use Crustum\Ai\Model\Entity\ConversationMessage;
use Override;

/**
 * Conversation messages table.
 *
 * @method \Crustum\Ai\Model\Entity\ConversationMessage newEmptyEntity()
 * @method \Crustum\Ai\Model\Entity\ConversationMessage newEntity(array<string, mixed> $data, array<string, mixed> $options = [])
 * @method \Crustum\Ai\Model\Entity\ConversationMessage get(mixed $primaryKey, array<string, mixed> $options = [])
 * @method \Crustum\Ai\Model\Entity\ConversationMessage saveOrFail(\Cake\Datasource\EntityInterface $entity, array<string, mixed> $options = [])
 */
class ConversationMessagesTable extends Table
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

        $this->setTable((string)Configure::read('Ai.conversations.tables.messages', 'agent_conversation_messages'));
        $this->setEntityClass(ConversationMessage::class);
        $this->setPrimaryKey('id');

        $this->belongsTo('Conversations', [
            'className' => ConversationsTable::class,
            'foreignKey' => 'conversation_id',
        ]);

        $schema = $this->getSchema();

        foreach (['attachments', 'steps', 'usage_data', 'meta'] as $field) {
            if ($schema->hasColumn($field)) {
                $schema->setColumnType($field, 'json');
            }
        }

        if ($schema->hasColumn('status')) {
            $schema->setColumnType('status', EnumType::from(MessageStatus::class));
        }
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
            ->scalar('conversation_id')
            ->maxLength('conversation_id', 36)
            ->requirePresence('conversation_id', 'create')
            ->notEmptyString('conversation_id')
            ->scalar('agent')
            ->requirePresence('agent', 'create')
            ->notEmptyString('agent')
            ->scalar('role')
            ->maxLength('role', 25)
            ->requirePresence('role', 'create')
            ->notEmptyString('role')
            ->scalar('content')
            ->requirePresence('content', 'create');

        return $validator;
    }

    /**
     * @param \Cake\Event\EventInterface<\Crustum\Ai\Model\Table\ConversationMessagesTable> $event Event instance.
     * @param \ArrayObject<string, mixed> $data Entity data.
     * @param \ArrayObject<string, mixed> $options Options.
     * @return void
     */
    public function beforeMarshal(EventInterface $event, ArrayObject $data, ArrayObject $options): void
    {
        foreach (['attachments', 'steps', 'usage_data', 'meta'] as $field) {
            $data[$field] ??= [];
        }
    }

    /**
     * @param \Cake\Database\Schema\TableSchemaInterface $schema Table schema.
     * @return \Cake\Database\Schema\TableSchemaInterface
     */
    protected function _initializeSchema(TableSchemaInterface $schema): TableSchemaInterface
    {
        $schema->setColumnType('attachments', 'json');
        $schema->setColumnType('steps', 'json');
        $schema->setColumnType('usage_data', 'json');
        $schema->setColumnType('meta', 'json');
        $schema->setColumnType('status', EnumType::from(MessageStatus::class));

        return $schema;
    }
}
