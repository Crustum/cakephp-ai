<?php
declare(strict_types=1);

namespace Crustum\Ai\Test\Support\Database;

/**
 * Canonical UUIDs shared by the conversation storage and relationship tests.
 *
 * Conversation, message and participant columns are uuid-typed, so tests must
 * use valid UUIDs rather than integer or ad-hoc identifiers.
 */
final class ConversationFixtures
{
    public const string PARTICIPANT = '00000000-0000-4000-8000-0000000000a1';

    public const string OTHER_PARTICIPANT = '00000000-0000-4000-8000-0000000000a2';

    public const string SECONDARY_PARTICIPANT = '00000000-0000-4000-8000-0000000000a3';

    public const string LATEST_PARTICIPANT = '11111111-1111-4111-8111-111111111111';

    public const string MISSING_PARTICIPANT = '22222222-2222-4222-8222-222222222222';

    public const string STREAMING_PARTICIPANT = '77777777-7777-4777-8777-777777777777';

    public const string CONVERSATION_ONE = '00000000-0000-4000-8000-000000000001';

    public const string CONVERSATION_TWO = '00000000-0000-4000-8000-000000000002';

    public const string CONVERSATION_THREE = '00000000-0000-4000-8000-000000000003';

    public const string SECONDARY_CONVERSATION = '00000000-0000-4000-8000-000000000004';

    public const string MISSING_CONVERSATION = '00000000-0000-4000-8000-0000000000ff';

    public const string MESSAGE_ONE = '10000000-0000-4000-8000-000000000001';

    public const string MESSAGE_TWO = '10000000-0000-4000-8000-000000000002';

    public const string MESSAGE_THREE = '10000000-0000-4000-8000-000000000003';

    public const string MESSAGE_FOUR = '10000000-0000-4000-8000-000000000004';

    public const string MESSAGE_FIVE = '10000000-0000-4000-8000-000000000005';
}
