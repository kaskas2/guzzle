<?php

namespace Guzzle\Http;

use Guzzle\Common\Collection;
use Guzzle\Common\HasDispatcherTrait;
use Guzzle\Http\Adapter\TransactionInterface;
use Guzzle\Http\Event\ClientCreateRequestEvent;
use Guzzle\Http\Event\ClientEvents;
use Guzzle\Http\Event\RequestEvents;
use Guzzle\Http\Event\RequestErrorEvent;
use Guzzle\Http\Exception\RequestException;
use Guzzle\Http\Message\FutureResponseInterface;
use Guzzle\Version;
use Guzzle\Http\Adapter\AdapterInterface;
use Guzzle\Http\Adapter\FutureProxyAdapter;
use Guzzle\Http\Adapter\StreamAdapter;
use Guzzle\Http\Adapter\StreamingProxyAdapter;
use Guzzle\Http\Adapter\Curl\CurlAdapter;
use Guzzle\Http\Adapter\Transaction;
use Guzzle\Http\Event\RequestBeforeSendEvent;
use Guzzle\Http\Message\MessageFactory;
use Guzzle\Http\Message\MessageFactoryInterface;
use Guzzle\Http\Message\RequestInterface;
use Guzzle\Url\Url;

/**
 * HTTP client
 */
class Client implements ClientInterface
{
    use HasDispatcherTrait;

    /** @var MessageFactoryInterface Request factory used by the client */
    private $messageFactory;

    /** @var AdapterInterface */
    private $adapter;

    /** @var string Base URL of the client */
    private $baseUrl;

    /** @var Collection Parameter object holding configuration data */
    private $config;

    /**
     * Clients accept an array of constructor parameters.
     *
     * Here's an example of creating a client using a URI template for the
     * client's base_url and an array of default request options to apply
     * to each request:
     *
     *     $client = new Client([
     *         'base_url' => [
     *              'http://www.foo.com/{version}/',
     *              ['version' => '123']
     *          ],
     *         'defaults' => [
     *             'timeout'         => 10,
     *             'allow_redirects' => false,
     *             'proxy'           => '192.168.16.1:10'
     *         ]
     *     ]);
     *
     * @param array $config Client configuration settings
     *                      - base_url: Base URL of the client that is merged into relative URLs. Can be a string or
     *                                  an array that contains a URI template followed by an associative array of
     *                                  expansion variables to inject into the URI template.
     *                      - adapter: Adapter used to transfer requests
     *                      - message_factory: Factory used to create request and response object
     *                      - defaults: Default request options to apply to each request
     */
    public function __construct(array $config = [])
    {
        // Add default request options
        if (!isset($config['defaults'])) {
            $config['defaults'] = $this->getDefaultOptions();
        } else {
            $config['defaults'] = array_replace($this->getDefaultOptions(), $config['defaults']);
        }

        $this->messageFactory = isset($config['message_factory']) ? $config['message_factory'] : new MessageFactory();
        $this->adapter = isset($config['adapter']) ? $config['adapter'] : $this->getDefaultAdapter();
        $this->baseUrl = isset($config['base_url']) ? $this->buildUrl($config['base_url']) : '';

        // Add the default user-agent header
        if (!isset($config['defaults']['headers'])) {
            $config['defaults']['headers'] = ['User-Agent' => static::getDefaultUserAgent()];
        } elseif (!isset(array_change_key_case($config['defaults']['headers'])['user-agent'])) {
            // Add the User-Agent header if one was not already set
            $config['defaults']['headers']['User-Agent'] = static::getDefaultUserAgent();
        }

        $this->config = new Collection($config);
    }

    /**
     * Get the default User-Agent string to use with Guzzle
     *
     * @return string
     */
    public static function getDefaultUserAgent()
    {
        return 'Guzzle/' . Version::VERSION . ' curl/' . curl_version()['version'] . ' PHP/' . PHP_VERSION;
    }

    public function getConfig($key)
    {
        return $this->config->getPath($key);
    }

    /**
     * Retrieve a default request option from the client
     *
     * @param string $keyOrPath request.options key (e.g. allow_redirects) or path to a nested key (e.g. headers/foo)
     *
     * @return mixed|null
     */
    public function getDefaultOption($keyOrPath)
    {
        return $this->config->getPath("defaults/{$keyOrPath}");
    }

    public function createRequest($method, $url = null, array $headers = [], $body = null, array $options = [])
    {
        // Merge in default options
        if ($default = $this->config->get('defaults')) {
            $options = array_replace_recursive($default, $options);
        }

        // Use a clone of the client's event dispatcher
        $options['constructor_options'] = ['event_dispatcher' => clone $this->getEventDispatcher()];

        $request = $this->messageFactory->createRequest(
            $method,
            $url ? (string) $this->buildUrl($url) : (string) $this->getBaseUrl(),
            $headers,
            $body,
            $options
        );

        $this->getEventDispatcher()->dispatch(
            ClientEvents::CREATE_REQUEST,
            new ClientCreateRequestEvent($this, $request, $options)
        );

        return $request;
    }

