<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\SCTP\Trait;

use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use SplQueue;
use Webrtc\DataChannel\Enum\State as DataChannelState;
use Webrtc\DataChannel\RTCDataChannel;
use Webrtc\DataChannel\RTCDataChannelParameters;
use Webrtc\Exception\InvalidArgumentException;
use Webrtc\Exception\RuntimeException;
use Webrtc\SCTP\Chunk\Chunk;
use Webrtc\SCTP\Chunk\DataChunk;
use Webrtc\SCTP\Chunk\ForwardTsnChunk;
use Webrtc\SCTP\Enum\State;
use Webrtc\SCTP\Param\StreamResetOutgoingParam;
use Webrtc\SCTP\SctpConstant;
use Webrtc\SCTP\SctpUtility;
use Webrtc\SSL\Exception\OpenSSLException;
use Webrtc\SSL\Exception\SSLException;
use Webrtc\SSL\Exception\SysCallException;
use Webrtc\SSL\Exception\WantReadException;
use Webrtc\SSL\Exception\WantWriteException;
use Webrtc\SSL\Exception\WantX509LookupException;
use Webrtc\SSL\Exception\ZeroReturnException;

/**
 * Trait DataChannel
 *
 * Provides support for managing SCTP-based WebRTC data channels.
 * Includes timers, retransmissions, congestion control, data flushing,
 * and stream lifecycle management.
 */
trait DataChannel
{
    private ?TimerInterface $dataChannelTask = null;
    private LoopInterface $loop;
    private ?int $dataChannelId = null;
    /** @var SplQueue<array<RTCDataChannel, int, string>> */
    private SplQueue $dataChannelQueue;
    /** @var RTCDataChannel[] */
    private array $dataChannels = [];


    /**
     *  Returns the current data channel timer.
     *
     * @return TimerInterface|null
     */
    public function getDataChannelTask(): ?TimerInterface
    {
        return $this->dataChannelTask;
    }

    /**
     * Handler for data channel task expiration.
     * Clears retransmission states and resets congestion control.
     *
     * @return void
     */
    public function dataChannelTaskExpired(): void
    {
        $this->dataChannelTask = null;
        $this->log(" DataChannel task expired!");

        // Mark retransmitting or abandoned chunks
        foreach ($this->sentQueue as $chunk) {
            if (!$this->maybeAbandon($chunk)) {
                $chunk->getAttributes()->retransmit = true;
            }
        }
        $this->updateAdvancedPeerAckPoint();

        // Adjust a congestion window
        $this->fastRecoveryExit = null;
        $this->flightSize = 0;
        $this->partialBytesAcked = 0;

        $this->ssthresh = max(intdiv($this->cwnd, 2), 4 * SctpConstant::USERDATA_MAX_LENGTH);
        $this->cwnd = SctpConstant::USERDATA_MAX_LENGTH;

        $this->loop->futureTick(fn() => $this->transmit());
    }

    /**
     * Restarts the data channel timer.
     *
     * @return void
     */
    private function dataChannelTaskRestart(): void
    {
        $this->log(" Datachannel timer restarted");
        if ($this->dataChannelTask !== null) {
            $this->loop->cancelTimer($this->dataChannelTask);
            $this->dataChannelTask = null;
        }
        $this->dataChannelTask = $this->loop->addTimer($this->rto, fn() => $this->dataChannelTaskExpired());
    }

    /**
     * Starts the data channel timer if not already running.
     *
     * @throws RuntimeException if already started.
     */
    private function dataChannelTaskStart(): void
    {
        if ($this->dataChannelTask !== null) {
            throw new RuntimeException("Datachannel timer already started");
        }
        $this->log(" Datachannel timer started");
        $this->dataChannelTask = $this->loop->addTimer($this->rto, fn() => $this->dataChannelTaskExpired());
    }

    /**
     * Cancels the running data channel timer, if any.
     */
    public function dataChannelTaskCancel(): void
    {
        if ($this->dataChannelTask !== null) {
            $this->log(" Datachannel timer canceled");
            $this->loop->cancelTimer($this->dataChannelTask);
            $this->dataChannelTask = null;
        }
    }


