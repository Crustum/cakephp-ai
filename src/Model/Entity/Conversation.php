<?php
declare(strict_types=1);

namespace Crustum\Ai\Model\Entity;

use Cake\Datasource\EntityInterface;
use Cake\ORM\Entity;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;
use Cake\Utility\Inflector;
use InvalidArgumentException;
use Throwable;

/**
 * Conversation entity.
 *
 * @property string $id
 * @property string|int|null $participant_id
 * @property string|null $participant_type
 * @property string $title
 * @property \Cake\I18n\DateTime|null $created
 * @property \Cake\I18n\DateTime|null $modified
 * @property list<\Crustum\Ai\Model\Entity\ConversationMessage>|null $messages
 * @property list<\Crustum\Ai\Model\Entity\ConversationMessage>|null $conversation_messages
 * @property object|null $participant
 */
class Conversation extends Entity
{
    /**
     * @var array<string, bool>
     */
    protected array $_accessible = [
        'id' => true,
        'participant_id' => true,
        'participant_type' => true,
        'title' => true,
        'created' => true,
        'modified' => true,
        'conversation_messages' => true,
        'messages' => true,
    ];

    /**
     * Resolve the participant_type discriminator to record for the participant.
     *
     * @param object $participant Conversation participant
     * @return string
     */
    public static function participantType(object $participant): string
    {
        return $participant::class;
    }

    /**
     * Resolve the participant_id key to record for the participant.
     *
     * @param object $participant Conversation participant
     * @return string|int
     */
    public static function participantKey(object $participant): string|int
    {
        if ($participant instanceof EntityInterface && $participant->has('id')) {
            $key = $participant->get('id');

            if ($key !== null && $key !== '') {
                return is_int($key) ? $key : (string)$key;
            }
        }

        if (isset($participant->id)) {
            return is_int($participant->id) ? $participant->id : (string)$participant->id;
        }

        throw new InvalidArgumentException(
            'The conversation participant must expose an [id] property or field.',
        );
    }

    /**
     * Resolve the conversation participant through its polymorphic relationship.
     *
     * @return object|null
     */
    protected function _getParticipant(): ?object
    {
        if (empty($this->participant_type) || empty($this->participant_id)) {
            return null;
        }

        $table = $this->resolveParticipantTable();

        if (!$table instanceof Table) {
            return null;
        }

        return $table->find()
            ->where([$table->getPrimaryKey() => $this->participant_id])
            ->first();
    }

    /**
     * Resolve the table for the configured participant type.
     *
     * @return \Cake\ORM\Table|null
     */
    protected function resolveParticipantTable(): ?Table
    {
        $type = $this->participant_type;

        if (class_exists($type) && is_subclass_of($type, EntityInterface::class)) {
            $position = strrpos($type, '\\');

            $namespace = $position !== false ? substr($type, 0, $position) : '';
            $shortName = $position !== false ? substr($type, $position + 1) : $type;

            $tableClass = str_replace('\\Entity', '\\Table', $namespace)
                . '\\' . Inflector::pluralize($shortName) . 'Table';

            if (class_exists($tableClass)) {
                return TableRegistry::getTableLocator()->get($tableClass);
            }
        }

        try {
            return TableRegistry::getTableLocator()->get($type);
        } catch (Throwable) {
            return null;
        }
    }
}