    public function getBaseUrl()
    {
        return $this->baseUrl;
    }

    public function get($url = null, array $headers = [], $options = [])
    {
        return $this->send($this->createRequest('GET', $url, $headers, null, $options));
    }

    public function head($url = null, array $headers = [], array $options = [])
    {
        return $this->send($this->createRequest('HEAD', $url, $headers, null, $options));
    }

    public function delete($url = null, array $headers = [], array $options = [])
    {
        return $this->send($this->createRequest('DELETE', $url, $headers, null, $options));
    }

    public function put($url = null, array $headers = [], $body = null, array $options = [])
    {
        return $this->send($this->createRequest('PUT', $url, $headers, $body, $options));
    }

    public function patch($url = null, array $headers = [], $body = null, array $options = [])
    {
        return $this->send($this->createRequest('PATCH', $url, $headers, $body, $options));
    }

    public function post($url = null, array $headers = [], $body = null, array $options = [])
    {
        return $this->send($this->createRequest('POST', $url, $headers, $body, $options));
    }

    public function options($url = null, array $headers = [], array $options = [])
    {
        return $this->send($this->createRequest('OPTIONS', $url, $headers, null, $options));
    }

    public function send(RequestInterface $request)
    {
        $transaction = new Transaction($this, $request, $this->messageFactory);
        $send = false;

        try {
            $send = !$request->getEventDispatcher()->dispatch(
                RequestEvents::BEFORE_SEND,
                new RequestBeforeSendEvent($transaction)
            )->isPropagationStopped();
        } catch (\Exception $e) {
            $this->handleSendError($e, $request, $transaction);
        }

        if ($send) {
            $this->adapter->send($transaction);
        }

        if (!($response = $transaction->getResponse())) {
            throw new \RuntimeException('No response was associated with the transaction');
        } elseif (!($response instanceof FutureResponseInterface) && !$response->getEffectiveUrl()) {
            $response->setEffectiveUrl($request->getUrl());
        }

        return $response;
    }

    /**
     * Handle a client error
     */
    private function handleSendError(\Exception $e, RequestInterface $request, TransactionInterface $transaction)
    {
        // Convert non-request exception to a wrapped exception
        if (!($e instanceof RequestException)) {
            $e = new RequestException($e->getMessage(), $request, null, $e);
        }

        // Dispatch an event and allow interception
        if (!$transaction->getRequest()->getEventDispatcher()->dispatch(
            RequestEvents::ERROR,
            new RequestErrorEvent($transaction, $e)
        )->isPropagationStopped()) {
            throw $e;
        }
    }

    /**
     * Get an array of default options to apply to the client
     *
     * @return array
     */
    protected function getDefaultOptions()
    {
        return [
            'allow_redirects' => true,
            'exceptions'      => true,
            'verify'          => __DIR__ . '/Resources/cacert.pem'
        ];
    }

    /**
     * Expand a URI template and inherit from the base URL if it's relative
     *
     * @param string|array $url URL or URI template to expand
     *
     * @return string
     */
    private function buildUrl($url)
    {
        if (!is_array($url)) {
            // Use absolute URLs as is
            return substr($url, 0, 4) === 'http'
                ? (string) $url
                : (string) Url::fromString($this->getBaseUrl())->combine($url);
        }

        list($url, $templateVars) = $url;
        if (substr($url, 0, 4) === 'http') {
            return \Guzzle\uriTemplate($url, $templateVars);
        }

        return (string) Url::fromString($this->getBaseUrl())
            ->combine(\Guzzle\uriTemplate($url, $templateVars));
    }

    /**
     * Get a default adapter to use based on the environment
     *
     * @return AdapterInterface
     * @throws \RuntimeException
     */
    private function getDefaultAdapter()
    {
        if (extension_loaded('curl')) {
            if (!ini_get('allow_url_fopen')) {
                return new FutureProxyAdapter(new CurlAdapter($this->messageFactory));
            } else {
                return new StreamingProxyAdapter(
                    new FutureProxyAdapter(new CurlAdapter($this->messageFactory)),
                    new FutureProxyAdapter(new StreamAdapter($this->messageFactory))
                );
            }
        } elseif (ini_get('allow_url_fopen')) {
            return new FutureProxyAdapter(new StreamAdapter($this->messageFactory));
        } else {
            throw new \RuntimeException('The curl extension must be installed or you must set allow_url_fopen to true');
        }
    }
}
