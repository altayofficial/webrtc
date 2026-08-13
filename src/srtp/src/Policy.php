<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\Srtp;

use FFI;
use FFI\CData;
use Webrtc\Mixin\SharedLibraryInterface;
use Webrtc\Srtp\Enum\SrtpProfile;
use Webrtc\Srtp\Enum\SsrcType;
use Webrtc\Srtp\Exception\SrtpException;
use Webrtc\Srtp\Exception\SrtpValidateException;
use Webrtc\Srtp\Trait\SrtpErrStatusAssertion;

/**
 * Class Policy
 *
 * Represents an SRTP policy for a single stream.
 */
class Policy implements SharedLibraryInterface
{
    use SrtpErrStatusAssertion;

    private FFI $libsrtp;
    private CData $policy;
    private SrtpProfile $srtpProfile;
    /**
     * @var CData|mixed|null
     */
    private CData $storedKey;

    /**
     * Policy constructor.
     *
     * @param string|null $key The SRTP pass key + master salt.
     * @param SsrcType $ssrcType The SSRC type.
     * @param int $ssrcValue The SSRC value.
     * @param SrtpProfile $srtpProfile The SRTP profile.
     * @throws SrtpValidateException|SrtpException if the key is invalid.
     */
    public function __construct(SrtpProfile $srtpProfile = SrtpProfile::AES128_CM_SHA1_80, ?string $key = null, SsrcType $ssrcType = SsrcType::UNDEFINED, int $ssrcValue = 0)
    {
        $this->initiateSharedLibrary();
        $this->policy = $this->libsrtp->new('srtp_policy_t');
        FFI::memset(FFI::addr($this->policy), 0, FFI::sizeof($this->policy));
        $this->srtpProfile = $srtpProfile;
        $this->assertSrtp($this->libsrtp->srtp_crypto_policy_set_from_profile_for_rtp(FFI::addr($this->policy->rtp), $srtpProfile->value));
        $this->assertSrtp($this->libsrtp->srtp_crypto_policy_set_from_profile_for_rtcp(FFI::addr($this->policy->rtcp), $srtpProfile->value));
        $this->setSsrcType($ssrcType);
        $this->setSsrcValue($ssrcValue);
        $this->setKey($key);
    }

    /**
     * Returns whether retransmissions of packets with the same sequence number are allowed.
     *
     * @return bool True if retransmissions are allowed; false otherwise.
     */
    public function getAllowRepeatTx(): bool
    {
        return $this->policy->allow_repeat_tx == 1;
    }

    /**
     * Sets whether retransmissions of packets with the same sequence number are allowed.
     *
     * @param bool $allowRepeatTx True to allow retransmissions; false otherwise.
     */
    public function setAllowRepeatTx(bool $allowRepeatTx): void
    {
        $this->policy->allow_repeat_tx = (int)$allowRepeatTx;
    }

    /**
     * Sets the SRTP key.
     *
     * @param string|null $key The SRTP pass key + master salt.
     * @throws SrtpException
     */
    public function setKey(?string $key = null): void
    {
        if (is_null($key)) {
            $this->policy->key = null;
            return;
        }

        $expectedLength = $this->libsrtp->srtp_profile_get_master_key_length($this->srtpProfile->value) +
            $this->libsrtp->srtp_profile_get_master_salt_length($this->srtpProfile->value);

        if (strlen($key) < $expectedLength) {
            throw new SrtpException("key must contain at least $expectedLength bytes");
        }

        $keyLength = strlen($key);

        // Store the key in a persistent class property
        $this->storedKey = $this->libsrtp->new("uint8_t[$keyLength]", false);

        for ($i = 0; $i < $keyLength; $i++) {
            $this->storedKey[$i] = ord($key[$i]); // Ensure correct byte storage
        }

        $this->policy->key = $this->storedKey; // Assign the persistent reference
    }

    public function getKey(): ?string
    {
        if (FFI::isNull($this->policy->key)) {
            return null;
        }

        $expectedLength = $this->libsrtp->srtp_profile_get_master_key_length($this->srtpProfile->value) +
            $this->libsrtp->srtp_profile_get_master_salt_length($this->srtpProfile->value);

        return FFI::string($this->policy->key, $expectedLength);
    }

    /**
     * Gets the SRTP profile.
     *
     * @return SrtpProfile The SRTP profile.
     */
    public function getSrtpProfile(): SrtpProfile
    {
        return $this->srtpProfile;
    }

    /**
     * Gets the SSRC type.
     *
     * @return SsrcType The SSRC type.
     */
    public function getSsrcType(): SsrcType
    {
        return SsrcType::tryFrom($this->policy->ssrc->type);
    }

    /**
     * Sets the SSRC type.
     *
     * @param SsrcType $ssrcType The SSRC type to set.
     */
    public function setSsrcType(SsrcType $ssrcType): void
    {
        $this->policy->ssrc->type = $ssrcType->value;
    }

    /**
     * Gets the SSRC value.
     *
     * @return int The SSRC value.
     */
    public function getSsrcValue(): int
    {
        return $this->policy->ssrc->value;
    }

    /**
     * Sets the SSRC value.
     *
     * @param int $ssrcValue The SSRC value to set.
     */
    public function setSsrcValue(int $ssrcValue): void
    {
        $this->policy->ssrc->value = $ssrcValue;
    }

    /**
     * Gets the window size
     *
     * @return int
     */
    public function getWindowSize(): int
    {
        return $this->policy->window_size;
    }

    /**
     * Sets the window size
     *
     * @param int $windowSize
     * @return void
     */
    public function setWindowSize(int $windowSize): void
    {
        $this->policy->window_size = $windowSize;
    }

    /**
     * Gets the policy object
     *
     * @return mixed
     */
    public function getPolicy(): CData
    {
        return $this->policy;
    }

    /**
     * @return void
     */
    public function initiateSharedLibrary(): void
    {
        global $libsrtp;

        if ($libsrtp instanceof FFI) {
            $this->libsrtp = $libsrtp;
        }
    }
}