<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\SSL\SSL;

use FFI;
use FFI\CData;
use Webrtc\Exception\InvalidArgumentException;
use Webrtc\Exception\RuntimeException;
use Webrtc\Mixin\SharedLibraryInterface;
use Webrtc\SSL\Enum\BioMethod;
use Webrtc\SSL\Exception\OpenSSLException;
use Webrtc\SSL\Exception\WantReadException;
use Webrtc\SSL\Exception\WantWriteException;

/**
 * BIO (Basic I/O) Stream Handler
 *
 * Provides an interface to OpenSSL's BIO abstraction for I/O operations,
 * supporting various BIO methods (memory, file, socket, etc.). Implement
 * both BIOInterface and SharedLibraryInterface.
 */
class BIO implements BIOInterface, SharedLibraryInterface
{
    /** @var CData|null Input BIO structure */
    private ?CData $input;

    /** @var CData|null Output BIO structure */
    private ?CData $output;

    /** @var FFI Reference to OpenSSL FFI library */
    private FFI $libssl;

    /** @var CData|null Buffer for I/O operations */
    private ?CData $buffer;

    /**
     * BIO constructor
     *
     * Initializes input and output BIO streams using specified methods
     *
     * @param BioMethod $input Method for input BIO
     * @param BioMethod|null $output Method for output BIO (defaults to same as input)
     * @throws RuntimeException If BIO creation fails
     */
    public function __construct(BioMethod $input, ?BioMethod $output = null)
    {
        $this->initiateSharedLibrary();

        $this->input = $this->libssl->BIO_new($this->libssl->{"BIO_" . $input->name}());
        $this->output = $this->libssl->BIO_new($this->libssl->{"BIO_" . ($output ? $output->name : $input->name)}());
        if (!$this->input || !$this->output) {
            throw new RuntimeException("Couldn't create basic input or output");
        }
    }

    /**
     * Gets the input BIO structure
     *
     * @return CData|null The input BIO structure
     */
    public function getInput(): ?CData
    {
        return $this->input;
    }

    /**
     * Gets the output BIO structure
     *
     * @return CData|null The output BIO structure
     */
    public function getOutput(): ?CData
    {
        return $this->output;
    }

    /**
     * Gets the number of pending bytes in output BIO
     *
     * @return int Number of bytes available for reading
     */
    public function getPendingBytes(): int
    {
        return $this->libssl->BIO_ctrl_pending($this->output);
    }

    /**
     * Reads data from the BIO
     *
     * @param bool $input Whether to read from input (true) or output (false) BIO
     * @return string|null The read data or null if no data available
     */
    public function read(bool $input = false): ?string
    {
        $bytes = $this->libssl->BIO_read($input ? $this->input : $this->output, $this->buffer, FFI::sizeof($this->buffer));

        if ($bytes > 0) {
            return FFI::string($this->buffer, $bytes);
        }

        return null;
    }

    /**
     * Writes data to the BIO
     *
     * @param string $buf The data to write
     * @return int Number of bytes written
     * @throws InvalidArgumentException If no output BIO is available
     * @throws OpenSSLException For general BIO errors
     * @throws WantReadException If BIO needs to read before writing
     * @throws WantWriteException If BIO needs to wait before writing
     */
    public function write(string $buf): int
    {
        if ($this->input === null) {
            throw new InvalidArgumentException("There is no output to write");
        }
        $bufSize = strlen($buf);

        $data = $this->libssl->new("unsigned char[$bufSize]");
        FFI::memcpy($data, $buf, $bufSize);

        // Write the data to the memory BIO
        $ret = $this->libssl->BIO_write($this->input, $data, $bufSize);
        $this->handleBioErrors($this->input);

        return $ret;
    }

    /**
     * Handles BIO-specific error conditions
     *
     * @param CData $bio The BIO structure to check
     * @throws OpenSSLException For non-retryable errors
     * @throws WantReadException When BIO needs read operation
     * @throws WantWriteException When BIO needs to write operation
     * @throws InvalidArgumentException For unknown BIO states
     */
    public function handleBioErrors(CData $bio): void
    {
        if ($this->libssl->BIO_test_flags($bio, BIO_FLAGS_SHOULD_RETRY)) {
            if ($this->libssl->BIO_test_flags($bio, BIO_FLAGS_READ)) {
                throw new WantReadException("BIO wants to read");
            } elseif ($this->libssl->BIO_test_flags($bio, BIO_FLAGS_WRITE)) {
                throw new WantWriteException("BIO wants to write");
            } elseif ($this->libssl->BIO_test_flags($bio, BIO_FLAGS_IO_SPECIAL)) {
                throw new InvalidArgumentException("BIO_should_io_special");
            } else {
                throw new InvalidArgumentException("unknown bio failure");
            }
        }
    }

    /**
     * Sets the buffer size for I/O operations
     *
     * @param int $bufferSize Size of buffer to allocate
     */
    public function setBufferSize(int $bufferSize): void
    {
        $this->buffer = $this->libssl->new("char[$bufferSize]");
    }

    /**
     * Initializes the shared OpenSSL library reference
     */
    public function initiateSharedLibrary(): void
    {
        global $libssl;

        if ($libssl instanceof FFI) {
            $this->libssl = $libssl;
        }
    }
}