<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\AVCodec\Data;

use FFI;
use Webrtc\Mixin\SharedLibraryInterface;

/**
 * Buffer Abstract Base Class
 *
 * Provides common functionality for working with audio/video data buffers
 * through FFI. Serves as the base class for all buffer types in the system.
 *
 * @package Webrtc\AVCodec\Data
 */
abstract class Buffer implements SharedLibraryInterface, BufferInterface
{
    /**
     * @var FFI $libAVCodec FFI instance for AVCodec library
     */
    protected FFI $libAVCodec;

    /**
     * @var bool $bufferWritable Flag indicating if buffer can be modified
     */
    protected bool $bufferWritable = true;

    /**
     * Constructor - initializes the shared library
     */
    public function __construct()
    {
        $this->initiateSharedLibrary();
    }

    /**
     * Get buffer size (abstract)
     *
     * @return int Size of buffer in bytes
     */
    abstract protected function getSize(): int;

    /**
     * Initialize shared library instance
     */
    public function initiateSharedLibrary(): void
    {
        global $libAVCodec;

        if ($libAVCodec instanceof FFI) {
            $this->libAVCodec = $libAVCodec;
        }
    }

    /**
     * Check if buffer is writable
     *
     * @return bool True if buffer can be modified
     */
    public function isBufferWritable(): bool
    {
        return $this->bufferWritable;
    }

    /**
     * Set buffer writable flag
     *
     * @param bool $isBufferWritable New writable state
     */
    public function setIsBufferWritable(bool $isBufferWritable): void
    {
        $this->bufferWritable = $isBufferWritable;
    }
}
