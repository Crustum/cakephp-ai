<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Crustum\Ai\Audio;
use Crustum\Ai\Enums\Lab;
use Crustum\Ai\Job\GenerateAudioJob;
use Crustum\Ai\Prompts\AudioPrompt;
use Crustum\Ai\Prompts\QueuedAudioPrompt;
use Crustum\Ai\Providers\ElevenLabsProvider;
use Crustum\Ai\Responses\AudioResponse;
use Crustum\Ai\Responses\Data\Meta;
use Crustum\Ai\Responses\Data\Usage;
use Crustum\Ai\Text;
use JMac\Testing\Double;
use JMac\Testing\Matching\Argument;
use League\Flysystem\FilesystemOperator;

test('audio rejects empty text', function (): void {
    Audio::fake();

    Audio::of('')->generate();
})->throws(InvalidArgumentException::class, 'Text content is required to generate audio.');

test('audio rejects whitespace-only text', function (): void {
    Audio::fake();

    Audio::of(" \t\n")->generate();
})->throws(InvalidArgumentException::class, 'Text content is required to generate audio.');

test('audio can be faked', function (): void {
    Audio::fake([
        base64_encode('first-audio'),
        fn(AudioPrompt $prompt): string => base64_encode('second-audio-' . $prompt->text),
        new AudioResponse(base64_encode('third-audio'), new Usage(), new Meta()),
    ]);

    $response = Audio::of('First text')->generate();
    expect($response->audio)->toEqual(base64_encode('first-audio'));

    $response = Audio::of('Second text')->generate();
    expect($response->audio)->toEqual(base64_encode('second-audio-Second text'));

    $response = Audio::of('Third text')->generate();
    expect($response->audio)->toEqual(base64_encode('third-audio'));

    // Assertion tests...
    Audio::assertGenerated(fn(AudioPrompt $prompt): bool => $prompt->text === 'First text');
    Audio::assertNotGenerated(fn(AudioPrompt $prompt): bool => $prompt->text === 'Missing text');

    Audio::assertGenerated(fn(AudioPrompt $prompt): bool => $prompt->text === 'First text');
});

test('can assert no audio was generated', function (): void {
    Audio::fake();

    Audio::assertNothingGenerated();
});

test('audio can be faked with no predefined responses', function (): void {
    Audio::fake();

    $response = Audio::of('First text')->generate();
    expect($response->audio)->toEqual(base64_encode('fake-audio-content'));

    $response = Audio::of('Second text')->generate();
    expect($response->audio)->toEqual(base64_encode('fake-audio-content'));
});

test('audio can be faked with a single closure that is invoked for every generation', function (): void {
    Audio::fake(fn(AudioPrompt $prompt): string => base64_encode('audio-for-' . $prompt->text));

    $response = Audio::of('First text')->generate();
    expect($response->audio)->toEqual(base64_encode('audio-for-First text'));

    $response = Audio::of('Second text')->generate();
    expect($response->audio)->toEqual(base64_encode('audio-for-Second text'));
});

test('audio timeout defaults to sdk fallback', function (): void {
    Audio::fake();

    Audio::of('Hello world')->generate();

    Audio::assertGenerated(fn(AudioPrompt $prompt): bool => $prompt->timeout === 30);
});

test('fake audio closure receives timeout', function (): void {
    Audio::fake(function (AudioPrompt $prompt): string {
        expect($prompt->timeout)->toBe(45);

        return base64_encode('audio-for-' . $prompt->text);
    });

    Audio::of('Hello world')->timeout(45)->generate();
});

test('audio can be generated from stringable macro', function (): void {
    Audio::fake();

    $response = Text::of('Hello world')->toAudio();

    expect($response->audio)->toEqual(base64_encode('fake-audio-content'));

    Audio::assertGenerated(fn(AudioPrompt $prompt): bool => $prompt->text === 'Hello world');
});

