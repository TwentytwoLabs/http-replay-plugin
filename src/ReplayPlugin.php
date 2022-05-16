<?php

declare(strict_types=1);

namespace Http\Client\Common\Plugin;

use Http\Client\Common\Plugin;
use Http\Message\StreamFactory;
use Http\Promise\FulfilledPromise;
use Http\Promise\Promise;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Class ReplayPlugin.
 */
class ReplayPlugin implements Plugin
{
    private CacheItemPoolInterface $pool;
    private StreamFactory $streamFactory;

    /**
     * Specify a replay bucket.
     */
    private ?string $bucket = null;

    /**
     * Record mode is disabled by default, so we can prevent dumb mistake.
     */
    private bool $recorderEnabled = false;
    private ?string $manifest;

    /**
     * @var string[]
     */
    private array $keepHeaders;

    public function __construct(CacheItemPoolInterface $pool, StreamFactory $streamFactory, ?string $manifest = null)
    {
        $this->pool = $pool;
        $this->streamFactory = $streamFactory;
        $this->manifest = $manifest;
        $this->keepHeaders = ['Host', 'Content-Type'];
    }

    public function setBucket(string $name)
    {
        $this->bucket = $name;
    }

    public function setRecorderEnabled(bool $recorderEnabled)
    {
        $this->recorderEnabled = $recorderEnabled;
    }

    public function addKeepHeaders(string $keepHeader)
    {
        $this->keepHeaders[] = $keepHeader;
    }

    public function handleRequest(RequestInterface $request, callable $next, callable $first): Promise
    {
        $cacheKey = $this->createCacheKey($request);
        $cacheItem = $this->pool->getItem($cacheKey);

        if ($cacheItem->isHit()) {
            return new FulfilledPromise($this->createResponseFromCacheItem($cacheItem));
        }

        if (false === $this->recorderEnabled) {
            throw new \RuntimeException(sprintf('Cannot replay request [%s] "%s" because record mode is disable', $request->getMethod(), $request->getUri()));
        }

        return $next($request)->then(function (ResponseInterface $response) use ($cacheItem) {
            $bodyStream = $response
                ->withoutHeader('Date')
                ->withoutHeader('ETag')
                ->withoutHeader('X-Debug-Token')
                ->withoutHeader('X-Debug-Token-Link')
                ->getBody()
            ;
            $body = $bodyStream->__toString();
            if ($bodyStream->isSeekable()) {
                $bodyStream->rewind();
            }

            $cacheItem->set([
                'response' => $response
                    ->withoutHeader('Date')
                    ->withoutHeader('ETag')
                    ->withoutHeader('X-Debug-Token')
                    ->withoutHeader('X-Debug-Token-Link'),
                'body' => $body,
            ]);
            $this->pool->save($cacheItem);

            return $response;
        });
    }

    private function createCacheKey(RequestInterface $request): string
    {
        if (null === $this->bucket) {
            throw new \LogicException('You need to specify a replay bucket');
        }

        $parts = [
            $request->getMethod(),
            $request->getUri(),
            implode(
                ' ',
                array_map(
                    function ($key, array $values) {
                        return in_array($key, \array_unique($this->keepHeaders)) ? $key.':'.implode(',', $values) : '';
                    },
                    array_keys($request->getHeaders()),
                    $request->getHeaders()
                )
            ),
            $request->getBody(),
        ];

        $key = $this->bucket.'_'.hash('sha1', trim(preg_replace('/\s\s+/', ' ', implode(' ', $parts))));

        if (null !== $this->manifest) {
            $this->buildManifest($key, $parts);
        }

        return $key;
    }

    private function buildManifest(string $key, array $parts)
    {
        $data = is_file($this->manifest) ? json_decode(file_get_contents($this->manifest), true) : [];
        $data[$key] = $parts;
        file_put_contents($this->manifest, json_encode($data));
    }

    /**
     * @return ResponseInterface
     */
    private function createResponseFromCacheItem(CacheItemInterface $cacheItem)
    {
        $data = $cacheItem->get();

        return $data['response']->withBody(
            $this->streamFactory->createStream($data['body'])
        );
    }
}
