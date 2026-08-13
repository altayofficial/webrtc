<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\MDNS;

use React\Datagram\Factory as DatagramFactory;
use React\Datagram\Socket;
use React\Dns\BadServerException;
use React\Dns\Model\Message;
use React\Dns\Protocol\BinaryDumper;
use React\Dns\Protocol\Parser;
use React\Dns\Query\ExecutorInterface;
use React\Dns\Query\Query;
use React\Dns\Query\TimeoutException;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use RuntimeException;
use function React\Async\await;

/**
 * Executor for sending DNS queries using multicast over UDP.
 *
 * This executor is suitable for Multicast DNS (mDNS) which is commonly used
 * in local network service discovery, such as WebRTC peer resolution.
 *
 * It implements ReactPHP's ExecutorInterface, providing asynchronous DNS query resolution
 * via multicast UDP sockets.
 */
class MulticastExecutor implements ExecutorInterface
{
    /**
     * Deferred used to resolve or reject the current DNS query.
     */
    private Deferred $deferred;

    /**
     * Datagram socket connection used for sending/receiving multicast messages.
     */
    private Socket $conn;

    /**
     * Constructs a new MulticastExecutor.
     *
     * @param string $nameserver Multicast DNS address (default: 224.0.0.251:5353).
     * @param ?LoopInterface $loop Optional ReactPHP event loop.
     * @param ?Parser $parser Optional DNS message parser.
     * @param ?BinaryDumper $dumper Optional DNS message dumper.
     * @param int $timeout Query timeout in seconds.
     * @param ?DatagramFactory $factory Optional datagram factory to create sockets.
     */
    public function __construct(
        private readonly string  $nameserver = "224.0.0.251:5353",
        private ?LoopInterface   $loop = null,
        private ?Parser          $parser = null,
        private ?BinaryDumper    $dumper = null,
        private readonly int     $timeout = 5,
        private ?DatagramFactory $factory = null
    )
    {
        $this->loop = $loop ?: Loop::get();
        $this->parser = $parser ?: new Parser();
        $this->dumper = $dumper ?: new BinaryDumper();
        $this->factory = $factory ?: new DatagramFactory($this->loop);
    }

    /**
     * Sends a DNS query and returns a promise that resolves with the response.
     *
     * @param Query $query The DNS query to perform.
     * @return PromiseInterface Resolves with a Message object on success or rejects on failure.
     */
    public function query(Query $query): PromiseInterface
    {
        $request = Message::createRequestForQuery($query);
        $queryData = $this->dumper->toBinary($request);
        return $this->doQuery($queryData, $query->name);
    }

    /**
     * Internal method to send the raw DNS query data and handle the response.
     *
     * @param string $queryData The binary representation of the DNS query.
     * @param string $name The domain name being queried (used in error messages).
     * @return PromiseInterface Resolves with a parsed DNS message or rejects on error/timeout.
     */
    public function doQuery($queryData, $name): PromiseInterface
    {
        $this->conn = await($this->factory->createClient('127.0.0.1:0'));

        $timer = $this->loop->addTimer($this->timeout, function () use ($name) {
            $this->conn->close();
            $this->deferred->reject(new TimeoutException(sprintf("DNS query for %s timed out", $name)));
        });

        $this->deferred = new Deferred(function ($_, $reject) use (&$timer, $name) {
            $this->conn->close();
            $this->loop->cancelTimer($timer);
            $reject(new RuntimeException(sprintf("DNS query for %s cancelled", $name)));
        });

        $this->conn->on('message', function ($data) use ($timer) {
            $response = $this->parser->parseMessage($data);

            $this->conn->close();
            $this->loop->cancelTimer($timer);

            if (!$response) {
                $this->deferred->reject(new BadServerException('Invalid response received'));
                return;
            }

            if ($response->tc) {
                $this->deferred->reject(new BadServerException('The server set the truncated bit although we issued a TCP request'));
                return;
            }

            $this->deferred->resolve($response);
        });

        $this->conn->send($queryData, $this->nameserver);

        return $this->deferred->promise();
    }

    /**
     * Returns the current deferred object, useful for testing or inspection.
     *
     * @return Deferred The active deferred used for the current DNS query.
     */
    public function getDeferred(): Deferred
    {
        return $this->deferred;
    }

    /**
     * Returns the active socket connection used for the multicast query.
     *
     * @return Socket The UDP socket used for sending/receiving messages.
     */
    public function getConn(): Socket
    {
        return $this->conn;
    }
}
