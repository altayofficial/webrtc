<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\RTP\Receiver;

use Webrtc\RTP\RtpConstants;
use Webrtc\RTP\RtpPacket;

/**
 * NackGenerator tracks missing RTP packets based on sequence numbers,
 * enabling the generation of NACK (Negative Acknowledgment) feedback.
 *
 * This class is essential for improving media reliability in RTP-based transport by
 * detecting lost packets and maintaining a list of missing sequence numbers.
 * It handles 16-bit sequence number wrap-around and automatically prunes old entries
 * to limit memory usage based on a fixed RTP history window.
 */
class NackGenerator
{
    private ?int $maxSeq = null;
    private array $missing = [];

    /**
     * Add a new RTP packet to track missing packets.
     *
     * @param RtpPacket $packet The received RTP packet.
     * @return bool True if there are missing packets, false otherwise.
     */
    public function add(RtpPacket $packet): bool
    {
        $missed = false;

        if ($this->maxSeq === null) {
            $this->maxSeq = $packet->getSequenceNumber();
            return false;
        }

        // Mark missing packets
        if ($this->uint16Gt($packet->getSequenceNumber(), $this->maxSeq)) {
            $seq = $this->uint16Add($this->maxSeq, 1);
            while ($this->uint16Gt($packet->getSequenceNumber(), $seq)) {
                $this->missing[$seq] = true;
                $missed = true;
                $seq = $this->uint16Add($seq, 1);
            }
            $this->maxSeq = $packet->getSequenceNumber();
        } else {
            unset($this->missing[$packet->getSequenceNumber()]);
        }

        // Limit the number of tracked packets
        $this->truncate();

        return $missed;
    }

    /**
     * Truncate the missing packets to prevent excessive memory usage.
     */
    private function truncate(): void
    {
        if ($this->maxSeq !== null) {
            $minSeq = $this->uint16Add($this->maxSeq, -RtpConstants::RTP_HISTORY_SIZE);
            foreach (array_keys($this->missing) as $seq) {
                if ($this->uint16Gt($minSeq, $seq)) {
                    unset($this->missing[$seq]);
                }
            }
        }
    }

    /**
     * Check if a 16-bit sequence number is greater than another.
     *
     * @param int $a First sequence number.
     * @param int $b Second sequence number.
     * @return bool True if $a > $b considering 16-bit wrap-around.
     */
    private function uint16Gt(int $a, int $b): bool
    {
        $halfMod = 0x8000;
        return (($a < $b) && (($b - $a) > $halfMod)) || (($a > $b) && (($a - $b) < $halfMod));
    }


    /**
     * Add a value to a 16-bit sequence number, handling wrap-around.
     *
     * @param int $a Base sequence number.
     * @param int $b Value to add.
     * @return int The Resulting sequence number within 16-bit range.
     */
    private function uint16Add(int $a, int $b): int
    {
        return ($a + $b) & 0xFFFF;
    }

    /**
     * Get the list of missing sequence numbers.
     *
     * @return array List of missing sequence numbers.
     */
    public function getMissing(): array
    {
        return array_keys($this->missing);
    }
}
