<?php

namespace garethp\ews\HttpPlayback;

use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use GuzzleHttp\Client as GuzzleClient;

/**
 * @method ResponseInterface get($uri, array $options = [])
 * @method ResponseInterface head($uri, array $options = [])
 * @method ResponseInterface put($uri, array $options = [])
 * @method ResponseInterface post($uri, array $options = [])
 * @method ResponseInterface patch($uri, array $options = [])
 * @method ResponseInterface delete($uri, array $options = [])
 * @method ResponseInterface getAsync($uri, array $options = [])
 * @method ResponseInterface headAsync($uri, array $options = [])
 * @method ResponseInterface putAsync($uri, array $options = [])
 * @method ResponseInterface postAsync($uri, array $options = [])
 * @method ResponseInterface patchAsync($uri, array $options = [])
 * @method ResponseInterface deleteAsync($uri, array $options = [])
 */
class Client
{
    const LIVE = 'live';

    const RECORD = 'record';

    const PLAYBACK = 'playback';

    protected $mode = 'live';

    protected $callList = [];

    protected $recordLocation;

    protected $recordFileName = 'saveState.json';

    private $shutdownRegistered = false;

    /**
     * @var GuzzleClient
     */
    private $client;

    public function __construct($options = [])
    {
        $options = array_replace_recursive(
            ['mode' => null, 'recordLocation' => null, 'recordFileName' => null],
            $options
        );

        if ($options['mode'] !== null) {
            $this->mode = $options['mode'];
        }

        if ($options['recordLocation'] !== null) {
            $this->recordLocation = $options['recordLocation'];
        }

        if ($options['recordFileName'] !== null) {
            $this->recordFileName = $options['recordFileName'];
        }

        $this->setupClient();
        $this->registerShutdown();
    }

    public function __call($method, $args)
    {
        if (count($args) < 1) {
            throw new \InvalidArgumentException('Magic request methods require a URI and optional options array');
        }

        $uri = $args[0];
        $opts = isset($args[1]) ? $args[1] : [];

        return substr($method, -5) === 'Async'
            ? $this->requestAsync(substr($method, 0, -5), $uri, $opts)
            : $this->request($method, $uri, $opts);
    }

    /**
     * @param $method
     * @param null $uri
     * @param array $options
     *
     * @return ResponseInterface
     */
    public function request($method, $uri = null, array $options = [])
    {
        return $this->doRequest($method, $uri, $options);
    }

    /**
     * @param $method
     * @param null $uri
     * @param array $options
     *
     * @return ResponseInterface
     */
    public function requestAsync($method, $uri = null, array $options = [])
    {
        return $this->doRequest($method, $uri, $options, true);
    }

    protected function requestWrapper($method, $uri = null, array $options = [], $async = false) {
        try {
            if ($async) {
                return $this->client->requestAsync($method, $uri, $options);
            } else {
                return $this->client->request($method, $uri, $options);
            }
        } catch (\Exception $e) {
            return $e;
        }
    }

    protected function doRequest($method, $uri = null, array $options = [], $async = false) {
        if ($this->mode === self::PLAYBACK) {
            $response = array_shift($this->callList);
        } else {
            $response = $this->requestWrapper($method, $uri, $options, $async);
        }

        if ($this->mode === self::RECORD) {
            $this->callList[] = $response;
        }

        if ($response instanceof \Exception) {
            throw $response;
        }

        return $response;
    }

    /**
     * Get the client for making calls
     *
     * @return Client
     */
    protected function setupClient()
    {
        if ($this->mode === self::PLAYBACK) {
            $this->callList = $this->arrayToResponses($this->getRecordings());
        }

        $this->client = new GuzzleClient();
    }

    protected function getRecordLocation()
    {
        if (!$this->recordLocation) {
            $dir = __DIR__;
            $dirPos = strrpos($dir, "src/API");
            $dir = substr($dir, 0, $dirPos);

            $this->recordLocation = $dir.'Resources/recordings/';
        }

        return $this->recordLocation;
    }

    protected function getRecordFilePath()
    {
        $path = $this->getRecordLocation() . $this->recordFileName;
        $path = str_replace("\\", DIRECTORY_SEPARATOR, $path);

        return $path;
    }

    protected function getRecordings()
    {
        $saveLocation = $this->getRecordFilePath();
        $records = json_decode(file_get_contents($saveLocation), true);

        return $records;
    }

    public function endRecord()
    {
        if ($this->mode != self::RECORD) {
            return;
        }

        $saveList = $this->responsesToArray($this->callList);
        $this->mode = self::LIVE;

        $saveLocation = $this->getRecordFilePath();
        $folder = pathinfo($saveLocation)['dirname'];
        if (!is_dir($folder)) {
            mkdir($folder, 0777, true);
        }

        file_put_contents($saveLocation, json_encode($saveList));
    }

    protected function registerShutdown()
    {
        if (!$this->shutdownRegistered) {
            register_shutdown_function(array($this, 'endRecord'));
            $this->shutdownRegistered = true;
        }
    }

    /**
     * @param $responses
     * @return array
     */
    protected function responsesToArray($responses)
    {
        $array = [];
        foreach ($responses as $response) {
            /** @var Response $response */

            if ($response instanceof \Exception) {
                $save = [
                    'error' => true,
                    'errorClass' => get_class($response),
                    'errorMessage' => $response->getMessage(),
                    'request' => [
                        'method' => $response->getRequest()->getMethod(),
                        'uri' => $response->getRequest()->getUri()->__toString(),
                        'headers' => $response->getRequest()->getHeaders(),
                        'body' => $response->getRequest()->getBody()->__toString()
                    ]
                ];
            } else {
                $save = [
                    'error' => false,
                    'statusCode' => $response->getStatusCode(),
                    'headers' => $response->getHeaders(),
                    'body' => $response->getBody()->__toString()
                ];
            }
            $array[] = $save;
        }

        return $array;
    }

    /**
     * @param $items
     * @return Response[]
     */
    protected function arrayToResponses($items)
    {
        $mockedResponses = [];
        foreach ($items as $item) {
            if (!$item['error']) {
                $mockedResponses[] = new Response($item['statusCode'], $item['headers'], $item['body']);
            } else {
                $errorClass = $item['errorClass'];
                $request = new Request(
                    $item['request']['method'],
                    $item['request']['uri'],
                    $item['request']['headers'],
                    $item['request']['body']
                );
                $mockedResponses[] = new $errorClass($item['errorMessage'], $request);
            }
        }

        return $mockedResponses;
    }
}