    /**
     *
     * Processes and sends the forward TSN chunk if it exists.
     * If there is no forward TSN chunk, it does nothing.
     * Ensures the data channel task is started if it's not already running.
     *
     * @return void
     * @throws OpenSSLException
     * @throws SSLException
     * @throws SysCallException
     * @throws WantReadException
     * @throws WantWriteException
     * @throws WantX509LookupException
     * @throws ZeroReturnException
     */
    private function processForwardTsn(): void
    {
        if ($this->forwardTsnChunk !== null) {
            $this->sendChunk($this->forwardTsnChunk);
            $this->forwardTsnChunk = null;
            if ($this->dataChannelTask === null) {
                $this->dataChannelTaskStart();
            }
        }
    }

    /**
     * Calculates the congestion window size (cwnd) based on the current flight size.
     * Takes into account the fast recovery state and burst size.
     *
     * @return int The calculated congestion window size.
     */
    private function calculateCwnd(): int
    {
        $burstSize = ($this->fastRecoveryExit !== null)
            ? 2 * SctpConstant::USERDATA_MAX_LENGTH
            : 4 * SctpConstant::USERDATA_MAX_LENGTH;

        return min($this->flightSize + $burstSize, $this->cwnd);
    }

    /**
     * Handles the retransmission of chunks that need it, and the transmission of chunks
     * from the outbound queue. It ensures that the flight size doesn’t exceed the congestion window (cwnd).
     *
     * @param int $cwnd The current congestion window size.
     * @throws OpenSSLException
     * @throws SSLException
     * @throws SysCallException
     * @throws WantReadException
     * @throws WantWriteException
     * @throws WantX509LookupException
     * @throws ZeroReturnException
     */
    private function retransmitAndTransmit(int $cwnd): void
    {
        $retransmitEarliest = true;

        // Retransmit chunks from the sent queue if they need retransmission
        foreach ($this->sentQueue as $chunk) {
            if ($chunk->getAttributes()->retransmit) {
                if ($this->fastRecoveryTransmit) {
                    $this->fastRecoveryTransmit = false;
                } elseif ($this->flightSize >= $cwnd) {
                    return; // Stop if flight size exceeds cwnd
                }


                $this->flightSizeIncrease($chunk);
                $this->resetChunkRetransmitState($chunk);
                $this->sendChunk($chunk);

                if ($retransmitEarliest) {
                    $this->dataChannelTaskRestart();
                }
            }
            $retransmitEarliest = false;
        }

        // Transmit from the outbound queue if we haven't reached the congestion window size
        while (!$this->outboundQueue->isEmpty() && $this->flightSize < $cwnd) {
            $chunk = $this->outboundQueue->dequeue();
            $this->sentQueue->enqueue($chunk);
            $this->flightSizeIncrease($chunk);

            // Update chunk counters and send the chunk
            $chunk->getAttributes()->sentCount += 1;
            $chunk->getAttributes()->sentTime = microtime(true);
            $this->sendChunk($chunk);

            // Start the datachannel task if not already running
            $this->ensureDataChannelTaskRunning();
        }
    }

    /**
     * Ensures that the data channel task is running.
     * Starts it if it is not already running.
     */
    private function ensureDataChannelTaskRunning(): void
    {
        if ($this->dataChannelTask === null) {
            $this->dataChannelTaskStart();
        }
    }

    /**
     * Resets the retransmitted state of a chunk.
     * This includes clearing the misses count, marking it as no longer needing retransmission, and incrementing the sent count.
     *
     * @param Chunk $chunk The chunk whose retransmit state will be reset.
     */
    private function resetChunkRetransmitState(Chunk $chunk): void
    {
        $chunk->getAttributes()->misses = 0;
        $chunk->getAttributes()->retransmit = false;
        $chunk->getAttributes()->sentCount += 1;
    }


