<?php
declare(strict_types=1);

namespace Crustum\Ai\Model\Entity;

use Cake\ORM\Entity;

/**
 * Conversation message entity.
 *
 * @property string $id
 * @property string $conversation_id
 * @property string|int|null $participant_id
 * @property string|null $participant_type
 * @property string $agent
 * @property string $role
 * @property string|null $content
 * @property array<int|string, mixed>|null $attachments
 * @property array<int|string, mixed>|null $tool_calls
 * @property array<int|string, mixed>|null $tool_results
 * @property array<int|string, mixed>|null $usage_data
 * @property array<int|string, mixed>|null $meta
 * @property string|null $approval_state
 * @property \Cake\I18n\FrozenTime|null $created
 * @property \Cake\I18n\FrozenTime|null $modified
 * @property \Crustum\Ai\Model\Entity\Conversation|null $conversation
 */
class ConversationMessage extends Entity
{
    /**
     * @var array<string, bool>
     */
    protected $_accessible = [
        'id' => true,
        'conversation_id' => true,
        'participant_id' => true,
        'participant_type' => true,
        'agent' => true,
        'role' => true,
        'content' => true,
        'attachments' => true,
        'tool_calls' => true,
        'tool_results' => true,
        'usage_data' => true,
        'meta' => true,
        'approval_state' => true,
        'created' => true,
        'modified' => true,
        'conversation' => true,
    ];
}
