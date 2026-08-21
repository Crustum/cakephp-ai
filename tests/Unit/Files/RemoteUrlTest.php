<?php
declare(strict_types=1);

use Crustum\Ai\Files\RemoteAudio;
use Crustum\Ai\Files\RemoteDocument;
use Crustum\Ai\Files\RemoteImage;
use Crustum\Ai\Files\RemoteVideo;

test('remote document rejects empty url', function (): void {
    new RemoteDocument('');
})->throws(InvalidArgumentException::class, 'Remote document URL cannot be empty.');

test('remote document rejects whitespace-only url', function (): void {
    new RemoteDocument("  \t\n");
})->throws(InvalidArgumentException::class, 'Remote document URL cannot be empty.');

test('remote image rejects empty url', function (): void {
    new RemoteImage('');
})->throws(InvalidArgumentException::class, 'Remote image URL cannot be empty.');

test('remote image rejects whitespace-only url', function (): void {
    new RemoteImage("  \t\n");
})->throws(InvalidArgumentException::class, 'Remote image URL cannot be empty.');

test('remote audio rejects empty url', function (): void {
    new RemoteAudio('');
})->throws(InvalidArgumentException::class, 'Remote audio URL cannot be empty.');

test('remote audio rejects whitespace-only url', function (): void {
    new RemoteAudio("  \t\n");
})->throws(InvalidArgumentException::class, 'Remote audio URL cannot be empty.');

test('remote video rejects empty url', function (): void {
    new RemoteVideo('');
})->throws(InvalidArgumentException::class, 'Remote video URL cannot be empty.');

test('remote video rejects whitespace-only url', function (): void {
    new RemoteVideo("  \t\n");
})->throws(InvalidArgumentException::class, 'Remote video URL cannot be empty.');
