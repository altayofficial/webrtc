<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\Opus;

use FFI;
use FFI\Exception as FFIException;
use Webrtc\Opus\Exception\OpusException;

/**
 * Class Opus
 *
 * This class provides methods to initialize the Opus library and handle
 * Opus-related operations.
 *
 * @deprecated
 */
class Opus
{
    /**
     * The minimum supported version of Opus required (in integer format).
     * @var int
     */
    private const SUPPORTED_VERSION = 10301; // corresponds to 1.3.1
    /**
     * The path to the Opus C header file.
     * @var string
     */
    private const HEADER_FILE_PATH = __DIR__ . "/libopus/include/opus.h";

    /**
     * Initializes the Opus library and returns an FFI instance.
     *
     * This method attempts to load the libopus shared library via FFI.
     * If initialization fails, a detailed exception is thrown.
     *
     * @return void
     *
     * @throws OpusException If the Opus library cannot be loaded.
     */
    public static function init(): void
    {
        global $libOpus;

        if (!isset($libOpus)) {
            try {
                $lib = getenv("LIB_OPUS_PATH") ?: self::getLibPath();
                $libOpus = FFI::cdef(file_get_contents(self::HEADER_FILE_PATH), $lib);

                // Call a function to verify if the library has initialized correctly
                $versionString = $libOpus->opus_get_version_string();
                $versionNumber = self::parseVersion($versionString);

                if ($versionNumber < self::SUPPORTED_VERSION) {
                    throw new OpusException(sprintf(
                        "The Opus library could not be initialized. The required version is %d or higher, but the detected version is %d (%s).",
                        self::SUPPORTED_VERSION,
                        $versionNumber,
                        $versionString
                    ));
                }

                self::setDefinition();

            } catch (FFIException $e) {
                $os = PHP_OS_FAMILY;
                $installHint = match ($os) {
                    'Windows' => <<<EOT
Download and install Opus (with development headers) from https://opus-codec.org/.
Ensure opus-*.dll is accessible in your PATH or specify LIB_OPUS_PATH environment variable.
EOT,
                    'Darwin' => <<<EOT
Install Opus library with development headers on macOS:

    brew install opus

Ensure the libopus.dylib is available in your system paths.
EOT,
                    'Linux' => <<<EOT
Install Opus development packages on Linux:

For Debian/Ubuntu:

    sudo apt update
    sudo apt install libopus-dev

For Fedora/RHEL:

    sudo dnf install opus-devel

Ensure the version installed is 1.3.1 or higher.
EOT,
                    default => "Please install Opus and ensure libopus is available. Visit https://opus-codec.org/ for instructions."
                };

                throw new OpusException(sprintf(
                    "Couldn't load Opus library: %s\n\nInstallation instructions:\n%s",
                    $e->getMessage(),
                    $installHint
                ), $e->getCode(), $e);
            }
        }
    }

    /**
     * Parses Opus version string to a comparable integer.
     *
     * Example: "libopus 1.3.1" → 10301
     *
     * @param string $versionString
     * @return int
     */
    private static function parseVersion(string $versionString): int
    {
        if (preg_match('/(\d+)\.(\d+)/', $versionString, $matches)) {
            return ((int)$matches[1]) * 10000 + ((int)$matches[2]) * 100;
        }

        return 0;
    }

    /**
     * Defines constants related to Opus codec operations.
     *
     * @return void
     */
    private static function setDefinition(): void
    {
        define("OPUS_APPLICATION_VOIP", 2048);
    }

    /**
     * Determines and returns the appropriate libopus shared library path.
     *
     * This method tries common library locations based on the operating system
     * and returns the most probable path to the libopus shared library.
     *
     * @return string The path or name of the libopus shared library.
     */
    private static function getLibPath(): string
    {
        $os = PHP_OS_FAMILY;

        if ($os === 'Windows') {
            $candidates = [
                'opus-0.dll',
                'opus-1.dll',
                'opus.dll',
            ];
        } elseif ($os === 'Darwin') { // macOS
            $candidates = [
                '/usr/local/lib/libopus.dylib',
                '/opt/homebrew/lib/libopus.dylib',
                'libopus.dylib',
            ];
        } elseif ($os === 'Linux') {
            $candidates = [
                '/usr/lib/x86_64-linux-gnu/libopus.so',
                '/usr/local/lib/libopus.so',
                'libopus.so',
            ];
        } else {
            $candidates = [
                'libopus',
            ];
        }

        foreach ($candidates as $candidate) {
            if (is_file($candidate) || @file_exists($candidate)) {
                return $candidate;
            }
        }

        return match ($os) {
            'Windows' => 'opus.dll',
            'Darwin' => 'libopus.dylib',
            'Linux' => 'libopus.so',
            default => 'libopus',
        };
    }
}
