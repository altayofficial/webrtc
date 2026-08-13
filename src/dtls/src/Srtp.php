<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\DTLS;

use Webrtc\DTLS\Exception\DTLSException;
use Webrtc\Srtp\Enum\SrtpProfile;
use Webrtc\Srtp\Enum\SsrcType;
use Webrtc\Srtp\Exception\SrtpException;
use Webrtc\Srtp\Exception\SrtpValidateException;
use Webrtc\Srtp\Policy;
use Webrtc\Srtp\Session;
use Webrtc\Srtp\Srtp as libSrtp;

/**
 * Srtp Class
 *
 * Provides a wrapper for Secure Real-time Transport Protocol (SRTP) functionality within
 * WebRTC DTLS implementation. This class handles the management of SRTP profiles, session
 * creation, and key material generation for secure media transmission in WebRTC connections.
 *
 * This class supports multiple SRTP cryptographic profiles, manages inbound and outbound
 * sessions, and handles key derivation from DTLS key material.
 */
class Srtp
{
    /**
     * The Size of the replay protection window for SRTP packets
     * Helps prevent replay attacks by tracking previously received packets
     * @var int
     */
    private const WINDOW_SIZE = 1024;

    /**
     * Predefined set of supported SRTP cryptographic profiles
     *
     * Each profile includes:
     * - srtpProfile: The SRTP profile enum value from SrtpProfile
     * - sslProfile: The corresponding SSL profile string identifier
     * - keyLength: Length of the encryption key in bytes
     * - saltLent: Length of the salt value in bytes
     * @var mixed[]
     */
    public const DEFAULT_PROFILES = [
        [
            "srtpProfile" => SrtpProfile::AEAD_AES_256_GCM,
            "sslProfile" => "SRTP_AEAD_AES_256_GCM",
            "keyLength" => 32,
            "saltLent" => 12
        ],
        [
            "srtpProfile" => SrtpProfile::AEAD_AES_128_GCM,
            "sslProfile" => "SRTP_AEAD_AES_128_GCM",
            "keyLength" => 16,
            "saltLent" => 12
        ],
        [
            "srtpProfile" => SrtpProfile::AES128_CM_SHA1_80,
            "sslProfile" => "SRTP_AES128_CM_SHA1_80",
            "keyLength" => 16,
            "saltLent" => 14
        ]
    ];

    /**
     * The currently selected SRTP profile configuration
     *
     * @var array
     */
    private array $profile;

    /**
     * Initializes the SRTP library and creates a new Srtp instance
     *
     * @throws SrtpException If the underlying SRTP library fails to initialize
     */
    public function __construct()
    {
        libSrtp::init();
    }

    /**
     * Verifies if a specific SRTP profile is available and supported by the current system
     *
     * Attempts to create a Policy with the specified profile to check its availability
     *
     * @param array $profile Array containing profile configuration details
     * @return bool True if the profile is available, false otherwise
     */
    private static function checkAvailabilityProfile(array $profile): bool
    {
        try {
            new Policy($profile["srtpProfile"]);
            return true;
        } catch (SrtpValidateException) {
            return false;
        }
    }

    /**
     * Retrieves all available SRTP profiles supported by the current system
     *
     * Iterates through DEFAULT_PROFILES and filters only those supported by the system
     *
     * @return array List of available SRTP profiles
     * @throws SrtpException If the SRTP library fails to initialize
     */
    public static function getProfiles(): array
    {
        libSrtp::init();
        $profiles = [];
        foreach (self::DEFAULT_PROFILES as $profile) {
            if (self::checkAvailabilityProfile($profile)) {
                $profiles [] = $profile;
            }
        }

        return $profiles;
    }

    /**
     * Retrieves and sets the profile configuration for a specified SRTP profile
     *
     * Searches through available profiles to find one matching the provided SSL profile name
     * and set it as the current profile if found
     *
     * @param string $selectedSrtpProfile The SSL profile name to search for
     * @return array|false The matched profile configuration or false if not found
     * @throws SrtpException If the SRTP library fails to initialize during profile retrieval
     */
    public function getProfile(string $selectedSrtpProfile): array|bool
    {
        $profiles = self::getProfiles();
        foreach ($profiles as $profile) {
            if ($profile["sslProfile"] === $selectedSrtpProfile) {
                $this->profile = $profile;
                return $profile;
            }
        }

        return false;
    }

    /**
     * Creates an inbound SRTP session using provided key material
     *
     * @param string $srtpKeyMaterial Raw key material from DTLS handshake
     * @param int $index Index for key derivation (0 for client, 1 for server)
     * @return Session The initialized inbound SRTP session
     * @throws DTLSException If the profile is not set or key generation fails
     * @throws SrtpValidateException If the SRTP session creation fails
     */
    public function getInbound(string $srtpKeyMaterial, int $index): Session
    {
        return $this->getSession($srtpKeyMaterial, $index, SsrcType::ANY_INBOUND);
    }

    /**
     * Creates an outbound SRTP session using provided key material
     *
     * @param string $srtpKeyMaterial Raw key material from DTLS handshake
     * @param int $index Index for key derivation (0 for client, 1 for server)
     * @return Session The initialized outbound SRTP session
     * @throws DTLSException If the profile is not set or key generation fails
     * @throws SrtpValidateException If the SRTP session creation fails
     */
    public function getOutbound(string $srtpKeyMaterial, int $index): Session
    {
        return $this->getSession($srtpKeyMaterial, $index, SsrcType::ANY_OUTBOUND);
    }

    /**
     * Generates an SRTP key from the raw key material
     *
     * Extracts the key and salt components from the key material based on profile settings
     * and the specified index. Concatenates them to form the final key.
     *
     * @param string $srtpKeyMaterial Raw key material from DTLS handshake
     * @param int $index Index for key derivation (0 for client, 1 for server)
     * @return string The generated SRTP key
     * @throws DTLSException If the profile is not set
     */
    public function generateKey(string $srtpKeyMaterial, int $index): string
    {
        if (!$this->profile) {
            throw new DTLSException("Profile is not set");
        }

        $keyStart = $index * $this->profile["keyLength"];
        $saltStart = 2 * $this->profile["keyLength"] + $index * $this->profile["saltLent"];

        return substr($srtpKeyMaterial, $keyStart, $this->profile["keyLength"]) . substr($srtpKeyMaterial, $saltStart, $this->profile["saltLent"]);
    }

    /**
     * Creates an SRTP session of the specified type using provided key material
     *
     * Common implementation for both inbound and outbound session creation
     *
     * @param string $srtpKeyMaterial Raw key material from DTLS handshake
     * @param int $index Index for key derivation (0 for client, 1 for server)
     * @param SsrcType $ssrcType The type of session (inbound or outbound)
     * @return Session The initialized SRTP session
     * @throws DTLSException If the profile is not set or key generation fails
     * @throws SrtpValidateException If the SRTP session creation fails
     */
    private function getSession(string $srtpKeyMaterial, int $index, SsrcType $ssrcType): Session
    {
        $key = $this->generateKey($srtpKeyMaterial, $index);

        $policy = new Policy($this->profile["srtpProfile"], $key, $ssrcType);
        $policy->setAllowRepeatTx(true);
        $policy->setWindowSize(self::WINDOW_SIZE);

        return new Session($policy);
    }

    /**
     * Sets the active SRTP profile configuration
     *
     * @param array $profile The profile configuration to set
     * @return void
     */
    public function setProfile(array $profile): void
    {
        $this->profile = $profile;
    }
}