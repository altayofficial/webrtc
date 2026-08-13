<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\TURN;

use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Random\RandomException;
use React\EventLoop\Loop;
use React\Socket\ConnectionInterface;
use React\Socket\Connector;
use Throwable;
use Webrtc\Exception\RuntimeException;
use Webrtc\STUN\ReceiverInterface;
use Webrtc\STUN\Trait\Request;
use Webrtc\STUN\Utils;
use Webrtc\TURN\Trait\TurnConnection;
use function React\Async\await;

/**
 * Class TurnTcpConnection
 * Protocol for handling TURN over TCP.
 */
class TurnTcpConnection extends TCPConnection implements TurnConnectionInterface
{
    use Request, TurnConnection;

    private string $id;
    private string $buffer = "";

    /**
     * @param TurnConfigurationInterface $configuration
     * @param ReceiverInterface $receiver
     * @param ?LoggerInterface $logger
     * @param ConnectionInterface $socket
     */
    public function __construct(private readonly TurnConfigurationInterface $configuration,
                                private readonly ReceiverInterface          $receiver,
                                private readonly ?LoggerInterface            $logger,
                                ConnectionInterface                         $socket)
    {
        parent::__construct($socket);
        $this->_loop = Loop::get();
        $this->id = Uuid::uuid4()->toString();
    }

    /**
     * @return string
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * Handle received messages.
     *
     * @param string $data The received data.
     * @return void
     * @throws RandomException
     */
    public function onTCPReceived(string $data): void
    {
        $this->buffer .= $data;

        while (strlen($this->buffer) >= 4) {
            [, $length] = array_values(unpack("nChannel/nLength", substr($this->buffer, 0, 4)));
            $length += Utils::paddingLength($length);

            if ($this->isChannelData($this->buffer)) {
                $fullLength = 4 + $length;
            } else {
                $fullLength = 20 + $length;
            }

            if (strlen($this->buffer) < $fullLength) {
                break;
            }

            $address = $this->getRemoteAddress();
            $data = substr($this->buffer, 0, $fullLength);
            $this->onReceived($data, $address);
            $this->buffer = substr($this->buffer, $fullLength);
        }
    }

    /**
     * Add pad if needed
     *
     * @param string $data
     * @return string
     */
    protected function padded(string $data): string
    {
        $padLen = Utils::paddingLength(strlen($data));
        return $data . str_repeat("\0", $padLen);

    }
    /**
     * Create a TurnTCP instance.
     *
     * @param TurnConfigurationInterface $configuration
     * @param ReceiverInterface $receiver The receiver.
     * @param ?LoggerInterface $logger
     * @return self
     */
    public static function create(TurnConfigurationInterface $configuration, ReceiverInterface $receiver, ?LoggerInterface $logger = null): self
    {
        $address = implode(":", $configuration->getTurnServer());
        $connector = new Connector(['tls' => $configuration->getTurnSsl()]);

        try {
            $socket = await($connector->connect($address));
            return new static($configuration, $receiver, $logger, $socket);
        } catch (Throwable $e) {
            throw new RuntimeException(sprintf("Could not connect to %s - %s", $address, $e->getMessage()), $e->getCode(), $e);
        }
    }
}
