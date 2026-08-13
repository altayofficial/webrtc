<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\SSL;

use FFI;
use FFI\Exception as FFIException;
use Webrtc\SSL\Exception\OpenSSLException;

/**
 * Class OpenSSL
 *
 * This class provides methods to initialize the OpenSSL library and handle
 * SSL-related operations using PHP FFI.
 */
class OpenSSL
{
    /**
     * The path to the OpenSSL C header file.
     * @var string
     */
    private const HEADER_FILE_PATH = __DIR__ . "/libssl/include/ssl.h";

    /**
     * Initializes the OpenSSL library and returns an FFI instance.
     *
     * @throws OpenSSLException if the SSL library initialization fails.
     */
    public static function init(): void
    {
        global $libssl;

        if (!isset($libssl)) {
            try {
                $lib = getenv("LIBSSL_PATH") ?: self::getLibPath();
                $libssl = FFI::cdef(file_get_contents(self::HEADER_FILE_PATH), $lib);

                if (!$libssl) {
                    throw new OpenSSLException("FFI failed to load OpenSSL shared library.");
                }

                self::setDefinition();

            } catch (FFIException $e) {
                $os = PHP_OS_FAMILY;
                $installHint = match ($os) {
                    'Windows' => <<<EOT
Download and install OpenSSL for Windows:

You can get precompiled binaries from:

    https://slproweb.com/products/Win32OpenSSL.html

After installation, ensure the libssl-*.dll is available in your PATH
or specify the LIBSSL_PATH environment variable pointing to the .dll.
EOT,
                    'Darwin' => <<<EOT
Install OpenSSL on macOS using Homebrew:

    brew install openssl

To link OpenSSL (if needed):

    brew link openssl --force

If you encounter linking issues, set PKG_CONFIG_PATH manually:

    export PKG_CONFIG_PATH="/usr/local/opt/openssl/lib/pkgconfig"

EOT,
                    'Linux' => <<<EOT
Install OpenSSL development packages on Linux.

For Debian/Ubuntu:

    sudo apt update
    sudo apt install libssl-dev

For Fedora/RHEL:

    sudo dnf install openssl-devel

If OpenSSL is too old, consider compiling a newer version manually from:

    https://www.openssl.org/source/
EOT,
                    default => "Please install OpenSSL development libraries (headers and shared libs). See https://www.openssl.org/ for official instructions."
                };

                throw new OpenSSLException(sprintf(
                    "Couldn't load OpenSSL library: %s\n\nInstallation instructions:\n%s",
                    $e->getMessage(),
                    $installHint
                ), $e->getCode(), $e);
            }
        }
    }

    /**
     * Determines and returns the appropriate OpenSSL shared library path.
     *
     * @return string
     */
    private static function getLibPath(): string
    {
        $os = PHP_OS_FAMILY;

        if ($os === 'Windows') {
            $candidates = [
                'libssl-3-x64.dll', // OpenSSL 3.x (64bit)
                'libssl-1_1-x64.dll', // OpenSSL 1.1 (64bit)
                'libssl.dll',
            ];
        } elseif ($os === 'Darwin') { // macOS
            $candidates = [
                '/usr/local/opt/openssl/lib/libssl.dylib',
                '/usr/local/lib/libssl.dylib',
                '/opt/homebrew/opt/openssl/lib/libssl.dylib',
                'libssl.dylib',
            ];
        } elseif ($os === 'Linux') {
            $candidates = [
                '/usr/lib/x86_64-linux-gnu/libssl.so',
                '/usr/local/lib/libssl.so',
                'libssl.so',
            ];
        } else {
            $candidates = [
                'libssl',
            ];
        }

        foreach ($candidates as $candidate) {
            if (is_file($candidate) || @file_exists($candidate)) {
                return $candidate;
            }
        }

        return match ($os) {
            'Windows' => 'libssl.dll',
            'Darwin' => 'libssl.dylib',
            'Linux' => 'libssl.so',
            default => 'libssl',
        };
    }

    /**
     * Defines OpenSSL-related constants manually.
     *
     * @return void
     */
    private static function setDefinition(): void
    {
        define("EVP_PKEY_EC", 408);
        define('EVP_MAX_MD_SIZE', 64);

        define("BIO_FLAGS_READ", 0x01);
        define("BIO_FLAGS_WRITE", 0x02);
        define("BIO_FLAGS_IO_SPECIAL", 0x04);
        define("BIO_FLAGS_SHOULD_RETRY", 0x08);

        define("SSL_MODE_AUTO_RETRY", 0x00000004);

        define("DTLS_CTRL_GET_TIMEOUT", 73);
        define("DTLS_CTRL_HANDLE_TIMEOUT", 74);

        define("SSL_MODE_ENABLE_PARTIAL_WRITE", 0x00000001);
        define("SSL_CTRL_MODE", 33);
    }
}
