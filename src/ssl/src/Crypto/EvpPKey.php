<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\SSL\Crypto;

use FFI;
use FFI\CData;
use Webrtc\Mixin\SharedLibraryInterface;
use Webrtc\SSL\Exception\OpenSSLException;

/**
 * EVP_PKEY wrapper class for public/private key operations
 *
 * This class provides a PHP interface to OpenSSL's EVP_PKEY structure,
 * which is a generic container for asymmetric keys. It implements
 * SharedLibraryInterface for FFI library management.
 */
class EvpPKey implements SharedLibraryInterface
{
    /** @var CData FFI CData structure representing the EVP_PKEY */
    private CData $pkey;

    /** @var FFI Reference to the OpenSSL FFI library */
    private FFI $libssl;

    /**
     * EvpPKey constructor
     *
     * Initializes a new EVP_PKEY structure
     *
     * @throws OpenSSLException If the EVP_PKEY structure cannot be created
     */
    public function __construct()
    {
        $this->initiateSharedLibrary();
        $this->pkey = $this->libssl->EVP_PKEY_new();
        if ($this->pkey === null) {
            throw new OpenSSLException("Failed to create EVP_PKEY");
        }
    }

    /**
     * Assigns a key to the EVP_PKEY structure
     *
     * Associates a specific key (e.g., EC key, RSA key) with this EVP_PKEY container
     *
     * @param int $type The key type (e.g., EVP_PKEY_EC, EVP_PKEY_RSA)
     * @param CData $key The key to assign (as FFI CData)
     * @throws OpenSSLException If the assignment fails
     */
    public function assign(int $type, CData $key): void
    {
        $ret = $this->libssl->EVP_PKEY_assign($this->pkey, $type, $key);
        if ($ret !== 1) {
            throw new OpenSSLException("Failed to assign EC key to EVP_PKEY");
        }
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
     * Gets the underlying EVP_PKEY structure
     *
     * @return CData The FFI CData structure representing the EVP_PKEY
     */
    public function getPkey(): CData
    {
        return $this->pkey;
    }
}