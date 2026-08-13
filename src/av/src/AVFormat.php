<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\AVCodec;

use FFI;
use FFI\Exception as FFIException;
use Webrtc\AVCodec\Exception\AvCodecException;

/**
 * Class AVFormat
 *
 * This class provides methods to initialize the AVFormat library and handle
 * AVFormat-related operations.
 */
class AVFormat
{
    /**
     * The minimum supported major version of libavformat.
     * @var int
     */
    private const SUPPORTED_VERSION = 7;

    /**
     * The path to the AVFormat C header file.
     * @var string
     */
    private const HEADER_FILE_PATH = __DIR__ . "/libav/include/avformat.h";

    /**
     * Initializes the AVFormat library.
     *
     * This method attempts to load the libavformat shared library via FFI, verify its version,
     * and set necessary configurations. If initialization fails, a detailed exception is thrown.
     *
     * @param bool $debug Whether to enable debug logging for AVFormat.
     *
     * @return void
     *
     * @throws AvCodecException If the AVFormat library cannot be loaded or the version is unsupported.
     */
    public static function init(bool $debug = false): void
    {
        global $libAVFormat;

        if (!isset($libAVFormat)) {
            try {
                $lib = getenv("LIB_AVFORMAT_PATH") ?? self::getLibPath();
                $libAVFormat = FFI::cdef(file_get_contents(self::HEADER_FILE_PATH), $lib);

                // Verify the loaded library version
                $version = $libAVFormat->av_version_info();

                if ($version < self::SUPPORTED_VERSION) {
                    throw new AvCodecException(sprintf(
                        "The library could not be initialized. Required version: %d or higher. Detected version: %d.",
                        self::SUPPORTED_VERSION,
                        $version
                    ));
                }

                if (!$debug) {
                    $libAVFormat->av_log_set_level(-8); // Set minimal logging when not debugging
                }

            } catch (FFIException $e) {
                $os = PHP_OS_FAMILY;
                $installHint = match ($os) {
                    'Windows' => <<<EOT
Download and install FFmpeg (version 7.x or higher) with development files (DLLs) from https://ffmpeg.org/download.html.
Ensure avformat-*.dll is accessible in your PATH or specify LIB_AVFORMAT_PATH environment variable.
EOT,
                    'Darwin' => <<<EOT
Install FFmpeg with development headers on macOS:

    brew install ffmpeg

Ensure it is version 7.x or higher. Upgrade if necessary:

    brew upgrade ffmpeg
EOT,
                    'Linux' => <<<EOT
Install FFmpeg development packages on Linux:

For Debian/Ubuntu:

    sudo apt update
    sudo apt install libavformat-dev

For Fedora/RHEL:

    sudo dnf install ffmpeg-devel

Ensure the installed version is 7.x or higher. If needed, compile FFmpeg manually.
EOT,
                    default => "Please install FFmpeg (version 7.x or higher) and ensure development libraries are available. Visit https://ffmpeg.org/download.html for instructions."
                };

                throw new AvCodecException(sprintf(
                    "Couldn't load AvFormat library: %s\n\nInstallation instructions:\n%s",
                    $e->getMessage(),
                    $installHint
                ), $e->getCode(), $e);
            }
        }
    }

    /**
     * Determines and returns the appropriate libavformat shared library path.
     *
     * This method tries common library locations based on the operating system
     * and returns the most probable path to the libavformat shared library.
     * If no common path is found, it returns a generic library name.
     *
     * @return string The path or name of the libavformat shared library.
     */
    private static function getLibPath(): string
    {
        $candidates = [];

        $os = PHP_OS_FAMILY;

        if ($os === 'Windows') {
            $candidates = [
                'avformat-8.dll',
                'avformat-7.dll',
                'avformat.dll',
            ];
        } elseif ($os === 'Darwin') { // macOS
            $candidates = [
                '/usr/local/lib/libavformat.dylib',
                '/opt/homebrew/lib/libavformat.dylib',
                'libavformat.dylib',
            ];
        } elseif ($os === 'Linux') {
            $candidates = [
                '/usr/lib/x86_64-linux-gnu/libavformat.so',
                '/usr/local/lib/libavformat.so',
                'libavformat.so',
            ];
        } else {
            $candidates = [
                'libavformat',
            ];
        }

        foreach ($candidates as $candidate) {
            if (is_file($candidate) || @file_exists($candidate)) {
                return $candidate;
            }
        }

        return match ($os) {
            'Windows' => 'avformat.dll',
            'Darwin' => 'libavformat.dylib',
            'Linux' => 'libavformat.so',
            default => 'libavformat',
        };
    }
}
