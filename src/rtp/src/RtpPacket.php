<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\RTP;

use Random\RandomException;
use Webrtc\RTP\Exception\RtpPacketException;
use Webrtc\RTP\HeaderExtension\HeaderExtensions;
use Webrtc\RTP\HeaderExtension\HeaderExtensionsMap;

/**
 * Represents an RTP packet with header fields and payload data.
 *
 * This class provides functionality to encode and decode RTP packets according to RFC 3550,
 * including handling of header extensions, CSRC items, and padding. It supports both
 * one-byte and two-byte header extensions as defined in RFC 5285.
 */
class RtpPacket
{
    /**
     * @var int
     */
    private const RTP_HEADER_LENGTH = 12;
    private int $version = 2;
    private int $marker = 0;
    private int $payloadType = 0;
    private int $sequenceNumber = 0;
    private int $timestamp = 0;
    private int $ssrc = 0;
    private array $csrc = [];
    private HeaderExtensions $extensions;
    public string $payload = "";
    private int $paddingSize = 0;
    private ?string $decodedData = null;

    /**
     * Constructs a new RTP packet.
     */
    public function __construct() {
        $this->extensions = new HeaderExtensions();
    }

    /**
     * Decodes a binary string into an RTP packet.
     *
     * @param string $data The binary RTP packet data.
     * @param HeaderExtensionsMap|null $extensionsMap Optional extensions mapping.
     * @return RtpPacket The decoded RTP packet.
     * @throws RtpPacketException If the packet is malformed or truncated.
     */
    public static function decode(string $data, ?HeaderExtensionsMap $extensionsMap = null): RtpPacket
    {
        $extensionsMap = $extensionsMap ?? new HeaderExtensionsMap();

        if (strlen($data) < self::RTP_HEADER_LENGTH) {
            throw new RtpPacketException("RTP packet length is less than " . self::RTP_HEADER_LENGTH . " bytes");
        }

        $header = self::decodeHeader($data);
        $packet = new self();
        $packet->setPayloadType($header['payloadType']);
        $packet->setMarker($header['marker']);
        $packet->setSequenceNumber($header['sequenceNumber']);
        $packet->setTimestamp($header['timestamp']);
        $packet->setSsrc($header['ssrc']);

        $pos = self::decodeCsrc($data, $header['cc'], $packet);

        if ($header['extension']) {
            $pos = self::decodeExtensions($data, $pos, $packet, $extensionsMap);
        }

        self::decodePayloadAndPadding($data, $pos, $header['padding'], $packet);

        return $packet;
    }

    /**
     * Decodes the RTP header fields from binary data.
     *
     * @param string $data The binary RTP packet data.
     * @return array Decoded header fields.
     * @throws RtpPacketException If the version is invalid.
     */
    private static function decodeHeader(string $data): array
    {
        $header = unpack("C1vpxcc/C1mpt/n1sequence/N1timestamp/N1ssrc", substr($data, 0, self::RTP_HEADER_LENGTH));

        $version = $header['vpxcc'] >> 6;
        if ($version !== 2) {
            throw new RtpPacketException("RTP packet has invalid version");
        }

        return [
            'version' => $version,
            'padding' => ($header['vpxcc'] >> 5) & 1,
            'extension' => ($header['vpxcc'] >> 4) & 1,
            'cc' => $header['vpxcc'] & 0x0F,
            'marker' => $header['mpt'] >> 7,
            'payloadType' => $header['mpt'] & 0x7F,
            'sequenceNumber' => $header['sequence'],
            'timestamp' => $header['timestamp'],
            'ssrc' => $header['ssrc']
        ];
    }

    /**
     * Decodes CSRC items from the RTP packet.
     *
     * @param string $data The binary RTP packet data.
     * @param int $cc The CSRC count from the header.
     * @param RtpPacket $packet The packet to populate with CSRC items.
     * @return int The new position in the packet after decoding CSRCs.
     * @throws RtpPacketException If CSRC data is truncated.
     */
    private static function decodeCsrc(string $data, int $cc, RtpPacket $packet): int
    {
        $pos = self::RTP_HEADER_LENGTH;
        for ($i = 0; $i < $cc; $i++) {
            if (strlen($data) < $pos + 4) {
                throw new RtpPacketException("RTP packet has truncated CSRC");
            }
            $packet->csrc[] = unpack("N", substr($data, $pos, 4))[1];
            $pos += 4;
        }
        return $pos;
    }

