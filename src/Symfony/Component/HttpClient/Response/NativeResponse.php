<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\HttpClient\Response;

use Symfony\Component\HttpClient\Chunk\FirstChunk;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * @author Nicolas Grekas <p@tchwork.com>
 *
 * @internal
 */
final class NativeResponse implements ResponseInterface
{
    use ResponseTrait;

    private $context;
    private $url;
    private $resolveRedirect;
    private $onProgress;
    private $remaining;
    private $buffer;
    private $inflate;

    /**
     * @internal
     */
    public function __construct(\stdClass $multi, $context, string $url, $options, bool $gzipEnabled, array &$info, callable $resolveRedirect, ?callable $onProgress)
    {
        $this->multi = $multi;
        $this->id = (int) $context;
        $this->context = $context;
        $this->url = $url;
        $this->timeout = $options['timeout'];
        $this->info = &$info;
        $this->resolveRedirect = $resolveRedirect;
        $this->onProgress = $onProgress;
        $this->content = $options['buffer'] ? fopen('php://temp', 'w+') : null;

        // Temporary resources to dechunk/inflate the response stream
        $this->buffer = fopen('php://temp', 'w+');
        $this->inflate = $gzipEnabled ? inflate_init(ZLIB_ENCODING_GZIP) : null;

        $info['user_data'] = $options['user_data'];
        ++$multi->responseCount;

        $this->initializer = static function (self $response) {
            if (null !== $response->info['error']) {
                throw new TransportException($response->info['error']);
            }

            if (null === $response->remaining) {
                self::stream([$response])->current();
            }
        };
    }

    /**
     * {@inheritdoc}
     */
    public function getInfo(string $type = null)
    {
        if (!$info = $this->finalInfo) {
            self::perform($this->multi);
            $info = $this->info;
            $info['url'] = implode('', $info['url']);
            unset($info['fopen_time'], $info['size_body']);

            if (null === $this->buffer) {
                $this->finalInfo = $info;
            }
        }

        return null !== $type ? $info[$type] ?? null : $info;
    }

    public function __destruct()
    {
        try {
            $this->doDestruct();
        } finally {
            $this->close();

            // Clear the DNS cache when all requests completed
            if (0 >= --$this->multi->responseCount) {
                $this->multi->responseCount = 0;
                $this->multi->dnsCache = [];
            }
        }
    }

    private function open(): void
    {
        set_error_handler(function ($type, $msg) { throw new TransportException($msg); });

        try {
            $this->info['start_time'] = microtime(true);
            $url = $this->url;

            do {
                // Send request and follow redirects when needed
                $this->info['fopen_time'] = microtime(true);
                $this->handle = $h = fopen($url, 'r', false, $this->context);
                $this->addRawHeaders($http_response_header);
                $url = ($this->resolveRedirect)($this->multi, $this->statusCode, $this->headers['location'][0] ?? null, $this->context);
            } while (null !== $url);
        } catch (\Throwable $e) {
            $this->statusCode = 0;
            $this->close();
            $this->multi->handlesActivity[$this->id][] = null;
            $this->multi->handlesActivity[$this->id][] = $e;

            return;
        } finally {
            $this->info['starttransfer_time'] = $this->info['total_time'] = microtime(true) - $this->info['start_time'];
            restore_error_handler();
        }

        stream_set_blocking($h, false);
        $context = stream_context_get_options($this->context);
        $this->context = $this->resolveRedirect = null;

        if (isset($context['ssl']['peer_certificate_chain'])) {
            $this->info['peer_certificate_chain'] = $context['ssl']['peer_certificate_chain'];
        }

        // Create dechunk and inflate buffers
        if (isset($this->headers['content-length'])) {
            $this->remaining = (int) $this->headers['content-length'][0];
        } elseif ('chunked' === ($this->headers['transfer-encoding'][0] ?? null)) {
            stream_filter_append($this->buffer, 'dechunk', STREAM_FILTER_WRITE);
            $this->remaining = -1;
        } else {
            $this->remaining = -2;
        }

        if ($this->inflate && 'gzip' !== ($this->headers['content-encoding'][0] ?? null)) {
            $this->inflate = null;
        }

        $this->multi->openHandles[$this->id] = [$h, $this->buffer, $this->inflate, &$this->content, $this->onProgress, &$this->remaining, &$this->info];
        $this->multi->handlesActivity[$this->id] = [new FirstChunk()];
    }

