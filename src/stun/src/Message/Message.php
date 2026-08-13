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

use Random\RandomException;
use Webrtc\Exception\InvalidArgumentException;
use Webrtc\STUN\Enum\MessageAttribute;
use Webrtc\STUN\Enum\MessageClass;
use Webrtc\STUN\Enum\MessageMethod;
use Webrtc\STUN\Utils;

/**
 * Class Message
 * Represents a WebRTC message with attributes.
 *
 * @link https://datatracker.ietf.org/doc/html/rfc5389#page-10
 */
class Message implements MessageInterface
{
    private string $transactionId;
    private MessageAttributeCollection $_attributes;

    /**
     * Message constructor.
     *
     * @param MessageClass $messageClass
     * @param MessageMethod $messageMethod
     * @throws RandomException
     */
    public function __construct(private readonly MessageClass $messageClass, private readonly MessageMethod $messageMethod)
    {
        $this->transactionId = random_bytes(12);
        $this->_attributes = new MessageAttributeCollection;
    }

    /**
     * Get the message attributes.
     *
     * @return MessageAttributeCollection
     */
    public function attributes(): MessageAttributeCollection
    {
        return $this->_attributes;
    }

    /**
     * Get the transaction ID.
     *
     * @return string
     */
    public function getTransactionId(): string
    {
        return $this->transactionId;
    }

    /**
     * @return MessageClass
     */
    public function getMessageClass(): MessageClass
    {
        return $this->messageClass;
    }

    /**
     * @return MessageMethod
     */
    public function getMessageMethod(): MessageMethod
    {
        return $this->messageMethod;
    }

    /**
     * Set the transaction ID.
     *
     * @param string $transactionId
     */
    public function setTransactionId(string $transactionId): void
    {
        $this->transactionId = $transactionId;
    }

    /**
     * Add message integrity attributes.
     *
     * @param string $key The key for HMAC.
     */
    public function addMessageIntegrity(string $key): void
    {
        $this->_attributes->add(MessageAttribute::MESSAGE_INTEGRITY, MessageIntegrity::messageIntegrity($this->encode(), $key));
        $this->_attributes->add(MessageAttribute::FINGERPRINT, MessageIntegrity::messageFingerprint($this->encode()));
    }

    /**
     * Encode the message to bytes.
     *
     * @return string
     */
    public function encode(): string
    {
        $encodedAttributes = $this->getEncodedAttributes();
        return pack(
                'nnNa12',
                $this->messageMethod->value | $this->messageClass->value,
                strlen($encodedAttributes),
                MessageAttributeEncoder::COOKIE,
                $this->transactionId
            ) . $encodedAttributes;
    }

    /**
     * Encode the attributes message to bytes
     *
     * @return string
     */
    private function getEncodedAttributes(): string
    {
        $data = '';
        foreach ($this->_attributes as $attrName => $attrValue) {
            [$attrType, $packedData] = MessageAttributeEncoder::encode($attrName, $attrValue, $this->transactionId);
            $attrLen = strlen($packedData ?? "");
            $padLen = Utils::paddingLength($attrLen);
            $data .= pack('nn', $attrType, $attrLen) . $packedData . str_repeat("\0", $padLen);
        }

        return $data;
    }

    /**
     * Decode a message from bytes.
     *
     * @param string $data The message data.
     * @param ?string $integrityKey Optional integrity key for HMAC validation.
     * @return static
     * @throws InvalidArgumentException If the message is invalid.
     * @throws RandomException
     */
    public static function decode(string $data, ?string $integrityKey = null): static
    {
        if (strlen($data) < MessageIntegrity::HEADER_LENGTH) {
            throw new InvalidArgumentException("STUN message length is less than 20 bytes");
        }

        [$messageType, $length, , $transactionId] = array_values(unpack('nmessageType/nlength/Ncookie/a12transactionId', substr($data, 0, MessageIntegrity::HEADER_LENGTH)));

        if (strlen($data) !== MessageIntegrity::HEADER_LENGTH + $length) {
            throw new InvalidArgumentException("STUN message length does not match");
        }

        if (!($class = MessageClass::tryFrom($messageType & 0x0110) )|| !($method = MessageMethod::tryFrom($messageType & 0x3EEF))) {
            throw new InvalidArgumentException("STUN message type does not match");
        }

        $message = new static($class, $method);
        $message->setTransactionId($transactionId);
        $message->attributes()->merge(self::getDecodedAttributes($data, $transactionId, $integrityKey));

        return $message;
    }

