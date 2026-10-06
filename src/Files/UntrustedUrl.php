<?php
declare(strict_types=1);

namespace Crustum\Ai\Files;

use Cake\Core\Configure;
use Closure;
use Crustum\Ai\Http\Contract\HttpResponseInterface;
use Crustum\Ai\Http\HttpClientFactory;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Fetch remote file URLs that cannot be trusted.
 *
 * Every URL and every redirect hop is validated against private and internal
 * addresses, and the connection is pinned to the validated addresses so a
 * rebinding lookup cannot redirect the connection.
 */
class UntrustedUrl
{
    protected const MAX_REDIRECTS = 5;

    protected const BLOCKED_HOSTS = ['localhost'];

    protected const BLOCKED_HOST_SUFFIXES = ['.local', '.localhost'];

    protected static ?Closure $resolver = null;

    /**
     * Fetch the URL, validating it and every redirect hop against private and internal addresses.
     *
     * @param string $url Remote file URL
     * @return \Crustum\Ai\Http\Contract\HttpResponseInterface
     * @throws \InvalidArgumentException if the URL or a redirect target is blocked, or the URL redirects too many times
     */
    public static function fetch(string $url): HttpResponseInterface
    {
        $client = HttpClientFactory::create();

        for ($hop = 0; $hop <= static::MAX_REDIRECTS; $hop++) {
            $uri = new Uri($url);

            $response = $client->get($url, [], [
                'allow_redirects' => false,
                'curl' => [CURLOPT_RESOLVE => static::validate($uri)],
            ]);

            $location = $response->getHeaderLine('Location');

            if (!self::isRedirect($response) || $location === '') {
                return $response;
            }

            $url = (string)UriResolver::resolve($uri, new Uri($location));
        }

        throw new InvalidArgumentException('The remote file URL redirected too many times.');
    }

    /**
     * Resolve hostnames with the given callback, or the system resolver when null.
     *
     * @param (\Closure(string): list<string>)|null $resolver Host resolver
     * @return void
     */
    public static function resolveUsing(?Closure $resolver): void
    {
        static::$resolver = $resolver;
    }

    /**
     * Validate the URL, returning the resolve entries that pin its host to the checked addresses.
     *
     * @param \GuzzleHttp\Psr7\Uri $uri URL to validate
     * @return list<string>
     * @throws \InvalidArgumentException if the URL does not use http or https, or its host is blocked, unresolvable, or resolves to a blocked address
     */
    protected static function validate(Uri $uri): array
    {
        if (!in_array($uri->getScheme(), ['http', 'https'], true)) {
            throw new InvalidArgumentException("The remote file URL [{$uri}] must use http or https.");
        }

        $host = rtrim(trim($uri->getHost(), '[]'), '.');

        if (in_array($host, (array)Configure::read('Ai.remote_files.allowed_hosts', []), true)) {
            return [];
        }

        if ($host === '' || in_array($host, static::BLOCKED_HOSTS, true) || self::endsWithAny($host, static::BLOCKED_HOST_SUFFIXES)) {
            throw new InvalidArgumentException("The remote file URL [{$uri}] points to a blocked host.");
        }

        $literal = filter_var($host, FILTER_VALIDATE_IP) !== false;

        $addresses = $literal ? [$host] : static::resolve($host);

        if ($addresses === []) {
            throw new InvalidArgumentException("The remote file URL [{$uri}] host could not be resolved.");
        }

        foreach ($addresses as $address) {
            if (static::isBlocked($address)) {
                throw new InvalidArgumentException("The remote file URL [{$uri}] points to a blocked address.");
            }
        }

        if ($literal) {
            return [];
        }

        $port = $uri->getPort() ?? ($uri->getScheme() === 'https' ? 443 : 80);

        return [$host . ':' . $port . ':' . implode(',', $addresses)];
    }

    /**
     * Resolve the given host to its IPv4 and IPv6 addresses.
     *
     * @param string $host Hostname
     * @return list<string>
     */
    protected static function resolve(string $host): array
    {
        if (static::$resolver instanceof Closure) {
            return (static::$resolver)($host);
        }

        set_error_handler(static fn(): bool => true);

        try {
            $records = dns_get_record($host, DNS_A | DNS_AAAA) ?: [];
        } finally {
            restore_error_handler();
        }

        return array_values(array_filter(array_map(fn(array $record): ?string => $record['ip'] ?? $record['ipv6'] ?? null, $records)));
    }

    /**
     * Determine if the given IP address is private, reserved, or otherwise internal.
     *
     * @param string $address IP address
     * @return bool
     */
    protected static function isBlocked(string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return true;
        }

        // The global range check accepts NAT64 addresses, so the IPv4 address they carry is checked instead.
        if (IpUtils::checkIp($address, '64:ff9b::/96')) {
            $embedded = inet_ntop(substr((string)inet_pton($address), 12));

            if (!is_string($embedded)) {
                return true;
            }

            $address = $embedded;
        }

        /** @phpstan-ignore identical.alwaysFalse */
        $isNotGlobal = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) === false;

        /** @phpstan-ignore booleanOr.rightAlwaysFalse */
        return IpUtils::checkIp($address, '64:ff9b:1::/48') || $isNotGlobal;
    }

    /**
     * Determine if the response is a redirect.
     *
     * @param \Crustum\Ai\Http\Contract\HttpResponseInterface $response HTTP response
     * @return bool
     */
    protected static function isRedirect(HttpResponseInterface $response): bool
    {
        return in_array($response->getStatusCode(), [301, 302, 303, 307, 308], true);
    }

    /**
     * Determine if the string ends with any of the given suffixes.
     *
     * @param string $haystack String to check
     * @param array<int, string> $suffixes Suffixes
     * @return bool
     */
    protected static function endsWithAny(string $haystack, array $suffixes): bool
    {
        foreach ($suffixes as $suffix) {
            if (str_ends_with($haystack, $suffix)) {
                return true;
            }
        }

        return false;
    }
}
