<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\DTLS\TLS;

use Exception;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use Throwable;
use Webrtc\DTLS\Enum\SSLHandshakeState;
use Webrtc\DTLS\Exception\HandshakeException;
use Webrtc\ICE\RTCIceTransportInterface;
use Webrtc\Mixin\EventForwarder;
use Webrtc\SSL\Exception\OpenSSLException;
use Webrtc\SSL\Exception\WantReadException;
use Webrtc\SSL\Exception\WantWriteException;
use Webrtc\SSL\SSL\BIOInterface;
use Webrtc\SSL\SSL\SSLInterface;
use Webrtc\Stats\enum\TLSState;

/**
 * Class Handshake
 *
 * Manages the DTLS (Datagram Transport Layer Security) handshake process using an asynchronous event loop.
 * This class handles both client and server-side handshake operations, managing timeouts, BIO buffer operations,
 * and state transitions during the TLS handshake process.
 *
 * @package Webrtc\DTLS\TLS
 */
class Handshake
{
    use EventForwarder;

    /** @var Deferred The deferred promise for handshake completion */
    private Deferred $deferred;

    /** @var LoopInterface The event loop instance */
    private LoopInterface $loop;

    /** @var SSLInterface The SSL context for the handshake */
    private SSLInterface $ssl;

    /** @var RTCIceTransportInterface|null The transport layer for sending/receiving data */
    private ?RTCIceTransportInterface $transport;

    /** @var BIOInterface The BIO buffer for SSL operations */
    private BIOInterface $bio;

    /** @var TimerInterface|null Periodic timer for checking handshake status */
    private ?TimerInterface $periodicCheck;

    /** @var TimerInterface|null Timer for handling DTLS timeout */
    private ?TimerInterface $timer = null;

    /** @var array Listeners for transport events */
    private array $listeners;

    /**
     * Handshake constructor.
     *
     * Initializes the handshake process with the given TLS context, transport, and initial state.
     *
     * @param TLS $tls The TLS context for this handshake
     * @param RTCIceTransportInterface $transport The transport layer for communication
     * @param SSLHandshakeState $state The initial handshake state (Accept or Connect)
     */
    public function __construct(private readonly TLS $tls, RTCIceTransportInterface $transport, private readonly SSLHandshakeState $state)
    {
        $this->deferred = new Deferred();
        $this->transport = $transport;
        $this->loop = Loop::get();
        $this->ssl = $tls->getSsl();
        $this->bio = $tls->getBio();
        $this->setSSLHandshakeState();
        $this->listeners = $this->forwardEvents2Methods($this->transport, ['data' => 'receive']);
    }

    /**
     * Receives data from the transport and writes it to the BIO buffer.
     *
     * This method is called when data is received on the transport layer. It cancels any pending
     * timeout timer and writes the received data to the BIO buffer for SSL processing.
     *
     * @param string $bytes The received data bytes
     * @throws OpenSSLException If writing to the BIO buffer fails
     */
    private function receive(string $bytes): void
    {
        if ($this->timer) {
            $this->loop->cancelTimer($this->timer);
            $this->timer = null;
        }

        $this->bio->write($bytes);
    }

    /**
     * Initiates the handshake process.
     *
     * Starts the handshake process and returns a promise that will be resolved when the handshake
     * completes successfully or rejected if it fails.
     *
     * @return PromiseInterface A promise representing the handshake completion
     */
    public function do(): PromiseInterface
    {
        $this->periodicCheckHandshakeStatus();
        return $this->deferred->promise();
    }

    /**
     * Sets the SSL handshake state based on the constructor parameter.
     *
     * Configures the SSL context to either accept (server mode) or connect (client mode).
     */
    private function setSSLHandshakeState(): void
    {
        if ($this->state === SSLHandshakeState::Accept) {
            $this->ssl->setAcceptState();
        } else {
            $this->ssl->setConnectState();
        }
    }

    /**
     * Periodically checks the handshake status.
     *
     * Sets up a periodic timer to check the handshake progress, sending/receiving data as necessary
     * and handling timeouts or errors that may occur during the process.
     */
    private function periodicCheckHandshakeStatus(): void
    {
        $this->periodicCheck = $this->loop->addPeriodicTimer(0, function (): void {
            try {
                $this->ssl->doHandshake();
                $this->cleanUp();
                $this->deferred->resolve(true);
            } catch (WantReadException) {
                $this->sendBIOData();
            } catch (WantWriteException) {
                // Handshake needs more writing, handled by async loop
            } catch (Throwable $e) {
                $this->deferred->reject(new HandshakeException("Handshake Failed", $e->getCode(), $e));
            }
            if (!$this->timer && $timeout = $this->ssl->dtlsV1GetTimeout()) {
                $this->handleDTLSTimeout($timeout);
            }
        });
    }

    /**
     * Sends data from the BIO buffer to the transport.
     *
     * Reads any pending data from the BIO buffer and send it through the transport layer.
     * This is typically called when SSL indicates it has data ready to send.
     */
    private function sendBIOData(): void
    {
        if ($data = $this->bio->read()) {
            try {
                $this->transport->send($data);
            } catch (Exception) {
                // Silently handle transport send errors
            }
        }
    }

    /**
     * Handles DTLS timeout events.
     *
     * Sets up a timer to handle DTLS-specific timeout requirements. When the timer expires,
     * it processes the timeout and sends any pending BIO data.
     *
     * @param float $timeout The timeout duration in seconds
     */
    private function handleDTLSTimeout(float $timeout): void
    {
        $this->timer = $this->loop->addTimer($timeout, function () {
            $this->ssl->dtlsV1HandleTimeout();
            $this->sendBIOData();
        });
    }

    /**
     * Cleans up resources after handshake completion.
     *
     * Removes event listeners, updates the TLS state, sends any remaining BIO data,
     * and cancels all active timers.
     */
    private function cleanUp(): void
    {
        $this->removeMessageListener();
        $this->tls->setState(TLSState::CONNECTED);
        $this->sendBIODataUntilEnd();

        $this->loop->cancelTimer($this->periodicCheck);
        if ($this->timer) {
            $this->loop->cancelTimer($this->timer);
        }
    }

    /**
     * Removes the data event listener from the transport.
     */
    private function removeMessageListener(): void
    {
        $this->transport->removeListener('data', $this->listeners[0]);
    }

    /**
     * Sends all remaining data from the BIO buffer.
     *
     * Continuously reads and sends any remaining data from the BIO buffer until it's empty.
     */
    private function sendBIODataUntilEnd(): void
    {
        while ($data = $this->bio->read()) {
            $this->transport->send($data);
        }
    }
}