<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\AVCodec\Audio;

use FFI;
use FFI\CData;
use Webrtc\Exception\InvalidArgumentException;
use Webrtc\Exception\RuntimeException;
use Webrtc\Mixin\SharedLibraryInterface;

/**
 * Class AudioLayout
 *
 * Represents an audio Layout with channel count and channel name.
 */
class AudioLayout implements SharedLibraryInterface
{
    private CData $layout;
    private FFI $libAVCodec;

    public function __construct(string|CData $layout)
    {
        $this->initiateSharedLibrary();

        if (is_string($layout)) {
            $this->layout = $this->libAVCodec->new("AVChannelLayout");

            $result = $this->libAVCodec->av_channel_layout_from_string(FFI::addr($this->layout), $layout);

            if ($result < 0) {
                throw new  InvalidArgumentException("Error parsing channel layout: $result");
            }
        } else {
            $this->layout = $layout;
        }
    }

    /**
     * @return int The number of channels
     */
    public function getNbChannels(): int
    {
        return $this->layout->nb_channels;
    }

    /**
     * @return array<AudioChannel>
     */
    public function getChannels(): array
    {
        $results = [];
        for ($i = 0; $i < $this->layout->nb_channels; $i++) {
            $channel = $this->libAVCodec->av_channel_layout_channel_from_index(FFI::addr($this->layout), $i);

            $buf = $this->libAVCodec->new('char[16]');
            $buf2 = $this->libAVCodec->new('char[128]');

            $size = $this->libAVCodec->av_channel_name($buf, 16, $channel) - 1;
            $size2 = $this->libAVCodec->av_channel_description($buf2, 128, $channel) - 1;

            $results[] = new AudioChannel(
                FFI::string($buf, $size),
                FFI::string($buf2, $size2)
            );
        }

        return $results;
    }

    /**
     * @return string The canonical name of the audio layout.
     */
    public function getName(): string
    {
        $bufSize = 128;
        $buf = $this->libAVCodec->new("char[$bufSize]");
        $ret = $this->libAVCodec->av_channel_layout_describe(FFI::addr($this->layout), $buf, $bufSize);
        if ($ret < 0) {
            throw new RuntimeException("Failed to get layout name: " . $ret);
        }

        return FFI::string($buf);
    }

    public function getLayout(): ?CData
    {
        return $this->layout;
    }

    /**
     * Load the shared library instance for AVCodec.
     *
     * @return void
     *
     * @throws RuntimeException If the shared library is not initialized.
     */
    public function initiateSharedLibrary(): void
    {
        global $libAVCodec;

        if (!$libAVCodec instanceof FFI) {
            throw new RuntimeException("Failed to load AVCodec shared library.");
        }

        $this->libAVCodec = $libAVCodec;
    }
}
