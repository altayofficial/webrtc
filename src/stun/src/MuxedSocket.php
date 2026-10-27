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

use Evenement\EventEmitter;
use React\Datagram\SocketInterface;

/**
 * One agent's view of a port shared through UdpMux. Closing it only takes the agent off the
 * shared socket, the socket itself stays up for everyone else.
 */
class MuxedSocket extends EventEmitter implements SocketInterface
{
    private bool $closed = false;

    /**
     * @param \Closure(): string $localUfrag
     */
    public function __construct(
        private readonly UdpMux $mux,
        private readonly string $host,
        private readonly string $localAddress,
        private readonly \Closure $localUfrag
    ) {
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function localUfrag(): string
    {
        return ($this->localUfrag)();
    }

    /**
     * @internal called by UdpMux for a datagram routed here
     */
    public function receive(string $data, string $peer): void
    {
        if (!$this->closed) {
            $this->emit("message", [$data, $peer, $this]);
        }
    }

    public function send($data, $remoteAddress = null)
    {
        // hmm, there is no connected peer on a shared socket, so a send without an address goes nowhere
        if (!$this->closed && $remoteAddress !== null) {
            $this->mux->send($this, $data, $remoteAddress);
        }
    }

    public function close()
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $this->mux->release($this);
        $this->emit("close", [$this]);
        $this->removeAllListeners();
    }

    public function end()
    {
        $this->close();
    }

    public function resume()
    {
    }

    public function pause()
    {
    }

    public function getLocalAddress()
    {
        return $this->localAddress;
    }

    public function getRemoteAddress()
    {
        return null;
    }
}