test('stringable audio macro passes through options', function (): void {
    Audio::fake();

    Text::of('Hello world')->toAudio(
        provider: Lab::ElevenLabs,
        voice: 'alloy',
        instructions: 'Speak slowly',
        model: 'custom-model',
        timeout: 45,
    );

    Audio::assertGenerated(fn(AudioPrompt $prompt): bool => $prompt->text === 'Hello world'
        && $prompt->provider instanceof ElevenLabsProvider
        && $prompt->voice === 'alloy'
        && $prompt->instructions === 'Speak slowly'
        && $prompt->model === 'custom-model'
        && $prompt->timeout === 45);
});

test('audio can prevent stray generations', function (): void {
    Audio::fake()->preventStrayAudio();

    Audio::of('First text')->generate();
})->throws(RuntimeException::class);

test('fake closures can throw exceptions', function (): void {
    Audio::fake(function (): void {
        throw new Exception('Something went wrong');
    });

    Audio::of('Test text')->generate();
})->throws(Exception::class);

test('audio voice and instructions are recorded', function (): void {
    Audio::fake();

    Audio::of('Hello world')->voice('alloy')->instructions('Speak slowly')->generate();

    Audio::assertGenerated(fn(AudioPrompt $prompt): bool => $prompt->text === 'Hello world'
        && $prompt->voice === 'alloy'
        && $prompt->instructions === 'Speak slowly');
});

test('audio is stored under a random name derived from its mime type', function (): void {
    $dir = sys_get_temp_dir() . '/ai-audio-' . bin2hex(random_bytes(4));

    try {
        Audio::fake([
            new AudioResponse(base64_encode('wav-bytes'), new Usage(), new Meta(), 'audio/wav'),
        ]);

        $path = Audio::of('First text')->generate()->store($dir . '/generated');

        expect($path)->toStartWith($dir . '/generated/')
            ->and($path)->toEndWith('.wav')
            ->and(file_get_contents($path))->toBe('wav-bytes');
    } finally {
        rrmdir($dir);
    }
});

test('pcm audio is stored with a pcm extension', function (): void {
    $dir = sys_get_temp_dir() . '/ai-audio-' . bin2hex(random_bytes(4));

    try {
        Audio::fake([
            new AudioResponse(base64_encode('pcm-bytes'), new Usage(), new Meta(), 'audio/pcm'),
        ]);

        $path = Audio::of('Fourth text')->generate()->store($dir . '/generated');

        expect($path)->toEndWith('.pcm')
            ->and(file_get_contents($path))->toBe('pcm-bytes');
    } finally {
        rrmdir($dir);
    }
});

test('audio can be stored under an explicit path and name', function (): void {
    $dir = sys_get_temp_dir() . '/ai-audio-' . bin2hex(random_bytes(4));

    try {
        Audio::fake([base64_encode('raw-bytes')]);

        $response = Audio::of('Hello world')->generate();

        expect($response->storeAs($dir . '/generated', 'hello.mp3'))->toBe($dir . '/generated/hello.mp3')
            ->and($response->storeAs($dir, 'hello.mp3'))->toBe($dir . '/hello.mp3')
            ->and(file_get_contents($dir . '/generated/hello.mp3'))->toBe('raw-bytes')
            ->and(file_get_contents($dir . '/hello.mp3'))->toBe('raw-bytes');
    } finally {
        rrmdir($dir);
    }
});

test('audio can be stored publicly and with an explicit name', function (): void {
    $dir = sys_get_temp_dir() . '/ai-audio-' . bin2hex(random_bytes(4));

    try {
        Audio::fake([base64_encode('raw-bytes')]);

        $response = Audio::of('Hello world')->generate();

        expect($response->storePublicly($dir . '/generated'))->toBeString();
        expect($response->storePubliclyAs($dir . '/public.mp3'))->toBe($dir . '/public.mp3')
            ->and(file_get_contents($dir . '/public.mp3'))->toBe('raw-bytes');
    } finally {
        rrmdir($dir);
    }
});

