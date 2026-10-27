<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\STUN;

use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use Throwable;
use Webrtc\Exception\InvalidArgumentException;
use Webrtc\Exception\RuntimeException;
use function count;
use function ord;
use function str_contains;
use function strlen;
use function strpos;
use function substr;
use function unpack;

/**
 * Lets every ICE agent share one UDP port per local address, so a server only has to open a
 * single port in its firewall instead of one per player.
 *
 * A datagram finds its agent like this: a binding request by the local ufrag in its USERNAME, a
 * STUN response by the transaction id of the request that went out, and anything else (DTLS,
 * SCTP, indications) by the remote address a binding request last came from.
 *
 * Whoever creates it owns it and calls close() once no agent needs it anymore.
 */
class UdpMux
{
    private const HEADER_LENGTH = 20;
    private const MAGIC_COOKIE = "\x21\x12\xA4\x42";
    private const ATTRIBUTE_USERNAME = 0x0006;

    /**
     * Requests that never got an answer would otherwise pile up here forever. A real agent has a
     * handful in flight, so this is only ever reached by something broken.
     */
    private const MAX_TRANSACTIONS = 65536;

    /** @var array<string, DrainingSocket> host => the socket every agent on that host shares */
    private array $sockets = [];

    /** @var array<string, array<int, MuxedSocket>> host => virtual sockets, by object id */
    private array $muxed = [];

    /** @var array<string, array<string, MuxedSocket>> host => remote address => owner */
    private array $addresses = [];

    /** @var array<string, MuxedSocket> transaction id => the socket that sent the request */
    private array $transactions = [];

    private LoopInterface $loop;

