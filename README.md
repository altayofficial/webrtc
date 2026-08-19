# webrtc

[PHP-WebRTC](https://github.com/PHP-WebRTC) rebuilt to run on PHP 8.1 and later.

Upstream requires PHP 8.4. Altay targets 8.1, so this package takes the upstream sources at pinned
tags and runs them through Rector's downgrade set. The result is version agnostic - it runs
unchanged on 8.1 through 8.4.

Namespaces are untouched (`Webrtc\Webrtc\RTCPeerConnection` and friends), so code written against
upstream needs no changes.

## Usage

```
composer require altayofficial/webrtc
```

The package declares `replace` for each of the 14 upstream packages it ships, so Composer will not
install them alongside it and the namespaces cannot collide. The media packages are not replaced -
this package does not provide them, so claiming otherwise would leave anything that genuinely needs
`Webrtc\RTP\` and friends with no source for them.

## Data channels only

Altay uses WebRTC for NetherNet, which is a data channel transport, so the audio/video half of
upstream is left out. `av`, `codecs`, `opus`, `vpx`, `rtp`, `rtcp` and `srtp` are not built, and
with them go the FFI bindings to libav, libopus, libvpx and libsrtp2.

Dropping them from `tools/packages.json` is not enough on its own - the core packages still
referenced media types - so `0002-data-channel-only.patch` strips those paths. `RTCPeerConnection`
loses `addTrack()`, `addTransceiver()`, senders, receivers, transceivers and codec negotiation;
`RTCDtlsTransport` loses SRTP setup and RTP/RTCP demultiplexing. The data channel API is unchanged.

The DTLS layer does not advertise `use_srtp` at all. A peer that offers media simply gets no echo
of the extension and falls back to not negotiating it, which is the correct outcome for a
connection that only ever carries a data channel.

## What the downgrade touches

| Feature | Since | Handled by |
| --- | --- | --- |
| Typed class constants | 8.3 | `DowngradeTypedClassConstRector` |
| `readonly class` | 8.2 | `DowngradeReadonlyClassRector` |
| Constants declared in a trait | 8.2 | `MoveTraitConstantsRector` (local rule) |
| Dynamic class constant fetch | 8.3 | `DowngradeDynamicClassConstFetchRector` |
| Standalone `null`/`false`/`true` types | 8.2 | `DowngradeStandaloneNullTrueFalseReturnTypeRector` |
| `array_find()` / `array_any()` | 8.4 | Rector where possible, otherwise `symfony/polyfill-php84` |

There is no upstream Rector rule for constants in traits, so `tools/src/MoveTraitConstantsRector.php`
moves them into a generated companion class next to the trait and repoints every `self::` reference
at it. That companion does not match its file name, so the build classmaps the affected files.

Nothing in `src/` is edited by hand.

## Patches

`patches/` holds unified diffs applied to the downgraded sources at the end of the build, in file
name order. They exist for changes upstream does not carry yet:

| Patch | What it does |
| --- | --- |
| `0001-binary-data-channel-messages.patch` | Adds a `$binary` parameter to `RTCDataChannel::send()` and `dataChannelSend()`. Without it the SCTP transport guesses the payload type and sends anything that happens to be valid UTF-8 as a WebRTC string, transcoding every byte above 0x7f - which corrupts binary protocols. |
| `0002-data-channel-only.patch` | Removes the media paths from `RTCPeerConnection`, `RTCPeerConnectionInterface`, `RTCDtlsTransport` and `TLS`, and deletes `Webrtc\DTLS\Srtp`, so the media packages can be left out of the build. |
| `0003-native-dtls.patch` | Rewrites `RTCDtlsTransport` and `RTCCertificate` on top of [altayofficial/dtls](https://github.com/altayofficial/dtls) and deletes the FFI backed `TLS` and `Handshake`, so the `ssl` package can be left out and `ext-ffi` disappears entirely. |

To add one, edit `src/` after a build, run `git diff > patches/<name>.patch`, and keep the edit in
place. Patches are written against the downgraded sources, so a rebuild applies them after Rector.

## Rebuilding

```
composer install -d tools
php tools/build.php
```

`tools/packages.json` pins the upstream tag for every package. To pick up a new upstream release,
bump the version there and rebuild - `src/` and `composer.json` are both regenerated. A patch that
no longer applies fails the build, so an upstream change to a patched file has to be resolved
rather than silently dropped.

## Extensions

There is no `ext-ffi` requirement and nothing is loaded at runtime. `ext-gmp` comes from the
crypto paths, and `ext-openssl` from the DTLS layer - both are compiled into the PHP binaries
Altay ships.

Upstream reaches for FFI in one place: `ssl`, which drives the DTLS handshake by `dlopen`ing
OpenSSL. That fails outright on a statically linked musl build, because static musl has no working
`dlopen` - which is what blocks NetherNet on Android. `altayofficial/dtls` implements DTLS 1.2 on
`ext-openssl` instead, so the `ssl` package is not built at all.

`mdns` also declares `ext-ffi` without ever calling into it, so the build drops that requirement
too - and then checks that no shipped source actually touches FFI, failing loudly if one does
rather than quietly shipping a manifest that lies.

## Licensing

This is a derivative distribution. Every upstream package built here is BSD-3-Clause - the MIT
licensed `quasarstream/av` is no longer shipped. Each licence file is reproduced under `licenses/`,
one directory per package. Copyright remains with Amin Yazdanpanah and the PHP-WebRTC
contributors.