    /**
     *
     * Main method that orchestrates the transmission process.
     * This method:
     * - Processes the forward TSN chunk.
     * - Calculates the congestion window size (cwnd).
     * - Handles retransmission of chunks and transmits chunks from the outbound queue.
     *
     * @return void
     * @throws OpenSSLException
     * @throws SSLException
     * @throws SysCallException
     * @throws WantReadException
     * @throws WantWriteException
     * @throws WantX509LookupException
     * @throws ZeroReturnException
     */
    public function transmit(): void
    {
        $this->processForwardTsn();
        $cwnd = $this->calculateCwnd();
        $this->retransmitAndTransmit($cwnd);
    }

    /**
     * Transmit reconfiguration requests.
     */
    private function transmitReconfig(): void
    {
        if (
            $this->state === State::ESTABLISHED &&
            !empty($this->reconfigQueue) &&
            $this->reconfigRequest === null
        ) {
            $streams = array_slice($this->reconfigQueue, 0, SctpConstant::RECONFIG_MAX_STREAMS);
            $this->reconfigQueue = array_slice($this->reconfigQueue, SctpConstant::RECONFIG_MAX_STREAMS);

            $param = new StreamResetOutgoingParam(
                requestSequence: $this->reconfigRequestSeq,
                responseSequence: $this->reconfigResponseSeq,
                lastTsn: SctpUtility::tsnMinusOne($this->localTsn),
                streams: $streams
            );

            $this->reconfigRequest = $param;
            $this->reconfigRequestSeq = SctpUtility::tsnPlusOne($this->reconfigRequestSeq);

            // Transmit the reconfiguration parameter asynchronously.
            $this->loop->futureTick(fn() => $this->sendReconfigParam($param));
        }
    }

    /**
     * Update the advanced peer acknowledgment point.
     */
    public function updateAdvancedPeerAckPoint(): void
    {
        if (SctpUtility::uint32Gt($this->lastSackedTsn, $this->advancedPeerAckTsn)) {
            $this->advancedPeerAckTsn = $this->lastSackedTsn;
        }

        $done = 0;
        $streams = [];
        while (!$this->sentQueue->isEmpty() && $this->sentQueue->top()->getAttributes()->abandoned) {
            $chunk = $this->sentQueue->dequeue();
            $this->advancedPeerAckTsn = $chunk->getTsn();
            $done++;
            if (!($chunk->getFlags() & SctpConstant::SCTP_DATA_UNORDERED)) {
                $streams[$chunk->getStreamId()] = $chunk->getStreamSeq();
            }
        }

        if ($done) {
            // Build FORWARD TSN
            $this->forwardTsnChunk = new ForwardTsnChunk();
            $this->forwardTsnChunk->setCumulativeTsn($this->advancedPeerAckTsn);
            $this->forwardTsnChunk->setStreams(array_map(fn($key, $value) => [$key, $value], array_keys($streams), $streams));
        }
    }

    /**
     * Sends data over the specified stream, fragmenting as needed.
     *
     * @param int $streamId
     * @param int $ppId
     * @param string $userData
     * @param float|null $expiry
     * @param int|null $maxRetransmits
     * @param bool|null $ordered
     * @return void
     * @throws OpenSSLException
     * @throws SSLException
     * @throws SysCallException
     * @throws WantReadException
     * @throws WantWriteException
     * @throws WantX509LookupException
     * @throws ZeroReturnException
     */
    public function sendDataStream(
        int    $streamId,
        int    $ppId,
        string $userData,
        ?float $expiry = 0,
        ?int   $maxRetransmits = null,
        ?bool  $ordered = true
    ): void
    {
        // Determine stream sequence
        $streamSeq = $ordered ? ($this->outboundStreamSeq[$streamId] ?? 0) : 0;
        $fragments = (int)ceil(strlen($userData) / SctpConstant::USERDATA_MAX_LENGTH);
        $pos = 0;

        for ($fragment = 0; $fragment < $fragments; $fragment++) {
            $chunk = new DataChunk();
            $chunk->setFlags(0);
            if (!$ordered) {
                $chunk->setFlags(SctpConstant::SCTP_DATA_UNORDERED);
            }
            if ($fragment === 0) {
                $chunk->setFlags($chunk->getFlags() | SctpConstant::SCTP_DATA_FIRST_FRAG);
            }
            if ($fragment === $fragments - 1) {
                $chunk->setFlags($chunk->getFlags() | SctpConstant::SCTP_DATA_LAST_FRAG);
            }

            $chunk->setTsn($this->localTsn);
            $chunk->setStreamId($streamId);
            $chunk->setStreamSeq($streamSeq);
            $chunk->setProtocol($ppId);
            $chunk->setUserData(substr($userData, $pos, SctpConstant::USERDATA_MAX_LENGTH));

            $chunk->getAttributes()->bookSize = strlen($chunk->getUserData());
            $chunk->getAttributes()->expiry = $expiry ?? 0;
            $chunk->getAttributes()->maxRetransmits = $maxRetransmits;

            $pos += SctpConstant::USERDATA_MAX_LENGTH;
            $this->localTsn = SctpUtility::tsnPlusOne($this->localTsn);
            $this->outboundQueue->enqueue($chunk);
        }

        if ($ordered) {
            $this->outboundStreamSeq[$streamId] = SctpUtility::uint16Add($streamSeq, 1);
        }

        // Transmit outbound data
        $this->transmit();
    }

