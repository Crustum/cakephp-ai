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
                'type' => 'uuid',
                'null' => false,
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
                'type' => 'text',
                'null' => false,
            ],
            'steps' => [
                'type' => 'text',
                'null' => false,
            ],
            'usage_data' => [
                'type' => 'text',
                'null' => false,
            ],
            'meta' => [
                'type' => 'text',
                'null' => false,
            ],
            'status' => [
                'type' => 'string',
                'length' => 25,
                'null' => false,
                'default' => 'completed',
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
                'type' => 'text',
                'null' => false,
            ],
            'steps' => [
                'type' => 'text',
                'null' => false,
            ],
            'usage_data' => [
                'type' => 'text',
                'null' => false,
            ],
            'meta' => [
                'type' => 'text',
                'null' => false,
            ],
            'status' => [
                'type' => 'string',
                'length' => 25,
                'null' => false,
                'default' => 'completed',
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