    /**
     * Decodes header extensions from the RTP packet.
     *
     * @param string $data The binary RTP packet data.
     * @param int $pos The current position in the packet.
     * @param RtpPacket $packet The packet to populate with extensions.
     * @param HeaderExtensionsMap $extensionsMap The extensions mapping.
     * @return int The new position in the packet after decoding extensions.
     * @throws RtpPacketException If extension data is truncated.
     */
    private static function decodeExtensions(string $data, int $pos, RtpPacket $packet, HeaderExtensionsMap $extensionsMap): int
    {
        if (strlen($data) < $pos + 4) {
            throw new RtpPacketException("RTP packet has truncated extension profile/length");
        }

        $extHeader = unpack("n1profile/n1length", substr($data, $pos, 4));
        $extLength = $extHeader['length'] * 4;
        $pos += 4;

        if (strlen($data) < $pos + $extLength) {
            throw new RtpPacketException("RTP packet has truncated extension value");
        }

        $extensionValue = substr($data, $pos, $extLength);
        $pos += $extLength;
        $packet->setExtensions($extensionsMap->get($extHeader['profile'], $extensionValue));

        return $pos;
    }

    /**
     * Decodes the payload and padding from the RTP packet.
     *
     * @param string $data The binary RTP packet data.
     * @param int $pos The current position in the packet.
     * @param int $padding Flag indicating if padding is present.
     * @param RtpPacket $packet The packet to populate with payload.
     * @throws RtpPacketException If padding length is invalid.
     */
    private static function decodePayloadAndPadding(string $data, int $pos, int $padding, RtpPacket $packet): void
    {
        if ($padding) {
            $paddingLen = ord($data[strlen($data) - 1]);
            if ($paddingLen <= 0 || $paddingLen > (strlen($data) - $pos)) {
                throw new RtpPacketException("RTP packet padding length is invalid");
            }
            $packet->paddingSize = $paddingLen;
            $packet->payload = substr($data, $pos, -$paddingLen);
        } else {
            $packet->payload = substr($data, $pos);
        }
    }

    /**
     * Encodes the RTP packet into a binary string.
     *
     * @param HeaderExtensionsMap|null $extensionsMap Optional extensions mapping.
     * @return string The binary RTP packet data.
     *
     * @throws RandomException
     */
    public function encode(?HeaderExtensionsMap $extensionsMap = null): string
    {
        $extensionsMap = $extensionsMap ?? new HeaderExtensionsMap();
        [$extensionProfile, $extensionValue] = $extensionsMap->set($this->extensions);

        $hasExtension = (int)!empty($extensionValue);

        $padding = $this->paddingSize > 0;
        $data = pack(
            "C2nN2",
            ($this->version << 6) | ($padding << 5) | ($hasExtension << 4) | count($this->csrc),
            ($this->marker << 7) | $this->payloadType,
            $this->sequenceNumber,
            $this->timestamp,
            $this->ssrc
        );

        foreach ($this->csrc as $csrc) {
            $data .= pack("N", $csrc);
        }

        if ($hasExtension) {
            $data .= pack("n2", $extensionProfile, strlen($extensionValue) / 4);
            $data .= $extensionValue;
        }

        $data .= $this->payload;

        if ($padding) {
            $data .= str_repeat(chr(random_int(0, 255)), $this->paddingSize - 1);
            $data .= chr($this->paddingSize);
        }

        return $data;
    }

    /**
     * Gets the RTP version number.
     *
     * @return int The RTP version (always 2 for this implementation).
     */
    public function getVersion(): int
    {
        return $this->version;
    }

    /**
     * Gets the marker bit value.
     *
     * @return int The marker bit (1 or 0).
     */
    public function getMarker(): int
    {
        return $this->marker;
    }

