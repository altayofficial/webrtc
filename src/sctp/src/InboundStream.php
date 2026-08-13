<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\SCTP;

use Generator;
use Webrtc\Exception\InvalidArgumentException;
use Webrtc\SCTP\Chunk\DataChunk;

/**
 * Class InboundStream
 *
 * Handles the reassembly of incoming SCTP (Stream Control Transmission Protocol) DataChunks
 * into complete user messages for a single SCTP stream. This class maintains message order,
 * supports ordered and unordered delivery, and handles fragmentation and defragmentation
 * according to SCTP specifications.
 *
 * Key responsibilities:
 * - Accepts incoming DataChunks and inserts them into the reassembly buffer.
 * - Detects and prevents duplicate chunks.
 * - Reassembles complete messages from fragment sequences.
 * - Maintains and updates stream sequence numbers for ordered delivery.
 * - Allows pruning of acknowledged or obsolete chunks based on TSN (Transmission Sequence Number).
 *
 * Used in the context of SCTP-based WebRTC data channels to correctly parse and deliver
 * incoming fragmented messages on each stream.
 */
class InboundStream
{
    /** @var DataChunk[] List of DataChunk objects for reassembly. */
    private array $reassembly = [];

    /** @var int Sequence number for ordered chunks. */
    private int $sequenceNumber = 0;

    /**
     * Adds a DataChunk to the reassembly list in the correct order.
     *
     * @param DataChunk $chunk The DataChunk to add.
     * @throws InvalidArgumentException If a duplicate chunk is detected.
     */
    public function addChunk(DataChunk $chunk): void
    {
        if ($this->isChunkNew($chunk)) {
            $this->reassembly[] = $chunk;
            return;
        }

        foreach ($this->reassembly as $i => $rchunk) {
            if ($rchunk->getTsn() === $chunk->getTsn()) {
                throw new InvalidArgumentException("Duplicate chunk in reassembly");
            }

            if (SctpUtility::uint32Gt($rchunk->getTsn(), $chunk->getTsn())) {
                array_splice($this->reassembly, $i, 0, [$chunk]);
                return;
            }
        }
    }

    /**
     * Checks if the chunk is new and should be appended at the end.
     *
     * @param DataChunk $chunk
     * @return bool
     */
    private function isChunkNew(DataChunk $chunk): bool
    {
        return empty($this->reassembly) || SctpUtility::uint32Gt($chunk->getTsn(), end($this->reassembly)->getTsn());
    }

    /**
     * Pops complete messages from the reassembly list.
     *
     * @return Generator Yields tuples of (streamId, protocol, userData).
     */
    public function popMessages(): Generator
    {
        $pos = 0;

        while ($pos < count($this->reassembly)) {
            $message = $this->processMessageExtraction($pos);
            if ($message !== null) {
                yield $message;
            } else {
                break;
            }
        }
    }

    /**
     * Handles message extraction and validation.
     *
     * @param int &$pos Current position in reassembly an array.
     * @return array|null Returns a complete message tuple or null if incomplete.
     */
    private function processMessageExtraction(int &$pos): ?array
    {
        $startPos = null;
        $expectedTsn = -1;

        while ($pos < count($this->reassembly)) {
            $chunk = $this->reassembly[$pos];

            if ($startPos === null) {
                if (!$this->canStartMessage($chunk)) {
                    $pos++;
                    continue;
                }

                [$startPos, , $expectedTsn] = $this->initializeMessageExtraction($pos, $chunk);
                if ($startPos === null) {
                    return null;
                }
            } elseif ($chunk->getTsn() !== $expectedTsn) {
                return null;
            }

            if ($chunk->getFlags() & SctpConstant::SCTP_DATA_LAST_FRAG) {
                return $this->assembleMessage($startPos, $pos, $chunk);
            }

            $pos++;
            $expectedTsn = SctpUtility::tsnPlusOne($expectedTsn);
        }

        return null;
    }

    /**
     * Initializes variables for message extraction.
     *
     * @param int $pos Current position in reassembly an array.
     * @param DataChunk $chunk First chunk of the message.
     * @return array|null Returns an array with start position, ordered flag, and expected TSN or null if invalid.
     */
    private function initializeMessageExtraction(int $pos, DataChunk $chunk): ?array
    {
        $ordered = !($chunk->getFlags() & SctpConstant::SCTP_DATA_UNORDERED);
        if ($ordered && SctpUtility::uint16Gt($chunk->getStreamSeq(), $this->sequenceNumber)) {
            return null;
        }

        return [$pos, $ordered, $chunk->getTsn()];
    }

    /**
     * Checks if a chunk can start a new message.
     *
     * @param DataChunk $chunk
     * @return bool
     */
    private function canStartMessage(DataChunk $chunk): bool
    {
        return ($chunk->getFlags() & SctpConstant::SCTP_DATA_FIRST_FRAG) !== 0;
    }

    /**
     * Assembles a message from collected chunks and updates the sequence number.
     *
     * @param int $startPos Start position in reassembly an array.
     * @param int &$pos Current position in reassembly an array.
     * @param DataChunk $chunk The last fragment chunk.
     * @return array The assembled message tuple.
     */
    private function assembleMessage(int $startPos, int &$pos, DataChunk $chunk): array
    {
        $userData = implode('', array_map(fn(DataChunk $c) => $c->getUserData(), array_slice($this->reassembly, $startPos, $pos - $startPos + 1)));

        $this->reassembly = array_merge(
            array_slice($this->reassembly, 0, $startPos),
            array_slice($this->reassembly, $pos + 1)
        );

        if (!($chunk->getFlags() & SctpConstant::SCTP_DATA_UNORDERED) && $chunk->getStreamSeq() === $this->sequenceNumber) {
            $this->sequenceNumber = SctpUtility::uint16Add($this->sequenceNumber, 1);
        }

        $pos = $startPos;
        return [$chunk->getStreamId(), $chunk->getProtocol(), $userData];
    }

    /**
     * Prunes chunks up to the given TSN.
     *
     * @param int $tsn The TSN up to which chunks should be pruned.
     * @return int The total size of the pruned chunks.
     */
    public function pruneChunks(int $tsn): int
    {
        $pos = -1;
        $size = 0;

        foreach ($this->reassembly as $i => $chunk) {
            if (SctpUtility::uint32Gte($tsn, $chunk->getTsn())) {
                $pos = $i;
                $size += strlen($chunk->getUserData());
            } else {
                break;
            }
        }

        $this->reassembly = array_slice($this->reassembly, $pos + 1);
        return $size;
    }

    /**
     * @return int
     */
    public function getSequenceNumber(): int
    {
        return $this->sequenceNumber;
    }

    /**
     * @param int $sequenceNumber
     * @return void
     */
    public function setSequenceNumber(int $sequenceNumber): void
    {
        $this->sequenceNumber = $sequenceNumber;
    }

    /**
     * @return DataChunk[]
     */
    public function getReassembly(): array
    {
        return $this->reassembly;
    }

}