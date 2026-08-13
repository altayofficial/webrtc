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
use React\Datagram\Factory;
use React\Datagram\SocketInterface;
use React\EventLoop\Loop;
use Throwable;
use Webrtc\Exception\RuntimeException;
use Webrtc\STUN\Datagram;
use Webrtc\STUN\ReceiverInterface;
use Webrtc\STUN\Trait\Request;
use Webrtc\TURN\Trait\TurnConnection;
use function React\Async\await;

/**
 * Class TurnUDPConnection
 * Protocol for handling TURN over UDP.
 */
class TurnUdpConnection extends Datagram implements TurnConnectionInterface
{
    use Request, TurnConnection;

    private string $id;

    /**
     * @param TurnConfigurationInterface $configuration
     * @param ReceiverInterface $receiver
     * @param ?LoggerInterface $logger
     * @param SocketInterface $socket
     */
    public function __construct(private readonly TurnConfigurationInterface $configuration,
                                private readonly ReceiverInterface          $receiver,
                                private readonly ?LoggerInterface            $logger,
                                SocketInterface                             $socket)
    {
        $this->remoteAddress = implode(":", $this->configuration->getTurnServer());
        $this->_loop = Loop::get();
        $this->id = Uuid::uuid4()->toString();
        parent::__construct($socket);
    }

    /**
     * @return string
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * Create a turn udp instance.
     *
     * @param TurnConfigurationInterface $configuration
     * @param ReceiverInterface $receiver The receiver.
     * @param ?LoggerInterface $logger
     * @return self
     */
    public static function create(TurnConfigurationInterface $configuration, ReceiverInterface $receiver, ?LoggerInterface $logger = null): self
    {
        $factory = new Factory();
        try {
            $socket = await($factory->createClient(implode(":", $configuration->getTurnServer())));
            return new static($configuration, $receiver, $logger, $socket);
        } catch (Throwable $e) {
            throw new RuntimeException(sprintf("Could not bind to %s", $e->getMessage()), $e->getCode(), $e);
        }
    }
}