    /**
     * Requests closing a data channel by sending an Outgoing Stream Reset Request.
     *
     * @param RTCDataChannel $channel The channel to be closed.
     */
    public function dataChannelClose(RTCDataChannel $channel): void
    {
        if (!in_array($channel->getReadyState(), [DataChannelState::Closing, DataChannelState::Closed])) {
            $channel->setReadyState(DataChannelState::Closing);

            if ($this->state == State::ESTABLISHED) {
                $this->reconfigQueue[] = $channel->getId();
                if (count($this->reconfigQueue) == 1) {
                    $this->transmitReconfig();
                }

            } else {
                $newQueue = new SplQueue;

                while (!$this->dataChannelQueue->isEmpty()) {
                    $queueItem = $this->dataChannelQueue->dequeue();
                    if ($queueItem[0] !== $channel) {
                        $newQueue->enqueue($queueItem);
                    }
                }

                $this->dataChannelQueue = $newQueue;

                if ($channel->getId() !== null) {
                    unset($this->dataChannels[$channel->getId()]);
                }

                $channel->setReadyState(DataChannelState::Closed);
            }
        }
    }

    /**
     * Marks the specified data channel as closed.
     *
     * @param int $streamId The ID of the stream to close.
     */
    public function dataChannelClosed(int $streamId): void
    {
        if (isset($this->dataChannels[$streamId])) {
            $channel = $this->dataChannels[$streamId];
            $channel->setReadyState(DataChannelState::Closed);
            unset($this->dataChannels[$streamId]);
        }
    }


    /**
     *
     * Attempts to flush buffered data to the SCTP layer, waiting for the association to be established.
     *
     * @return void
     * @throws OpenSSLException
     * @throws SSLException
     * @throws SysCallException
     * @throws WantReadException
     * @throws WantWriteException
     * @throws WantX509LookupException
     * @throws ZeroReturnException
     */
    public function dataChannelFlush(): void
    {
        if ($this->state != State::ESTABLISHED) {
            return;
        }

        while (!$this->dataChannelQueue->isEmpty() && $this->outboundQueue->isEmpty()) {
            [$channel, $protocol, $userData] = $this->dataChannelQueue->dequeue();

            $streamId = $channel->getId();
            if ($streamId === null) {
                $streamId = $this->dataChannelId;
                while (isset($this->dataChannels[$streamId])) {
                    $streamId += 2;
                }
                $this->dataChannels[$streamId] = $channel;
                $channel->setId($streamId);
            }

            if ($channel instanceof RTCDataChannel) {
                if ($protocol === SctpConstant::WEBRTC_DCEP) {
                    $this->sendDataStream($streamId, $protocol, $userData);
                } else {
                    $expiry = null;
                    if ($channel->getMaxPacketLifeTime() !== null) {
                        $expiry = time() + ($channel->getMaxPacketLifeTime() / 1000);
                    }
                    $this->sendDataStream(
                        $streamId,
                        $protocol,
                        $userData,
                        $expiry,
                        $channel->getMaxRetransmits(),
                        $channel->getOrdered()
                    );
                    $channel->addBufferedAmount(-strlen($userData));
                }
            }

        }
    }

