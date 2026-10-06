<?php
declare(strict_types=1);

namespace Crustum\Ai\Model\Entity;

use Cake\ORM\Entity;
use Crustum\Ai\Approvals\PendingApproval;

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
 * @property array<int|string, mixed>|null $steps
 * @property-read array<int|string, mixed> $tool_calls
 * @property-read array<int|string, mixed> $provider_tool_calls
 * @property-read array<int|string, mixed> $tool_results
 * @property array<int|string, mixed>|null $usage_data
 * @property array<int|string, mixed>|null $meta
 * @property \Crustum\Ai\Enums\MessageStatus|null $status
 * @property \Cake\I18n\DateTime|null $created
 * @property \Cake\I18n\DateTime|null $modified
 * @property \Crustum\Ai\Model\Entity\Conversation|null $conversation
 */
class ConversationMessage extends Entity
{
    /**
     * @var array<string, bool>
     */
    protected array $_accessible = [
        'id' => true,
        'conversation_id' => true,
        'participant_id' => true,
        'participant_type' => true,
        'agent' => true,
        'role' => true,
        'content' => true,
        'attachments' => true,
        'steps' => true,
        'usage_data' => true,
        'meta' => true,
        'status' => true,
        'created' => true,
        'modified' => true,
        'conversation' => true,
    ];

    /**
     * @var list<string>
     */
    protected array $_virtual = ['tool_calls', 'tool_results', 'provider_tool_calls'];

    /**
     * The tool calls made across every step of the turn, in step order.
     *
     * @return list<array<string, mixed>>
     */
    protected function _getToolCalls(): array
    {
        $collapsed = [];

        foreach ((array)($this->get('steps') ?? []) as $step) {
            foreach ((array)($step['tool_calls'] ?? []) as $toolCall) {
                $collapsed[] = $toolCall;
            }
        }

        return $collapsed;
    }

    /**
     * The provider-hosted tool calls made across every step of the turn, in step order.
     *
     * @return list<array<string, mixed>>
     */
    protected function _getProviderToolCalls(): array
    {
        $collapsed = [];

        foreach ((array)($this->get('steps') ?? []) as $step) {
            foreach ((array)($step['provider_tool_calls'] ?? []) as $toolCall) {
                $collapsed[] = $toolCall;
            }
        }

        return $collapsed;
    }

    /**
     * The tool results recorded across every step of the turn, in step order.
     *
     * @return list<array<string, mixed>>
     */
    protected function _getToolResults(): array
    {
        return array_values(array_map(
            fn(array $toolCall): array => array_intersect_key(
                $toolCall,
                ['id' => true, 'name' => true, 'arguments' => true, 'result' => true, 'result_id' => true, 'denied' => true, 'failed' => true],
            ),
            array_filter(
                $this->_getToolCalls(),
                PendingApproval::isAnswered(...),
            ),
        ));
    }
}
