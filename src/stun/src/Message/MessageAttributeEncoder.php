<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\STUN\Message;

use Webrtc\Exception\InvalidArgumentException;
use Webrtc\STUN\Enum\MessageAttribute;

/**
 * Class to handle encoding and decoding of WebRTC message attributes.
 *
 */
class MessageAttributeEncoder
{
    /**
     * @var int
     */
    public const COOKIE = 0x2112A442;
    /**
     * @var int
     */
    private const IPV4_PROTOCOL = 1;
    /**
     * @var int
     */
    private const IPV6_PROTOCOL = 2;

    /**
     * @var mixed[]
     */
    private const ATTRIBUTES = [
        [MessageAttribute::MAPPED_ADDRESS, 'packAddress', 'unpackAddress'],
        [MessageAttribute::CHANGE_REQUEST, 'packUnsigned', 'unpackUnsigned'],
        [MessageAttribute::SOURCE_ADDRESS, 'packAddress', 'unpackAddress'],
        [MessageAttribute::CHANGED_ADDRESS, 'packAddress', 'unpackAddress'],
        [MessageAttribute::USERNAME, 'returnValue', 'returnValue'],
        [MessageAttribute::MESSAGE_INTEGRITY, 'returnValue', 'returnValue'],
        [MessageAttribute::ERROR_CODE, 'packErrorCode', 'unpackErrorCode'],
        [MessageAttribute::CHANNEL_NUMBER, 'packUnsignedShort', 'unpackUnsignedShort'],
        [MessageAttribute::LIFETIME, 'packUnsigned', 'unpackUnsigned'],
        [MessageAttribute::XOR_PEER_ADDRESS, 'packXorAddress', 'unpackXorAddress'],
        [MessageAttribute::REALM, 'returnValue', 'returnValue'],
        [MessageAttribute::NONCE, 'returnValue', 'returnValue'],
        [MessageAttribute::XOR_RELAYED_ADDRESS, 'packXorAddress', 'unpackXorAddress'],
        [MessageAttribute::REQUESTED_TRANSPORT, 'packUnsigned', 'unpackUnsigned'],
        [MessageAttribute::XOR_MAPPED_ADDRESS, 'packXorAddress', 'unpackXorAddress'],
        [MessageAttribute::PRIORITY, 'packUnsigned', 'unpackUnsigned'],
        [MessageAttribute::USE_CANDIDATE, 'returnNull', 'returnNull'],
        [MessageAttribute::SOFTWARE, 'returnValue', 'returnValue'],
        [MessageAttribute::FINGERPRINT, 'packUnsigned', 'unpackUnsigned'],
        [MessageAttribute::ICE_CONTROLLED, 'packUnsigned64', 'unpackUnsigned64'],
        [MessageAttribute::ICE_CONTROLLING, 'packUnsigned64', 'unpackUnsigned64'],
        [MessageAttribute::RESPONSE_ORIGIN, 'packAddress', 'unpackAddress'],
        [MessageAttribute::OTHER_ADDRESS, 'packAddress', 'unpackAddress']
    ];

    private array $attributesByType = [];
    private array $attributesByName = [];

    /**
     * MessageAttributeEncoder constructor.
     *
     * @param string|array|null $data The message data.
     * @param string $transactionId The transaction ID.
     */
    public function __construct(private readonly string|array|null $data, private readonly string $transactionId)
    {
        foreach (self::ATTRIBUTES as $attr) {
            $this->attributesByType[$attr[0]->value] = $attr;
            $this->attributesByName[$attr[0]->name] = $attr;
        }
    }

    /**
     * Get attributes by type.
     *
     * @param int $type The attribute type.
     * @return array|null The attribute array or null if not found.
     */
    public function getAttributesByType(int $type): ?array
    {
        return $this->attributesByType[$type] ?? null;
    }

    /**
     * Get attributes by name.
     *
     * @param string $name The attribute name.
     * @return array|null The attribute array or null if not found.
     */
    public function getAttributesByName(string $name): ?array
    {
        return $this->attributesByName[$name] ?? null;
    }

    /**
     * Return the value of the attribute as is.
     *
     * @return string The attribute value.
     */
    public function returnValue(): string
    {
        return $this->data;
    }

    /**
     * Return null for the attribute value.
     *
     * @return null|string
     */
    public function returnNull(): ?string
    {
        return null;
    }

    /**
     * Perform XOR operation on the address.
     *
     * @param string|null $data The address data.
     * @return string The XORed address.
     */
    public function xorAddress(?string $data = null): string
    {
        $data = $data ?? $this->data;
        $xpad = pack('nN', self::COOKIE >> 16, self::COOKIE) . $this->transactionId;
        $xdata = substr($data, 0, 2);
        for ($i = 2, $len = strlen($data); $i < $len; $i++) {
            $xdata .= chr(ord($data[$i]) ^ ord($xpad[$i - 2]));
        }
        return $xdata;
    }

    /**
     * Pack the address.
     *
     * @return string The packed address.
     */
    public function packAddress(): string
    {
        $ip = $this->data[0];
        $port = $this->data[1];
        $ipAddress = inet_pton($ip);
        if (!$ipAddress) {
            throw new InvalidArgumentException("'$ip' does not appear to be an IPv4 or IPv6 address");
        }
        $protocol = !str_contains($ip, ':') ? self::IPV4_PROTOCOL : self::IPV6_PROTOCOL;
        return pack('C2n', 0, $protocol, $port) . $ipAddress;
    }