    /**
     * Decode the attribute message from bytes
     *
     * @param string $data
     * @param string $transactionId
     * @param string|null $integrityKey
     * @return array
     */
    private static function getDecodedAttributes(string $data, string $transactionId, ?string $integrityKey): array
    {
        $attributes = [];
        $pos = MessageIntegrity::HEADER_LENGTH;

        while ($pos <= strlen($data) - 4) {

            [$attrType, $attrLen] = array_values(unpack('nattrType/nattrLen', substr($data, $pos, 4)));

            $value = substr($data, $pos + 4, $attrLen);
            $padLen = Utils::paddingLength($attrLen);

            try {
                [$attrName, $unpackedData] = MessageAttributeEncoder::decode($attrType, $value, $transactionId);
                $attributes[$attrName] = $unpackedData;
            } catch (InvalidArgumentException $e) {
                $attrName = '';
            }

            if ($attrName === MessageAttribute::FINGERPRINT->name && $attributes[$attrName] !== MessageIntegrity::messageFingerprint(substr($data, 0, $pos))) {
                throw new InvalidArgumentException("STUN message fingerprint does not match");
            }

            if ($attrName === MessageAttribute::MESSAGE_INTEGRITY->name && $integrityKey !== null
                && $attributes[$attrName] !== MessageIntegrity::messageIntegrity(substr($data, 0, $pos), $integrityKey)) {
                throw new InvalidArgumentException("STUN message integrity does not match");
            }

            $pos += 4 + $attrLen + $padLen;
        }

        return $attributes;
    }

    /**
     * Create a new message.
     *
     * @param MessageClass $messageClass The message class.
     * @param MessageMethod $messageMethod The message method.
     * @param array $attributes The message attributes.
     * @param string|null $integrityKey
     * @return Message The Message object.
     * @throws RandomException
     */
    public static function new(MessageClass $messageClass, MessageMethod $messageMethod, array $attributes = [], ?string $integrityKey = null): MessageInterface
    {
        $message = new static($messageClass, $messageMethod);
        $message->attributes()->merge($attributes);
        if ($integrityKey) {
            $message->addMessageIntegrity($integrityKey);
        }

        return $message;
    }

    /**
     * @return string
     */
    public function __toString(): string
    {
        return $this->encode();
    }

    /**
     * Convert a Message object to a human-readable message
     *
     * @return string
     */
    public function humanReadable(): string
    {
        $info = [
            "ID: " . bin2hex($this->transactionId),
            "Class: {$this->messageClass->name}",
            "Method: {$this->messageMethod->name}",
            "Attributes: " . $this->getAttributesInfo()
        ];

        return implode(" - ", $info);
    }

    /**
     * Convert attributes to human-readable
     *
     * @return string
     */
    private function getAttributesInfo(): string
    {
        if(count($this->attributes()) === 0) {
            return "No Attributes";
        }

        $attr = '[';

        foreach ($this->attributes()->all() as $attrName => $attrVal) {
            $attrVal = is_array($attrVal) ? "(" . implode(", ", $attrVal) . ")" : $attrVal;
            if (in_array($attrName, ["MESSAGE_INTEGRITY"])){
                $attrVal = bin2hex($attrVal);
            }
            $attr .= $attrName . ": " . $attrVal . ", ";
        }

        return substr($attr, 0, -2) . "]";
    }
}