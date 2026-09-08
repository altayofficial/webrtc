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

use Exception;
use React\Datagram\Buffer;
use React\EventLoop\LoopInterface;
use function count;
use function error_clear_last;
use function error_get_last;
use function str_contains;
use function stream_socket_sendto;
use function strtolower;
use function trim;

/**
 * A datagram write buffer that puts the packet on the wire straight away.
 *
 * The base class queues every outgoing datagram and writes one per writable event, so a packet
 * leaves on the next turn of the event loop at the earliest and a burst leaves at one packet per
 * turn. Under a loop that is driven at a fixed tick rate that turns a 128 KB body into hundreds of
 * ticks worth of waiting, and it costs a turn of the loop per packet even when it is only one.
 *
 * A UDP send to a socket with room in its buffer cannot block, so the common case needs none of
 * that. The queue is kept for the case where the kernel buffer really is full.
 */
class ImmediateBuffer extends Buffer
{
    /** @var array<int, array{string, string|null}> Datagrams the kernel had no room for */
    private array $queue = [];

    private bool $watching = false;
    private bool $open = true;

    public function __construct(LoopInterface $loop, $socket)
    {
        parent::__construct($loop, $socket);
    }

    public function send($data, $remoteAddress = null)
    {
        if (!$this->open || $this->socket === false) {
            return;
        }

        // anything already waiting has to go first, or the association would see its packets reordered
        if ($this->queue !== []) {
            $this->enqueue($data, $remoteAddress);

            return;
        }

        if (!$this->write($data, $remoteAddress)) {
            $this->enqueue($data, $remoteAddress);
        }
    }

    public function onWritable()
    {
        while ($this->queue !== []) {
            [$data, $remoteAddress] = $this->queue[0];
            if (!$this->write($data, $remoteAddress)) {
                return;
            }
            array_shift($this->queue);
        }

        $this->unwatch();
    }

    public function close()
    {
        $this->open = false;
        $this->queue = [];
        $this->unwatch();

        parent::close();
    }

    public function end()
    {
        if ($this->queue === []) {
            $this->close();

            return;
        }

        parent::end();
    }

    /**
     * @return bool false when the datagram has to wait for the socket to drain
     */
    private function write(string $data, ?string $remoteAddress): bool
    {
        // installing an error handler around every datagram costs more than the send itself, so the
        // warning is suppressed and only looked up on the rare path where the send did not go through
        error_clear_last();

        // fwrite() obeys the stream buffer size and would split a packet at 8 KB
        $sent = $remoteAddress === null
            ? @stream_socket_sendto($this->socket, $data)
            : @stream_socket_sendto($this->socket, $data, 0, $remoteAddress);

        if ($sent >= 0 && $sent !== false) {
            return true;
        }

        $errstr = trim((string) (error_get_last()["message"] ?? ''));
        if (self::wouldBlock($errstr)) {
            return false;
        }

        $this->emit('error', [new Exception('Unable to send packet: ' . $errstr), $this]);

        // a datagram the kernel refused is gone either way, and the layer above has its own
        // retransmission - holding it back would only stall everything queued behind it
        return true;
    }

    private static function wouldBlock(string $errstr): bool
    {
        $errstr = strtolower($errstr);

        return str_contains($errstr, 'temporarily unavailable')
            || str_contains($errstr, 'would block')
            || str_contains($errstr, 'buffer space');
    }

    private function enqueue(string $data, ?string $remoteAddress): void
    {
        $this->queue[] = [$data, $remoteAddress];

        if (!$this->watching) {
            $this->watching = true;
            $this->handleResume();
        }
    }

    private function unwatch(): void
    {
        if ($this->watching) {
            $this->watching = false;
            $this->handlePause();
        }
    }

    public function queued(): int
    {
        return count($this->queue);
    }
}
