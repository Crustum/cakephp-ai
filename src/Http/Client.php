<?php
declare(strict_types=1);

namespace Crustum\Ai\Http;

use Cake\Event\EventDispatcherInterface;
use Cake\Event\EventDispatcherTrait;
use Cake\Http\Client as CakeClient;
use Cake\Http\Client\Response;
use Laminas\Diactoros\Uri;
use Psr\Http\Message\RequestInterface;

/**
 * CakePHP 4 and 5 compatible event-aware HTTP client.
 */
class Client extends CakeClient implements EventDispatcherInterface
{
    use EventDispatcherTrait;

    /**
     * @param array<string, mixed> $config Client configuration
     */
    public function __construct(array $config = [])
    {
        parent::__construct($config);

        $this->_eventClass = ClientEvent::class;
    }

    /**
     * @param \Psr\Http\Message\RequestInterface $request Request instance
     * @param array<string, mixed> $options Request options
     * @return \Cake\Http\Client\Response
     */
    public function send(RequestInterface $request, array $options = []): Response
    {
        $redirects = 0;
        if (isset($options['redirect'])) {
            $redirects = (int)$options['redirect'];
            unset($options['redirect']);
        }

        do {
            $event = $this->dispatchEvent(
                'HttpClient.beforeSend',
                ['request' => $request, 'adapterOptions' => $options, 'redirects' => $redirects],
            );
            assert($event instanceof ClientEvent);

            $request = $event->getRequest();
            $response = $event->getResult();
            $requestSent = false;
            if ($response === null) {
                $requestSent = true;
                $response = $this->_sendRequest($request, $event->getAdapterOptions());
            }

            $event = $this->dispatchEvent(
                'HttpClient.afterSend',
                [
                    'request' => $request,
                    'adapterOptions' => $options,
                    'redirects' => $redirects,
                    'requestSent' => $requestSent,
                    'response' => $response,
                ],
            );
            assert($event instanceof ClientEvent);

            $response = $event->getResult();
            assert($response instanceof Response);

            $handleRedirect = $response->isRedirect() && $redirects-- > 0;
            if ($handleRedirect) {
                $url = $request->getUri();
                $locationUrl = $this->buildUrl($response->getHeaderLine('Location'), [], [
                    'host' => $url->getHost(),
                    'port' => $url->getPort(),
                    'scheme' => $url->getScheme(),
                    'protocolRelative' => true,
                ]);
                $request = $request->withUri(new Uri($locationUrl));
                $request = $this->_cookies->addToRequest($request, []);
            }
        } while ($handleRedirect);

        return $response;
    }
}
