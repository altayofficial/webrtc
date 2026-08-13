// Structs
typedef struct OpusDecoder OpusDecoder;
typedef struct OpusEncoder OpusEncoder;
typedef int16_t opus_int16;
typedef int32_t opus_int32;

// Functions
OpusDecoder *opus_decoder_create(
    opus_int32 Fs,
    int channels,
    int *error
);
int opus_decode(
    OpusDecoder *st,
    const unsigned char *data,
    opus_int32 len,
    opus_int16 *pcm,
    int frame_size,
    int decode_fec
);
void opus_decoder_destroy(OpusDecoder *st);

OpusEncoder *opus_encoder_create(
    opus_int32 Fs,
    int channels,
    int application,
    int *error
);
opus_int32 opus_encode(
    OpusEncoder *st,
    const opus_int16 *pcm,
    int frame_size,
    unsigned char *data,
    opus_int32 max_data_bytes
);
void opus_encoder_destroy(OpusEncoder *st);
const char* opus_get_version_string(void);