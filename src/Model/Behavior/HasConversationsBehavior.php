<?php
declare(strict_types=1);

namespace Crustum\Ai\Model\Behavior;

use Cake\ORM\Behavior;

/**
 * Creates a hasMany association to agent conversations scoped by participant.
 *
 * Usage:
 * ```
 * public function initialize(array $config): void
 * {
 *     $this->addBehavior('Crustum/Ai.HasConversations');
 * }
 * ```
 */
class HasConversationsBehavior extends Behavior
{
    /**
     * @param array<string, mixed> $config Configuration options.
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        if (!$this->_table->hasAssociation('Conversations')) {
            $this->_table->hasMany('Conversations', [
                'className' => 'Crustum/Ai.Conversations',
                'foreignKey' => 'participant_id',
                'bindingKey' => $this->_table->getPrimaryKey(),
                'conditions' => [
                    'Conversations.participant_type' => $this->_table->getEntityClass(),
                ],
                'propertyName' => 'conversations',
            ]);
        }
    }
}