    /**
     * Adds a negotiated data channel to the handler.
     *
     * @param RTCDataChannel $channel The channel to add.
     *
     * @throws InvalidArgumentException If the data channel ID is already registered.
     */
    public function dataChannelAddNegotiated(RTCDataChannel $channel): void
    {
        if (isset($this->dataChannels[$channel->getId()])) {
            throw new InvalidArgumentException("Data channel with ID {$channel->getId()} already registered");
        }

        $this->dataChannels[$channel->getId()] = $channel;

        if ($this->state == State::ESTABLISHED) {
            $channel->setReadyState(DataChannelState::Open);
        }
    }

    /**
     * Opens a new data channel, registering it and setting its properties.
     *
     * @param RTCDataChannel $channel The channel to open.
     *
     * @throws InvalidArgumentException If the channel ID is already registered.
     */
    public function dataChannelOpen(RTCDataChannel $channel): void
    {
        if ($channel->getId() !== null) {
            if (isset($this->dataChannels[$channel->getId()])) {
                throw new InvalidArgumentException("Data channel with ID {$channel->getId()} already registered");
            } else {
                $this->dataChannels[$channel->getId()] = $channel;
            }
        }
        $channelType = SctpConstant::DATA_CHANNEL_RELIABLE;
        $priority = 0;
        $reliability = 0;

        if (!$channel->getOrdered()) {
            $channelType |= 0x80;
        }
        if ($channel->getMaxRetransmits() !== null) {
            $channelType |= 1;
            $reliability = $channel->getMaxRetransmits();
        } elseif ($channel->getMaxPacketLifeTime() !== null) {
            $channelType |= 2;
            $reliability = $channel->getMaxPacketLifeTime();
        }

        $data = pack("CCnNnn", SctpConstant::DATA_CHANNEL_OPEN, $channelType, $priority, $reliability, strlen($channel->getLabel()), strlen($channel->getProtocol()));
        $data .= $channel->getLabel();
        $data .= $channel->getProtocol();
        $this->dataChannelQueue->enqueue([$channel, SctpConstant::WEBRTC_DCEP, $data]);
        $this->loop->futureTick(fn() => $this->dataChannelFlush());
    }