    public function __construct(private readonly int $port, ?LoopInterface $loop = null)
    {
        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException("Invalid port $port");
        }
        $this->loop = $loop ?? Loop::get();
    }

    public function getPort(): int
    {
        return $this->port;
    }

    /**
     * Opens a virtual socket on the shared port of $host for one agent.
     *
     * @param \Closure(): string $localUfrag read on every unrouted binding request, since an agent
     *                                       may change its ufrag after the socket is opened
     * @throws RuntimeException when the shared port cannot be bound
     */
    public function open(string $host, \Closure $localUfrag): MuxedSocket
    {
        $socket = $this->listen($host);
        $muxed = new MuxedSocket($this, $host, $socket->getLocalAddress(), $localUfrag);
        $this->muxed[$host][spl_object_id($muxed)] = $muxed;

        return $muxed;
    }

    /**
     * Binds the shared port on $host now rather than on the first agent that needs it, so a port
     * that is taken shows up at startup and not as a join that never finds a path.
     *
     * @throws RuntimeException
     */
    public function listen(string $host): DrainingSocket
    {
        return $this->sockets[$host] ?? $this->bind($host);
    }

    /**
     * @throws RuntimeException
     */
    private function bind(string $host): DrainingSocket
    {
        $address = str_contains($host, ":") ? "udp://[$host]:$this->port" : "udp://$host:$this->port";
        $stream = @stream_socket_server($address, $errno, $errstr, STREAM_SERVER_BIND);
        if ($stream === false) {
            throw new RuntimeException("Could not bind the shared ICE port $address - $errstr", $errno);
        }

        try {
            $socket = new DrainingSocket($this->loop, $stream);
        } catch (Throwable $e) {
            @fclose($stream);
            throw new RuntimeException("Could not bind the shared ICE port $address - " . $e->getMessage(), $e->getCode(), $e);
        }

        $socket->on("message", function (string $data, ?string $peer) use ($host): void {
            if ($peer !== null) {
                $this->route($host, $data, $peer);
            }
        });
        $socket->on("error", function (Throwable $e) use ($host): void {
            foreach ($this->muxed[$host] ?? [] as $muxed) {
                $muxed->emit("error", [$e, $muxed]);
            }
        });

        $this->sockets[$host] = $socket;
        $this->muxed[$host] = [];
        $this->addresses[$host] = [];

        return $socket;
    }

    private function route(string $host, string $data, string $peer): void
    {
        $owner = null;
        if (self::isStun($data)) {
            $class = ((ord($data[0]) & 0x01) << 1) | ((ord($data[1]) & 0x10) >> 4);
            if ($class >= 2) {
                // a response belongs to whoever asked, whatever address it comes back from - a STUN
                // server answers every agent from the same one
                $id = substr($data, 8, 12);
                $owner = $this->transactions[$id] ?? null;
                unset($this->transactions[$id]);
            } elseif ($class === 0) {
                $owner = $this->byUfrag($host, $data);
                if ($owner !== null) {
                    // yeah, latest request wins: a client reconnecting from the same address is
                    // the new agent's peer from here on, not the old one's
                    $this->addresses[$host][$peer] = $owner;
                }
            }
        }

        $owner ??= $this->addresses[$host][$peer] ?? null;
        $owner?->receive($data, $peer);
    }

    /**
     * The agent a binding request is for, read from the "local:remote" ufrag pair it is signed with.
     */
    private function byUfrag(string $host, string $data): ?MuxedSocket
    {
        $username = self::username($data);
        if ($username === null) {
            return null;
        }
        $colon = strpos($username, ":");
        $ufrag = $colon === false ? $username : substr($username, 0, $colon);

        foreach ($this->muxed[$host] ?? [] as $muxed) {
            if ($muxed->localUfrag() === $ufrag) {
                return $muxed;
            }
        }

        return null;
    }

    private static function username(string $data): ?string
    {
        $length = strlen($data);
        $offset = self::HEADER_LENGTH;
        while ($offset + 4 <= $length) {
            ['type' => $type, 'length' => $attributeLength] = unpack("ntype/nlength", $data, $offset);
            $offset += 4;
            if ($offset + $attributeLength > $length) {
                return null;
            }
            if ($type === self::ATTRIBUTE_USERNAME) {
                return substr($data, $offset, $attributeLength);
            }
            // attribute values are padded to four bytes
            $offset += ($attributeLength + 3) & ~3;
        }

        return null;
    }

    private static function isStun(string $data): bool
    {
        return strlen($data) >= self::HEADER_LENGTH
            && (ord($data[0]) & 0xC0) === 0
            && substr($data, 4, 4) === self::MAGIC_COOKIE;
    }

    /**
     * @internal called by MuxedSocket
     */
    public function send(MuxedSocket $from, string $data, string $remoteAddress): void
    {
        $socket = $this->sockets[$from->getHost()] ?? null;
        if ($socket === null) {
            return;
        }
        // only requests get answers worth routing back
        if (self::isStun($data) && (ord($data[0]) & 0x01) === 0 && (ord($data[1]) & 0x10) === 0) {
            if (count($this->transactions) >= self::MAX_TRANSACTIONS) {
                $this->transactions = [];
            }
            $this->transactions[substr($data, 8, 12)] = $from;
        }
        $socket->send($data, $remoteAddress);
    }

    /**
     * @internal called by MuxedSocket
     */
    public function release(MuxedSocket $muxed): void
    {
        $host = $muxed->getHost();
        unset($this->muxed[$host][spl_object_id($muxed)]);
        foreach ($this->addresses[$host] ?? [] as $address => $owner) {
            if ($owner === $muxed) {
                unset($this->addresses[$host][$address]);
            }
        }
        foreach ($this->transactions as $id => $owner) {
            if ($owner === $muxed) {
                unset($this->transactions[$id]);
            }
        }
    }

    /**
     * Closes every shared socket. Agents still holding a virtual socket are told it closed.
     */
    public function close(): void
    {
        foreach ($this->muxed as $muxedSockets) {
            foreach ($muxedSockets as $muxed) {
                $muxed->close();
            }
        }
        foreach ($this->sockets as $socket) {
            $socket->close();
        }
        $this->sockets = [];
        $this->muxed = [];
        $this->addresses = [];
        $this->transactions = [];
    }
}
