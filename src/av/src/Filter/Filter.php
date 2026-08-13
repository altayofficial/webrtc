<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\AVCodec\Filter;

use FFI;
use FFI\CData;
use Webrtc\Exception\InvalidArgumentException;
use Webrtc\Mixin\SharedLibraryInterface;

/**
 * Class Filter
 *
 * Represents an AVFilter and provides access to its properties, options, and capabilities.
 */
class Filter implements SharedLibraryInterface
{
    /**
     * @var CData|null Pointer to the underlying AVFilter structure.
     */
    private ?CData $filter;
    private FFI $libAVFilter;

    /**
     * Filter constructor.
     *
     * @param string|CData $filter
     */
    public function __construct(string|CData $filter)
    {
        $this->initiateSharedLibrary();
        $this->filter = $filter instanceof CData ? $filter : $this->libAVFilter->avfilter_get_by_name($filter);
        if (!$this->filter) {
            throw new InvalidArgumentException("No filter found with the name: $filter");
        }
    }

    /**
     * Get the name of the filter.
     *
     * @return string The name of the filter.
     */
    public function getName(): string
    {
        return $this->filter->name;
    }

    /**
     * Get the description of the filter.
     *
     * @return string The description of the filter.
     */
    public function getDescription(): string
    {
        return $this->filter->description;
    }

    /**
     * Get the flags for the filter.
     *
     * @return int The filter's flags.
     */
    public function getFlags(): int
    {
        return $this->filter->flags;
    }

    /**
     * Check if the filter supports dynamic inputs.
     *
     * @return bool True if the filter supports dynamic inputs; otherwise, false.
     */
    public function hasDynamicInputs(): bool
    {
        return (bool)($this->filter->flags & (1 << 0));
    }

    /**
     * Check if the filter supports dynamic outputs.
     *
     * @return bool True if the filter supports dynamic outputs; otherwise, false.
     */
    public function hasDynamicOutputs(): bool
    {
        return (bool)($this->filter->flags & (1 << 1));
    }

    /**
     * Check if the filter supports timeline features.
     *
     * @return bool True if the filter supports timeline features; otherwise, false.
     */
    public function supportsTimeline(): bool
    {
        return (bool)($this->filter->flags & $this->libAVFilter->AVFILTER_FLAG_SUPPORT_TIMELINE_GENERIC);
    }

    /**
     * Check if the filter supports slice threading.
     *
     * @return bool True if the filter supports slice threading; otherwise, false.
     */
    public function supportsSliceThreads(): bool
    {
        return (bool)($this->filter->flags & $this->libAVFilter->AVFILTER_FLAG_SLICE_THREADS);
    }

    /**
     * Check if the filter supports command processing.
     *
     * @return bool True if the filter supports commands; otherwise, false.
     */
    public function supportsCommands(): bool
    {
        return $this->filter->process_command !== null;
    }

    /**
     * Initiate the shared library
     *
     * @return void
     */
    public function initiateSharedLibrary(): void
    {
        global $libAVFilter;

        if ($libAVFilter instanceof FFI) {
            $this->libAVFilter = $libAVFilter;
        }
    }

    /**
     * @return CData|null
     */
    public function getFilter(): ?CData
    {
        return $this->filter;
    }
}
