<?php
declare(strict_types=1);

namespace Crustum\Ai\Http;

use Cake\Event\Event;
use Cake\Http\Client as CakeClient;
use Cake\Http\Client\Response;
use InvalidArgumentException;
use Psr\Http\Message\RequestInterface;

/**
 * HTTP client lifecycle event.
 */
class ClientEvent extends Event
{
    /**
     * @param string $name Event name
     * @param \Cake\Http\Client $subject HTTP client
     * @param array<string, mixed> $data Event data
     */
    public function __construct(string $name, CakeClient $subject, array $data = [])
    {
        if (isset($data['response'])) {
            $this->result = $data['response'];
            unset($data['response']);
        }

        parent::__construct($name, $subject, $data);
    }

    /**
     * @return \Cake\Http\Client\Response|null
     */
    public function getResult(): ?Response
    {
        return $this->result;
    }

    /**
     * @param mixed $value Event result
     * @return $this
     */
    public function setResult(mixed $value = null)
    {
        if ($value !== null && !$value instanceof Response) {
            throw new InvalidArgumentException(
                'The result for HTTP client events must be a `Cake\Http\Client\Response` instance.',
            );
        }

        return parent::setResult($value);
    }

    /**
     * @return \Psr\Http\Message\RequestInterface
     */
    public function getRequest(): RequestInterface
    {
        return $this->_data['request'];
    }

    /**
     * @return array<string, mixed>
     */
    public function getAdapterOptions(): array
    {
        return $this->_data['adapterOptions'];
    }
}