test('storing audio publicly passes public visibility to the disk', function (): void {
    Audio::fake([base64_encode('raw-bytes')]);

    $response = Audio::of('Hello world')->generate();

    $operator = Double::for(FilesystemOperator::class);
    $operator->expects('write')->with(Argument::matches('#^private/#'), 'raw-bytes', []);
    $operator->expects('write')->with(Argument::matches('#^public/#'), 'raw-bytes', ['visibility' => 'public']);
    $operator->expects('write')->with('hello.mp3', 'raw-bytes', ['visibility' => 'public']);

    Configure::write('Ai.filesystem.named.audio', $operator);

    $private = $response->store('private', 'audio');
    $public = $response->storePublicly('public', 'audio');
    $named = $response->storePubliclyAs('hello.mp3', null, 'audio');

    expect($private)->toStartWith('private/')
        ->and($public)->toStartWith('public/')
        ->and($named)->toBe('hello.mp3');
});

test('queued audio can be faked', function (): void {
    Audio::fake();

    Audio::of('First text')->queue();

    Audio::assertQueued(fn(QueuedAudioPrompt $prompt): bool => $prompt->text === 'First text');
    Audio::assertNotQueued(fn(QueuedAudioPrompt $prompt): bool => $prompt->contains('Second text'));

    Audio::assertQueued(fn(QueuedAudioPrompt $prompt): bool => $prompt->text === 'First text');

    Audio::assertNotQueued(fn(QueuedAudioPrompt $prompt): bool => $prompt->text === 'Second text');
});

test('can assert no audio was queued', function (): void {
    Audio::fake();

    Audio::assertNothingQueued();
});

test('queued audio can be faked and then callback is executed', function (): void {
    aiSyncQueue();
    Audio::fake([base64_encode('audio')]);

    $GLOBALS['audioResponse'] = null;

    Audio::of('First text')->queue()->then(function ($response): void {
        $GLOBALS['audioResponse'] = $response;
    });

    Audio::assertQueued(fn(QueuedAudioPrompt $prompt): bool => $prompt->text === 'First text');

    expect($GLOBALS['audioResponse'])->toBeInstanceOf(AudioResponse::class);
    expect($GLOBALS['audioResponse']->audio)->toEqual(base64_encode('audio'));
});

test('queued audio can be faked and then callback is not executed if queue is faked', function (): void {
    aiFakeQueue();
    Audio::fake([base64_encode('audio')]);

    $GLOBALS['audioResponse'] = null;

    Audio::of('First text')->queue()->then(function ($response): void {
        $GLOBALS['audioResponse'] = $response;
    });

    Audio::assertQueued(fn(QueuedAudioPrompt $prompt): bool => $prompt->text === 'First text');

        expect($GLOBALS['audioResponse'])->toBeNull();

        $this->assertJobPushed(GenerateAudioJob::class);
});

test('generate accepts ai provider enum', function (): void {
    Audio::fake();

    Audio::of('Enum audio')->generate(provider: Lab::OpenAI);

    Audio::assertGenerated(fn(AudioPrompt $prompt): bool => $prompt->text === 'Enum audio');
});

test('queued audio accepts ai provider enum', function (): void {
    Audio::fake();

    Audio::of('Queued enum audio')->queue(provider: Lab::ElevenLabs);

    Audio::assertQueued(fn(QueuedAudioPrompt $prompt): bool => $prompt->text === 'Queued enum audio'
        && $prompt->provider === Lab::ElevenLabs);
});

test('queued audio voice and instructions are recorded', function (): void {
    Audio::fake();

    Audio::of('Hello world')->male()->instructions('Speak quickly')->queue();

    Audio::assertQueued(fn(QueuedAudioPrompt $prompt): bool => $prompt->text === 'Hello world'
        && $prompt->voice === 'default-male'
        && $prompt->instructions === 'Speak quickly');
});

test('queued audio timeout is recorded', function (): void {
    Audio::fake();

    Audio::of('Hello world')->timeout(90)->queue();

    Audio::assertQueued(fn(QueuedAudioPrompt $prompt): bool => $prompt->timeout === 90 && $prompt->contains('Hello world'));
});
