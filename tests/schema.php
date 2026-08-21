<?php
declare(strict_types=1);

/**
 * Test database schema for Ai plugin tests.
 */
return [
    [
        'table' => 'users',
        'columns' => [
            'id' => [
                'type' => 'integer',
                'autoIncrement' => true,
            ],
            'name' => [
                'type' => 'string',
                'length' => 255,
                'null' => false,
            ],
            'created' => [
                'type' => 'datetime',
                'null' => true,
            ],
            'modified' => [
                'type' => 'datetime',
                'null' => true,
            ],
        ],
        'constraints' => [
            'primary' => [
                'type' => 'primary',
                'columns' => [
                    'id',
                ],
            ],
        ],
    ],
    [
        'table' => 'agent_conversations',
        'columns' => [
            'id' => [
                'type' => 'uuid',
                'null' => false,
            ],
            'participant_id' => [
                'type' => 'uuid',
                'null' => true,
            ],
            'participant_type' => [
                'type' => 'string',
                'length' => 255,
                'null' => true,
            ],
            'title' => [
                'type' => 'string',
                'length' => 255,
                'null' => false,
            ],
            'created' => [
                'type' => 'datetime',
                'null' => true,
            ],
            'modified' => [
                'type' => 'datetime',
                'null' => true,
            ],
        ],
        'constraints' => [
            'primary' => [
                'type' => 'primary',
                'columns' => [
                    'id',
                ],
            ],
        ],
        'indexes' => [
            'agent_conversations_participant_modified' => [
                'type' => 'index',
                'columns' => [
                    'participant_type',
                    'participant_id',
                    'modified',
                ],
            ],
        ],
    ],
    [
        'table' => 'agent_conversation_messages',
        'columns' => [
            'id' => [
                'type' => 'uuid',
                'null' => false,
            ],
            'conversation_id' => [
                'type' => 'uuid',
                'null' => false,
            ],
            'participant_id' => [
                'type' => 'uuid',
                'null' => true,
            ],
            'participant_type' => [
                'type' => 'string',
                'length' => 255,
                'null' => true,
            ],
            'agent' => [
                'type' => 'string',
                'length' => 255,
                'null' => false,
            ],
            'role' => [
                'type' => 'string',
                'length' => 25,
                'null' => false,
            ],
            'content' => [
                'type' => 'text',
                'null' => false,
            ],
            'attachments' => [
                'type' => 'json',
                'null' => false,
            ],
            'tool_calls' => [
                'type' => 'json',
                'null' => false,
            ],
            'tool_results' => [
                'type' => 'json',
                'null' => false,
            ],
            'usage_data' => [
                'type' => 'json',
                'null' => false,
            ],
            'meta' => [
                'type' => 'json',
                'null' => false,
            ],
            'approval_state' => [
                'type' => 'text',
                'null' => true,
            ],
            'created' => [
                'type' => 'datetime',
                'null' => true,
            ],
            'modified' => [
                'type' => 'datetime',
                'null' => true,
            ],
        ],
        'constraints' => [
            'primary' => [
                'type' => 'primary',
                'columns' => [
                    'id',
                ],
            ],
        ],
        'indexes' => [
            'agent_conversation_messages_conversation_id' => [
                'type' => 'index',
                'columns' => [
                    'conversation_id',
                ],
            ],
            'agent_conversation_messages_conversation_index' => [
                'type' => 'index',
                'columns' => [
                    'conversation_id',
                    'participant_type',
                    'participant_id',
                    'modified',
                ],
            ],
            'agent_conversation_messages_participant' => [
                'type' => 'index',
                'columns' => [
                    'participant_type',
                    'participant_id',
                ],
            ],
        ],
    ],
    [
        'table' => 'custom_conversations',
        'columns' => [
            'id' => [
                'type' => 'uuid',
                'null' => false,
            ],
            'participant_id' => [
                'type' => 'uuid',
                'null' => true,
            ],
            'participant_type' => [
                'type' => 'string',
                'length' => 255,
                'null' => true,
            ],
            'title' => [
                'type' => 'string',
                'length' => 255,
                'null' => false,
            ],
            'created' => [
                'type' => 'datetime',
                'null' => true,
            ],
            'modified' => [
                'type' => 'datetime',
                'null' => true,
            ],
        ],
        'constraints' => [
            'primary' => [
                'type' => 'primary',
                'columns' => [
                    'id',
                ],
            ],
        ],
        'indexes' => [
            'custom_conversations_participant_modified' => [
                'type' => 'index',
                'columns' => [
                    'participant_type',
                    'participant_id',
                    'modified',
                ],
            ],
        ],
    ],
    [
        'table' => 'custom_conversation_messages',
        'columns' => [
            'id' => [
                'type' => 'uuid',
                'null' => false,
            ],
            'conversation_id' => [
                'type' => 'uuid',
                'null' => false,
            ],
            'participant_id' => [
                'type' => 'uuid',
                'null' => true,
            ],
            'participant_type' => [
                'type' => 'string',
                'length' => 255,
                'null' => true,
            ],
            'agent' => [
                'type' => 'string',
                'length' => 255,
                'null' => false,
            ],
            'role' => [
                'type' => 'string',
                'length' => 25,
                'null' => false,
            ],
            'content' => [
                'type' => 'text',
                'null' => false,
            ],
            'attachments' => [
                'type' => 'json',
                'null' => false,
            ],
            'tool_calls' => [
                'type' => 'json',
                'null' => false,
            ],
            'tool_results' => [
                'type' => 'json',
                'null' => false,
            ],
            'usage_data' => [
                'type' => 'json',
                'null' => false,
            ],
            'meta' => [
                'type' => 'json',
                'null' => false,
            ],
            'approval_state' => [
                'type' => 'text',
                'null' => true,
            ],
            'created' => [
                'type' => 'datetime',
                'null' => true,
            ],
            'modified' => [
                'type' => 'datetime',
                'null' => true,
            ],
        ],
        'constraints' => [
            'primary' => [
                'type' => 'primary',
                'columns' => [
                    'id',
                ],
            ],
        ],
        'indexes' => [
            'custom_conversation_messages_conversation_id' => [
                'type' => 'index',
                'columns' => [
                    'conversation_id',
                ],
            ],
            'custom_conversation_messages_conversation_index' => [
                'type' => 'index',
                'columns' => [
                    'conversation_id',
                    'participant_type',
                    'participant_id',
                    'modified',
                ],
            ],
            'custom_conversation_messages_participant' => [
                'type' => 'index',
                'columns' => [
                    'participant_type',
                    'participant_id',
                ],
            ],
        ],
    ],
];
