<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway\OpenRouter;

use Crustum\Ai\Contracts\Gateway\ClassificationGateway;
use Crustum\Ai\Contracts\Providers\ClassificationProvider;
use Crustum\Ai\Contracts\Providers\Provider;
use Crustum\Ai\Enums\Lab;
use Crustum\Ai\Files\File;
use Crustum\Ai\Gateway\OpenRouter\Trait\CreatesOpenRouterClientTrait;
use Crustum\Ai\Gateway\Trait\AnswersQuestionsTrait;
use Crustum\Ai\Gateway\Trait\HandlesFailoverErrorsTrait;
use Crustum\Ai\Gateway\Trait\ResolvesInlineImagesTrait;
use Crustum\Ai\Responses\ClassificationResponse;
use Laminas\Diactoros\UploadedFile;

/**
 * OpenRouter Decisions API classification gateway.
 */
class OpenRouterClassificationGateway implements ClassificationGateway
{
    use AnswersQuestionsTrait {
        classify as answerQuestions;
    }
    use CreatesOpenRouterClientTrait {
        baseUrl as openRouterBaseUrl;
    }
    use HandlesFailoverErrorsTrait;
    use ResolvesInlineImagesTrait;

    /**
     * Answer the given questions about the state, sending image attachments as image parts of the state.
     *
     * @param \Crustum\Ai\Contracts\Providers\ClassificationProvider $provider Classification provider
     * @param string $model Model name
     * @param array<string, mixed>|string $state State to classify
     * @param array<string, \Crustum\Ai\Contracts\Question> $questions Questions to answer
     * @param int $timeout Timeout in seconds
     * @param array<string, mixed> $providerOptions Provider-specific options
     * @param array<int|string, \Crustum\Ai\Files\File|\Laminas\Diactoros\UploadedFile> $attachments Attachments to classify alongside the state
     * @return \Crustum\Ai\Responses\ClassificationResponse
     */
    public function classify(
        ClassificationProvider $provider,
        string $model,
        string|array $state,
        array $questions,
        int $timeout = 30,
        array $providerOptions = [],
        array $attachments = [],
    ): ClassificationResponse {
        return $this->answerQuestions(
            $provider,
            $model,
            $attachments === [] ? $state : $this->stateWithImages($state, $attachments),
            $questions,
            $timeout,
            $providerOptions,
        );
    }

    /**
     * Append the images to the state as data URL image parts, each named image preceded by its name.
     *
     * @param array<array-key, mixed>|string $state State to classify
     * @param array<int|string, \Crustum\Ai\Files\File|\Laminas\Diactoros\UploadedFile> $attachments Attachments to classify alongside the state
     * @return array<int|string, mixed>
     */
    protected function stateWithImages(string|array $state, array $attachments): array
    {
        $state = is_array($state) && array_is_list($state) ? $state : [$state];

        foreach ($attachments as $name => $attachment) {
            if (is_string($name)) {
                $state[] = "{$name}:";
            }

            $state[] = $this->mapImage($attachment);
        }

        return $state;
    }

    /**
     * Map an image to a data URL image part.
     *
     * @param \Crustum\Ai\Files\File|\Laminas\Diactoros\UploadedFile $attachment Image attachment
     * @return array<string, mixed>
     * @throws \InvalidArgumentException if the attachment is not a JPEG, PNG, or WebP image with inline content.
     */
    protected function mapImage(File|UploadedFile $attachment): array
    {
        [$image, $content, $mime] = $this->resolveInlineImage($attachment, 'OpenRouter decisions', [
            'image/jpeg' => 'JPEG',
            'image/png' => 'PNG',
            'image/webp' => 'WebP',
        ]);

        return array_merge($image->providerOptions(Lab::OpenRouter), [
            'type' => 'image_url',
            'image_url' => ['url' => 'data:' . $mime . ';base64,' . base64_encode($content)],
        ]);
    }

    /**
     * Get the path of the endpoint that answers questions.
     *
     * @return string
     */
    protected function classificationEndpoint(): string
    {
        return '/alpha/decisions';
    }

    /**
     * Get the base URL for the Decisions API, which is served outside of the versioned API.
     *
     * @param \Crustum\Ai\Contracts\Providers\Provider $provider Provider instance
     * @return string
     */
    protected function baseUrl(Provider $provider): string
    {
        $baseUrl = $this->openRouterBaseUrl($provider);

        return str_ends_with($baseUrl, '/v1') ? substr($baseUrl, 0, -strlen('/v1')) : $baseUrl;
    }
}