    /**
     * Unpack the address.
     *
     * @param string|null $data The packed address data.
     * @return array The unpacked address.
     * @throws InvalidArgumentException If the data is invalid.
     */
    public function unpackAddress(?string $data = null): array
    {
        $data = $data ?? $this->data;

        if (strlen($data) < 4) {
            throw new InvalidArgumentException("STUN address length is less than 4 bytes");
        }
        [, $protocol, $port] = array_values(unpack('Creserved/Cprotocol/nport', substr($data, 0, 4)));

        $address = substr($data, 4);

        if ($protocol === self::IPV4_PROTOCOL) {
            if (strlen($address) !== 4) {
                throw new InvalidArgumentException("STUN address has invalid length for IPv4");
            }
            return [inet_ntop($address), $port];
        } elseif ($protocol === self::IPV6_PROTOCOL) {
            if (strlen($address) !== 16) {
                throw new InvalidArgumentException("STUN address has invalid length for IPv6");
            }
            return [inet_ntop($address), $port];
        } else {
            throw new InvalidArgumentException("STUN address has unknown protocol");
        }
    }

    /**
     * Pack the XORed address.
     *
     * @return string The packed XORed address.
     */
    public function packXorAddress(): string
    {
        return $this->xorAddress($this->packAddress());
    }

    /**
     * Unpack the XORed address.
     *
     * @return array The unpacked XORed address.
     * @throws InvalidArgumentException If the data is invalid.
     */
    public function unpackXorAddress(): array
    {
        return $this->unpackAddress($this->xorAddress());
    }

    /**
     * Pack the error code.
     *
     * @return string The packed error code.
     */
    public function packErrorCode(): string
    {
        return pack('nCC', 0, intdiv($this->data[0], 100), $this->data[0] % 100) . $this->data[1];
    }

    /**
     * Unpack the error code.
     *
     * @return array The unpacked error code and reason.
     * @throws InvalidArgumentException If the data is invalid.
     */
    public function unpackErrorCode(): array
    {

        if (strlen($this->data) < 4) {
            throw new InvalidArgumentException("STUN error code is less than 4 bytes");
        }
        [, $codeHigh, $codeLow] = array_values(unpack('nreserved/CcodeHigh/CcodeLow', substr($this->data, 0, 4)));
        $reason = substr($this->data, 4);
        return [$codeHigh * 100 + $codeLow, $reason];
    }

    /**
     * Pack an unsigned integer.
     *
     * @return string The packed unsigned integer.
     */
    public function packUnsigned(): string
    {
        return pack('N', $this->data);
    }

    /**
     * Unpack an unsigned integer.
     *
     * @return int The unpacked unsigned integer.
     */
    public function unpackUnsigned(): int
    {
        return unpack('N', $this->data)[1];
    }

    /**
     * Pack an unsigned short integer.
     *
     * @return string The packed unsigned short integer.
     */
    public function packUnsignedShort(): string
    {
        return pack('n', $this->data) . "\x00\x00";
    }

    /**
     * Unpack an unsigned short integer from the data.
     *
     * @return int The unpacked unsigned short integer.
     */
    public function unpackUnsignedShort(): int
    {
        return unpack('n', substr($this->data, 0, 2))[0];
    }

    /**
     * Pack a 64-bit unsigned integer into a string.
     *
     * @return string The packed 64-bit unsigned integer.
     */
    public function packUnsigned64(): string
    {
        $data = gmp_init($this->data, 10);
        $high = gmp_intval(gmp_div_q($data, gmp_init('0x100000000')));
        $low = gmp_intval(gmp_mod($data, gmp_init('0x100000000')));
        return pack('NN', $high, $low);
    }

    /**
     * Unpack a 64-bit unsigned integer from the data.
     *
     * @return string The unpacked 64-bit unsigned integer as a string.
     */
    public function unpackUnsigned64(): string
    {
        $result = unpack('N2', $this->data);
        $high = gmp_init($result[1]);
        $low = gmp_init($result[2]);
        return gmp_strval(gmp_add(gmp_mul($high, gmp_init('0x100000000')), $low));
    }

    /**
     * Encode an attribute by name.
     *
     * @param string $attrName The name of the attribute to encode.
     * @param ?string|array $data The data to encode.
     * @param string $transactionId The transaction ID.
     * @return array The encoded attribute type and value.
     */
    public static function encode(string $attrName, string|array|null $data, string $transactionId): array
    {
        return self::getEncode($attrName, $data, $transactionId);
    }

    /**
     * Decode an attribute by type.
     *
     * @param string $attrType The type of the attribute to decode.
     * @param string $data The data to decode.
     * @param string $transactionId The transaction ID.
     * @return array The decoded attribute type and value.
     */
    public static function decode(string $attrType, string $data, string $transactionId): array
    {
        return self::getEncode($attrType, $data, $transactionId, true);
    }

    /**
     * Get the encoded or decoded attribute.
     *
     * @param string $attr The attribute to encode or decode.
     * @param string|array|null $data The data to encode or decode.
     * @param string $transactionId The transaction ID.
     * @param bool $decode Flag to indicate decoding.
     * @return array The attribute type and encoded or decoded value.
     */
    private static function getEncode(string $attr, string|array|null $data, string $transactionId, bool $decode = false): array
    {
        $encoder = new static($data, $transactionId);
        $attr = $encoder->{$decode ? 'getAttributesByType' : 'getAttributesByName'}($attr);

        if (!$attr) {
            throw new InvalidArgumentException("Invalid attribute name: $attr");
        }

        $attrType = $attr[0]->{$decode ? 'name' : 'value'};
        $funcName = $attr[$decode ? 2 : 1];

        return [$attrType, call_user_func([$encoder, $funcName])];
    }
}