    /**
     * Receives a message for a data channel, handling different message types.
     *
     * @param int $streamId The ID of the stream.
     * @param int $ppId The protocol ID.
     * @param string $data The received data.
     *
     * @throws InvalidArgumentException If an invalid stream ID is encountered.
     */
    public function dataChannelReceive(int $streamId, int $ppId, string $data): void
    {
        if ($ppId === SctpConstant::WEBRTC_DCEP && strlen($data) > 0) {
            $msgType = ord($data[0]);
            if ($msgType === SctpConstant::DATA_CHANNEL_OPEN && strlen($data) >= 12) {
                // Assert no existing channel for this stream ID
                if (isset($this->dataChannels[$streamId])) {
                    throw new InvalidArgumentException("Data channel with stream ID $streamId already exists");
                }

                $unpacked = unpack("CmsgType/CchannelType/npriority/Nreliability/nlabelLength/nprotocolLength", $data);
                $pos = 12;
                $label = substr($data, $pos, $unpacked["labelLength"]);

                $pos += $unpacked["labelLength"];
                $protocol = substr($data, $pos, $unpacked["protocolLength"]);

                // Determine reliability settings
                $maxPacketLifeTime = null;
                $maxRetransmits = null;
                if (($unpacked["channelType"] & 0x03) === 1) {
                    $maxRetransmits = $unpacked["reliability"];
                } elseif (($unpacked["channelType"] & 0x03) === 2) {
                    $maxPacketLifeTime = $unpacked["reliability"];
                }

                // Register the channel
                $parameters = new RTCDataChannelParameters(
                    label: $label,
                    maxPacketLifeTime: $maxPacketLifeTime,
                    maxRetransmits: $maxRetransmits,
                    ordered: ($unpacked["channelType"] & 0x80) === 0,
                    protocol: $protocol,
                    id: $streamId
                );
                $channel = new RTCDataChannel($this, $parameters, false);
                $channel->setReadyState(DataChannelState::Open);
                $this->dataChannels[$streamId] = $channel;

                // Send ACK
                $this->dataChannelQueue->enqueue([
                    $channel,
                    SctpConstant::WEBRTC_DCEP,
                    pack("C", SctpConstant::DATA_CHANNEL_ACK)
                ]);

                $this->loop->futureTick(fn() => $this->dataChannelFlush());

                // Emit event
                $this->emit("datachannel", [$channel]);
            } elseif ($msgType === SctpConstant::DATA_CHANNEL_ACK) {
                if (!isset($this->dataChannels[$streamId])) {
                    throw new InvalidArgumentException("Data channel with stream ID $streamId does not exist");
                }
                $channel = $this->dataChannels[$streamId];
                $channel->setReadyState(DataChannelState::Open);
            }
        } elseif ($ppId === SctpConstant::WEBRTC_STRING && isset($this->dataChannels[$streamId])) {
            // Emit message for string data
            $this->dataChannels[$streamId]->emit("message", [$data]);
        } elseif ($ppId === SctpConstant::WEBRTC_STRING_EMPTY && isset($this->dataChannels[$streamId])) {
            // Emit empty string message
            $this->dataChannels[$streamId]->emit("message", [""]);
        } elseif ($ppId === SctpConstant::WEBRTC_BINARY && isset($this->dataChannels[$streamId])) {
            // Emit binary message
            $this->dataChannels[$streamId]->emit("message", [$data]);
        } elseif ($ppId === SctpConstant::WEBRTC_BINARY_EMPTY && isset($this->dataChannels[$streamId])) {
            // Emit empty binary message
            $this->dataChannels[$streamId]->emit("message", [""]);
        }
    }

    /**
     * Sends data over a specified data channel.
     *
     * @param RTCDataChannel $channel The channel to send data over.
     * @param string $data The data to send.
     * @throws OpenSSLException
     * @throws SSLException
     * @throws SysCallException
     * @throws TLSException
     * @throws WantReadException
     * @throws WantWriteException
     * @throws WantX509LookupException
     * @throws ZeroReturnException
     */
    public function dataChannelSend(RTCDataChannel $channel, string $data): void
    {
        if ($data === "") {
            $ppId = SctpConstant::WEBRTC_STRING_EMPTY;
            $userData = "\x00";
        } elseif (mb_check_encoding($data, 'UTF-8')) {
            $ppId = SctpConstant::WEBRTC_STRING;
            $userData = mb_convert_encoding($data, 'UTF-8', 'ISO-8859-1');
        } elseif ($data === "\x00") {
            $ppId = SctpConstant::WEBRTC_BINARY_EMPTY;
            $userData = "\x00";
        } else {
            $ppId = SctpConstant::WEBRTC_BINARY;
            $userData = $data;
        }

        $channel->addBufferedAmount(strlen($userData));
        $this->dataChannelQueue->enqueue([$channel, $ppId, $userData]);
        $this->dataChannelFlush();
    }

    /**
     * Update the RTO given a new round-trip measurement.
     *
     * @param float $rtt The round-trip time.
     */
    public function updateRto(float $rtt): void
    {
        if ($this->srtt === null) {
            $this->rttvar = $rtt / 2;
            $this->srtt = $rtt;
        } else {
            $this->rttvar = (1 - SctpConstant::SCTP_RTO_BETA) * $this->rttvar + SctpConstant::SCTP_RTO_BETA * abs($this->srtt - $rtt);
            $this->srtt = (1 - SctpConstant::SCTP_RTO_ALPHA) * $this->srtt + SctpConstant::SCTP_RTO_ALPHA * $rtt;
        }
        $this->rto = max(SctpConstant::SCTP_RTO_MIN, min($this->srtt + 4 * $this->rttvar, SctpConstant::SCTP_RTO_MAX));
    }
}