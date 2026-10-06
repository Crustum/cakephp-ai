<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway\Bedrock\Trait;

use Aws\BedrockRuntime\BedrockRuntimeClient;
use Aws\Credentials\AssumeRoleCredentialProvider;
use Aws\Credentials\CredentialProvider;
use Aws\Middleware;
use Aws\Sts\StsClient;
use Closure;
use Crustum\Ai\Contracts\Providers\Provider;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

/**
 * Creates Bedrock Runtime and STS clients for AWS Bedrock.
 */
trait CreatesBedrockClientTrait
{
    /**
     * The memoized assume role credential providers, keyed by the resolved credential configuration.
     *
     * @var array<string, \Closure>
     */
    protected array $assumeRoleProviders = [];

    /**
     * Create a new Bedrock client instance.
     *
     * @param \Crustum\Ai\Contracts\Providers\Provider $provider Provider instance
     * @param int|null $timeout Request timeout in seconds
     * @return \Aws\BedrockRuntime\BedrockRuntimeClient
     */
    protected function createBedrockClient(Provider $provider, ?int $timeout = null): BedrockRuntimeClient
    {
        if (!class_exists(BedrockRuntimeClient::class)) {
            throw new RuntimeException('The Bedrock provider requires the AWS SDK. Please install it via: composer require aws/aws-sdk-php');
        }

        $credentials = $provider->providerCredentials();

        $config = $provider->additionalConfiguration();

        $clientConfig = [
            'region' => $this->bedrockRegion($config),
            'version' => '2023-09-30',
            ...$this->resolveAuthConfig($credentials, $config, $timeout),
        ];

        if ($timeout) {
            $clientConfig['http'] = ['timeout' => $timeout];
        }

        $client = new BedrockRuntimeClient($clientConfig);

        $headers = $config['headers'] ?? [];

        if ($headers !== []) {
            $client->getHandlerList()->appendBuild(Middleware::mapRequest(
                function (RequestInterface $request) use ($headers): RequestInterface {
                    foreach ($headers as $name => $value) {
                        $request = $request->withHeader($name, $value);
                    }

                    return $request;
                },
            ), 'crustum-ai.headers');
        }

        return $client;
    }

    /**
     * Resolve the configured region, falling back to the default.
     *
     * @param array<string, mixed> $config Configuration
     * @return string
     */
    protected function bedrockRegion(array $config): string
    {
        return $config['region'] ?? 'us-east-1';
    }

    /**
     * Resolve the authentication configuration for the Bedrock client.
     *
     * @param array<string, mixed> $credentials Credentials
     * @param array<string, mixed> $config Configuration
     * @param int|null $timeout Request timeout in seconds
     * @return array<string, mixed>
     */
    protected function resolveAuthConfig(array $credentials, array $config, ?int $timeout = null): array
    {
        if (!empty($credentials['key'])) {
            return [
                'token' => ['token' => $credentials['key']],
                'auth_scheme_preference' => ['smithy.api#httpBearerAuth'],
            ];
        }

        if (!empty($config['assume_role']['arn'])) {
            return ['credentials' => $this->assumeRoleCredentialProvider($credentials, $config, $timeout)];
        }

        return $this->resolveSourceAuthConfig($credentials, $config);
    }

    /**
     * Resolve the authentication configuration for static, or automatically discovered, credentials.
     *
     * @param array<string, mixed> $credentials Credentials
     * @param array<string, mixed> $config Configuration
     * @return array<string, mixed>
     */
    protected function resolveSourceAuthConfig(array $credentials, array $config): array
    {
        if (!empty($credentials['access_key_id']) && !empty($credentials['secret_access_key'])) {
            $awsCredentials = [
                'key' => $credentials['access_key_id'],
                'secret' => $credentials['secret_access_key'],
            ];

            if (!empty($credentials['session_token'])) {
                $awsCredentials['token'] = $credentials['session_token'];
            }

            return ['credentials' => $awsCredentials];
        }

        if (!($config['use_default_credential_provider'] ?? true)) {
            return ['credentials' => false];
        }

        return [];
    }

    /**
     * Resolve a memoized credential provider that assumes the configured role.
     *
     * @param array<string, mixed> $credentials Credentials
     * @param array<string, mixed> $config Configuration
     * @param int|null $timeout Request timeout in seconds
     * @return \Closure
     */
    protected function assumeRoleCredentialProvider(array $credentials, array $config, ?int $timeout = null): Closure
    {
        $key = hash('xxh128', serialize([$credentials, $config]));

        return $this->assumeRoleProviders[$key] ??= CredentialProvider::memoize(
            new AssumeRoleCredentialProvider([
                'client' => $this->createStsClient($credentials, $config, $timeout),
                'assume_role_params' => $this->assumeRoleParameters($config['assume_role']),
            ]),
        );
    }

    /**
     * Create the STS client used to assume the configured role.
     *
     * @param array<string, mixed> $credentials Credentials
     * @param array<string, mixed> $config Configuration
     * @param int|null $timeout Request timeout in seconds
     * @return \Aws\Sts\StsClient
     */
    protected function createStsClient(array $credentials, array $config, ?int $timeout = null): StsClient
    {
        $clientConfig = [
            'region' => $this->bedrockRegion($config),
            'version' => 'latest',
            ...$this->resolveSourceAuthConfig($credentials, $config),
        ];

        if ($timeout) {
            $clientConfig['http'] = ['timeout' => $timeout];
        }

        return new StsClient($clientConfig);
    }

    /**
     * Build the parameters for the STS assume role request.
     *
     * @param array<string, mixed> $assumeRole Assume role configuration
     * @return array<string, mixed>
     */
    protected function assumeRoleParameters(array $assumeRole): array
    {
        return array_filter([
            'RoleArn' => $assumeRole['arn'],
            'RoleSessionName' => $assumeRole['session_name'] ?? null ?: 'crustum-ai-bedrock',
            'DurationSeconds' => (int)($assumeRole['duration_seconds'] ?? 0),
            'ExternalId' => $assumeRole['external_id'] ?? null,
        ]);
    }
}