    /**
     * Gets the payload type.
     *
     * @return int The payload type identifier.
     */
    public function getPayloadType(): int
    {
        return $this->payloadType;
    }

    /**
     * Gets the sequence number.
     *
     * @return int The 16-bit sequence number.
     */
    public function getSequenceNumber(): int
    {
        return $this->sequenceNumber;
    }

    /**
     * Gets the padding size.
     *
     * @return int The number of padding bytes.
     */
    public function getPaddingSize(): int
    {
        return $this->paddingSize;
    }

    /**
     * Gets the timestamp.
     *
     * @return int The 32-bit timestamp.
     */
    public function getTimestamp(): int
    {
        return $this->timestamp;
    }

    /**
     * Gets the synchronization source (SSRC).
     *
     * @return int The 32-bit SSRC identifier.
     */
    public function getSsrc(): int
    {
        return $this->ssrc;
    }

    /**
     * Gets the contributing sources (CSRC).
     *
     * @return array List of 32-bit CSRC identifiers.
     */
    public function getCsrc(): array
    {
        return $this->csrc;
    }

    /**
     * Gets the header extensions.
     *
     * @return HeaderExtensions The header extensions container.
     */
    public function getExtensions(): HeaderExtensions
    {
        return $this->extensions;
    }

    /**
     * Gets the payload data.
     *
     * @return string The raw payload data.
     */
    public function getPayload(): string
    {
        return $this->payload;
    }

    /**
     * Sets the marker bit.
     *
     * @param int $marker The marker bit value (1 or 0).
     */
    public function setMarker(int $marker): void
    {
        $this->marker = $marker;
    }

    /**
     * Sets the payload type.
     *
     * @param int $payloadType The payload type identifier.
     */
    public function setPayloadType(int $payloadType): void
    {
        $this->payloadType = $payloadType;
    }

    /**
     * Sets the sequence number.
     *
     * @param int $sequenceNumber The 16-bit sequence number.
     */
    public function setSequenceNumber(int $sequenceNumber): void
    {
        $this->sequenceNumber = $sequenceNumber;
    }

    /**
     * Sets the timestamp.
     *
     * @param int $timestamp The 32-bit timestamp.
     */
    public function setTimestamp(int $timestamp): void
    {
        $this->timestamp = $timestamp;
    }

    /**
     * Sets the synchronization source (SSRC).
     *
     * @param int $ssrc The 32-bit SSRC identifier.
     */
    public function setSsrc(int $ssrc): void
    {
        $this->ssrc = $ssrc;
    }

    /**
     * Sets the header extensions.
     *
     * @param HeaderExtensions $extensions The header extensions container.
     */
    public function setExtensions(HeaderExtensions $extensions): void
    {
        $this->extensions = $extensions;
    }

    /**
     * Sets the contributing sources (CSRC).
     *
     * @param array $csrc List of 32-bit CSRC identifiers.
     */
    public function setCsrc(array $csrc): void
    {
        $this->csrc = $csrc;
    }

    /**
     * Sets the payload data.
     *
     * @param string $payload The raw payload data.
     */
    public function setPayload(string $payload): void
    {
        $this->payload = $payload;
    }

    /**
     * Returns a string representation of the RTP packet.
     *
     * @return string Formatted string with key packet fields.
     */
    public function __toString(): string {
        return sprintf(
            "RtpPacket(seq=%d, ts=%d, marker=%d, payload=%d, %d bytes)",
            $this->sequenceNumber,
            $this->timestamp,
            $this->marker,
            $this->payloadType,
            strlen($this->payload)
        );
    }

    /**
     * Sets the decoded payload data.
     *
     * This is typically used to store the decoded media data after processing.
     *
     * @param string $decodedData The decoded payload data.
     */
    public function setDecodedData(string $decodedData): void
    {
        $this->decodedData = $decodedData;
    }

    /**
     * Gets the decoded payload data.
     *
     * @return string|null The decoded payload data or null if not set.
     */
    public function getDecodedData(): ?string
    {
        return $this->decodedData;
    }
}