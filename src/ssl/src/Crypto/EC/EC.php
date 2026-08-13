<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\SSL\Crypto\EC;

use FFI;
use FFI\CData;
use Webrtc\Mixin\SharedLibraryInterface;
use Webrtc\SSL\Crypto\EvpPKey;
use Webrtc\SSL\Crypto\PrivateKeyInterface;
use Webrtc\SSL\Enum\ECCurveName;
use Webrtc\SSL\Exception\OpenSSLException;

/**
 * Elliptic Curve (EC) key generation and management class
 *
 * This class provides functionality for creating and managing Elliptic Curve cryptographic keys
 * using OpenSSL's EC functions through FFI. It implements both SharedLibraryInterface for FFI
 * library management and PrivateKeyInterface for key operations.
 */
class EC implements SharedLibraryInterface, PrivateKeyInterface
{
    /** @var FFI Reference to the OpenSSL FFI library */
    private FFI $libssl;

    /** @var CData FFI CData structure representing the EC_KEY */
    private CData $ecKey;

    /** @var EvpPKey Wrapper for the EVP_PKEY structure containing the EC key */
    private EvpPKey $pkey;

    /**
     * EC constructor
     *
     * Initializes a new EC key context for the specified curve
     *
     * @param ECCurveName $name The elliptic curve to use for key generation
     * @throws OpenSSLException If the EC key context cannot be created
     */
    public function __construct(ECCurveName $name)
    {
        $this->initiateSharedLibrary();
        $this->ecKey = $this->libssl->EC_KEY_new_by_curve_name($name->value);
        if (FFI::isNull($this->ecKey)) {
            throw new OpenSSLException("Failed to create EC key");
        }
    }

    /**
     * Generates a new EC key pair
     *
     * Creates a public/private key pair for the elliptic curve specified in the constructor
     * and wraps it in an EvpPKey object
     *
     * @return EvpPKey The generated key pair
     * @throws OpenSSLException If key generation fails
     */
    public function generate(): EvpPKey
    {
        $ret = $this->libssl->EC_KEY_generate_key($this->ecKey);
        if ($ret !== 1) {
            throw new OpenSSLException("Failed to generate EC key");
        }

        $this->pkey = new EvpPKey();
        $this->pkey->assign(EVP_PKEY_EC, $this->ecKey);

        return $this->pkey;
    }

    /**
     * Initializes the shared OpenSSL library reference
     *
     * Implements the SharedLibraryInterface by setting the libssl FFI instance
     * from the global $libssl variable
     */
    public function initiateSharedLibrary(): void
    {
        global $libssl;

        if ($libssl instanceof FFI) {
            $this->libssl = $libssl;
        }
    }

    /**
     * Gets the EVP_PKEY wrapper containing the EC key
     *
     * Implements the PrivateKeyInterface by providing access to the generated key
     *
     * @return EvpPKey The EVP_PKEY wrapper containing the EC key
     */
    public function getPKey(): EvpPKey
    {
        return $this->pkey;
    }

    /**
     * Destructor
     *
     * Cleans up the EC key resources. Note: Currently commented out due to potential
     * issues (see FIXME comment)
     */
    public function __destruct()
    {
        // FIXME:
        // $this->libssl->EC_KEY_free($this->ecKey);
    }
}