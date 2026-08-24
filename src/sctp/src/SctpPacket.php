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

use Webrtc\Exception\InvalidArgumentException;
use Webrtc\SCTP\Chunk\Chunk;

/**
 * SCTP Packet handling class.
 *
 * This class provides functionality to encode and decode Stream Control Transmission Protocol (SCTP) packets.
 * SCTP is a transport layer protocol that provides reliable, message-oriented communication with congestion control.
 *
 * The class handles:
 * - Parsing raw binary SCTP packets into their components (header fields and chunks)
 * - Constructing SCTP packets from individual components
 * - Validating packet integrity through checksum verification
 * - Supporting all standard SCTP chunk types as defined in RFC 4960
 */
class SctpPacket
{
    /**
     * Mapping of SCTP chunk type codes to their corresponding class names.
     *
     * @var array<int, string> CHUNK_TYPES
     */
    public const CHUNK_TYPES = [
        0 => "DataChunk",
        1 => "InitChunk",
        2 => "InitAckChunk",
        3 => "SackChunk",
        4 => "HeartbeatChunk",
        5 => "HeartbeatAckChunk",
        6 => "AbortChunk",
        7 => "ShutdownChunk",
        8 => "ShutdownAckChunk",
        9 => "ErrorChunk",
        10 => "CookieEchoChunk",
        11 => "CookieAckChunk",
        14 => "ShutdownCompleteChunk",
        130 => "ReconfigChunk",
        192 => "ForwardTsnChunk",
    ];

    /**
     * Decodes a binary SCTP packet into its constituent parts.
     *
     * Parses the packet header and all chunks, validates the checksum, and returns
     * the packet components as an array. Throw an exception if the packet is invalid.
     *
     * @param string $data The binary string representing the SCTP packet.
     * @return array{
     *     0: int, // Source port
     *     1: int, // Destination port
     *     2: int, // Verification tag
     *     3: ChunkInterface[] // Array of chunk objects
     * }
     * @throws InvalidArgumentException If:
     *         - Packet length is less than 12 bytes (minimum SCTP header size)
     *         - Checksum validation fails
     *         - Chunk parsing fails
     */
    public static function decode(string $data): array
    {
        $length = strlen($data);
        if ($length < 12) {
            throw new InvalidArgumentException("SCTP packet length is less than 12 bytes");
        }

        $unpacked = unpack("nsourcePort/ndestinationPort/NverificationTag", $data);
        $sourcePort = $unpacked['sourcePort'];
        $destinationPort = $unpacked['destinationPort'];
        $verificationTag = $unpacked['verificationTag'];

        // Verify checksum
        $checksum = unpack("V", substr($data, 8, 4))[1];
        if ($checksum !== SctpUtility::crc32c(substr($data, 0, 8) . "\x00\x00\x00\x00" . substr($data, 12))) {
            throw new InvalidArgumentException("SCTP packet has invalid checksum");
        }

        $chunks = [];
        $pos = 12;
        while ($pos <= $length - 4) {
            $chunkHeader = unpack("Ctype/Cflags/nlength", substr($data, $pos, 4));
            $chunkType = $chunkHeader['type'];
            $chunkFlags = $chunkHeader['flags'];
            $chunkLength = $chunkHeader['length'];

            $chunkBody = substr($data, $pos + 4, $chunkLength - 4);
            if (isset(self::CHUNK_TYPES[$chunkType])) {
                $chunkCls = "\\Webrtc\\SCTP\\Chunk\\" . self::CHUNK_TYPES[$chunkType];
                $chunks[] = new $chunkCls($chunkFlags, $chunkBody);
            }

            $pos += $chunkLength + SctpUtility::padl($chunkLength);
        }

        return [$sourcePort, $destinationPort, $verificationTag, $chunks];
    }

    /**
     * Encodes SCTP packet components into a binary packet.
     *
     * Constructs a valid SCTP packet with header fields and a single chunk,
     * calculates and inserts the proper checksum.
     *
     * @param int $sourcePort The source port number (16-bit unsigned integer)
     * @param int $destinationPort The destination port number (16-bit unsigned integer)
     * @param int $verificationTag The verification tag (32-bit unsigned integer)
     * @param Chunk $chunk The chunk object to include in the packet
     * @return string The binary string representation of the complete SCTP packet
     */
    public static function encode(int $sourcePort, int $destinationPort, int $verificationTag, Chunk $chunk): string
    {
        $header = pack("nnN", $sourcePort, $destinationPort, $verificationTag);
        $data = $chunk->encode();
        $checksum = SctpUtility::crc32c($header . "\x00\x00\x00\x00" . $data);
        return $header . pack("V", $checksum) . $data;
    }

    /**
     * Encodes several already encoded chunk bodies into one SCTP packet.
     *
     * SCTP carries multiple chunks per packet by design, and decode() above already reads them back,
     * so bundling only ever concerned the sending side. One packet per chunk meant one DTLS record
     * and one datagram per message, which is the dominant cost of moving a small message.
     *
     * @param string[] $encodedChunks Chunk bodies, each already padded to a 4 byte boundary
     * @return string The binary string representation of the complete SCTP packet
     */
    public static function encodeChunks(int $sourcePort, int $destinationPort, int $verificationTag, array $encodedChunks): string
    {
        $header = pack("nnN", $sourcePort, $destinationPort, $verificationTag);
        $data = implode("", $encodedChunks);
        $checksum = SctpUtility::crc32c($header . "\x00\x00\x00\x00" . $data);
        return $header . pack("V", $checksum) . $data;
    }
}