    /**
     * {@inheritdoc}
     */
    private function close(): void
    {
        unset($this->multi->openHandles[$this->id], $this->multi->handlesActivity[$this->id]);
        $this->handle = $this->buffer = $this->inflate = $this->onProgress = null;
    }

    /**
     * {@inheritdoc}
     */
    private static function schedule(self $response, array &$runningResponses): void
    {
        if (null === $response->buffer) {
            return;
        }

        if (!isset($runningResponses[$i = $response->multi->id])) {
            $runningResponses[$i] = [$response->multi, []];
        }

        if (null === $response->remaining) {
            $response->multi->pendingResponses[] = $response;
        } else {
            $runningResponses[$i][1][$response->id] = $response;
        }
    }

    /**
     * {@inheritdoc}
     */
    private static function perform(\stdClass $multi, array &$responses = null): void
    {
        // List of native handles for stream_select()
        if (null !== $responses) {
            $multi->handles = [];
        }

        foreach ($multi->openHandles as $i => [$h, $buffer, $inflate, $content, $onProgress]) {
            $hasActivity = false;
            $remaining = &$multi->openHandles[$i][5];
            $info = &$multi->openHandles[$i][6];
            $e = null;

            // Read incoming buffer and write it to the dechunk one
            try {
                while ($remaining && '' !== $data = (string) fread($h, 0 > $remaining ? 16372 : $remaining)) {
                    fwrite($buffer, $data);
                    $hasActivity = true;
                    $multi->sleep = false;

                    if (-1 !== $remaining) {
                        $remaining -= \strlen($data);
                    }
                }
            } catch (\Throwable $e) {
                $hasActivity = $onProgress = false;
            }

            if (!$hasActivity) {
                if ($onProgress) {
                    try {
                        // Notify the progress callback so that it can e.g. cancel
                        // the request if the stream is inactive for too long
                        $onProgress();
                    } catch (\Throwable $e) {
                        // no-op
                    }
                }
            } elseif ('' !== $data = stream_get_contents($buffer, -1, 0)) {
                rewind($buffer);
                ftruncate($buffer, 0);

                if (null !== $inflate && false === $data = @inflate_add($inflate, $data)) {
                    $e = new TransportException('Error while processing content unencoding.');
                }

                if ('' !== $data && null === $e) {
                    $multi->handlesActivity[$i][] = $data;

                    if (null !== $content && \strlen($data) !== fwrite($content, $data)) {
                        $e = new TransportException(sprintf('Failed writing %d bytes to the response buffer.', \strlen($data)));
                    }
                }
            }

            if (null !== $e || !$remaining || feof($h)) {
                // Stream completed
                $info['total_time'] = microtime(true) - $info['start_time'];

                if ($onProgress) {
                    try {
                        $onProgress(-1);
                    } catch (\Throwable $e) {
                        // no-op
                    }
                }

                if (null === $e) {
                    if (0 < $remaining) {
                        $e = new TransportException(sprintf('Transfer closed with %s bytes remaining to read.', $remaining));
                    } elseif (-1 === $remaining && fwrite($buffer, '-') && '' !== stream_get_contents($buffer, -1, 0)) {
                        $e = new TransportException('Transfer closed with outstanding data remaining from chunked response.');
                    }
                }

                $multi->handlesActivity[$i][] = null;
                $multi->handlesActivity[$i][] = $e;
                unset($multi->openHandles[$i]);
                $multi->sleep = false;
            } elseif (null !== $responses) {
                $multi->handles[] = $h;
            }
        }

        if (null === $responses) {
            return;
        }

        if ($multi->pendingResponses && \count($multi->handles) < $multi->maxHostConnections) {
            // Open the next pending request - this is a blocking operation so we do only one of them
            $response = array_shift($multi->pendingResponses);
            $response->open();
            $responses[$response->id] = $response;
            $multi->sleep = false;
            self::perform($response->multi);

            if (null !== $response->handle) {
                $multi->handles[] = $response->handle;
            }
        }

        if ($multi->pendingResponses) {
            // Create empty activity list to tell ResponseTrait::stream() we still have pending requests
            $response = $multi->pendingResponses[0];
            $responses[$response->id] = $response;
            $multi->handlesActivity[$response->id] = [];
        }
    }

    /**
     * {@inheritdoc}
     */
    private static function select(\stdClass $multi, float $timeout): int
    {
        $_ = [];

        return (!$multi->sleep = !$multi->sleep) ? -1 : stream_select($multi->handles, $_, $_, (int) $timeout, (int) (1E6 * ($timeout - (int) $timeout)));
    }
}
