// Typedefs
typedef uint32_t vpx_codec_er_flags_t;
typedef int64_t vpx_codec_pts_t;
typedef uint32_t vpx_codec_frame_flags_t;
typedef long vpx_enc_frame_flags_t;
typedef unsigned long vpx_enc_deadline_t;
typedef const void *vpx_codec_iter_t;

// Enums
enum vp8e_enc_control_id {
    VP8E_SET_CPUUSED = 13,
    VP8E_SET_NOISE_SENSITIVITY = 15,
    VP8E_SET_STATIC_THRESHOLD =  17,
    VP8E_SET_TOKEN_PARTITIONS = 18
};


typedef enum {
  VPX_CODEC_OK,
  VPX_CODEC_ERROR,
  VPX_CODEC_MEM_ERROR,
  VPX_CODEC_ABI_MISMATCH,
  VPX_CODEC_INCAPABLE,
  VPX_CODEC_UNSUP_BITSTREAM,
  VPX_CODEC_UNSUP_FEATURE,
  VPX_CODEC_CORRUPT_FRAME,
  VPX_CODEC_INVALID_PARAM,
  VPX_CODEC_LIST_END
} vpx_codec_err_t;

typedef enum vpx_bit_depth {
  VPX_BITS_8 = 8,
  VPX_BITS_10 = 10,
  VPX_BITS_12 = 12,
} vpx_bit_depth_t;

typedef enum vpx_enc_pass {
  VPX_RC_ONE_PASS,
  VPX_RC_FIRST_PASS,
  VPX_RC_LAST_PASS
} vpx_enc_pass;

typedef enum vpx_rc_mode {
  VPX_VBR,
  VPX_CBR,
  VPX_CQ,
  VPX_Q
} vpx_rc_mode;

typedef enum vpx_kf_mode {
  VPX_KF_FIXED,
  VPX_KF_AUTO,
  VPX_KF_DISABLED = 0
} vpx_kf_mode;

typedef enum vpx_img_fmt {
  VPX_IMG_FMT_NONE,
  VPX_IMG_FMT_I420 = 258,
} vpx_img_fmt_t;

typedef enum vpx_color_space {
  VPX_CS_UNKNOWN = 0,
  VPX_CS_BT_601 = 1,
  VPX_CS_BT_709 = 2,
  VPX_CS_SMPTE_170 = 3,
  VPX_CS_SMPTE_240 = 4,
  VPX_CS_BT_2020 = 5,
  VPX_CS_RESERVED = 6,
  VPX_CS_SRGB = 7
} vpx_color_space_t;

typedef enum vpx_color_range {
  VPX_CR_STUDIO_RANGE = 0,
  VPX_CR_FULL_RANGE = 1
} vpx_color_range_t;

typedef enum vpx_codec_cx_pkt_kind {
  VPX_CODEC_CX_FRAME_PKT,
  VPX_CODEC_STATS_PKT,
  VPX_CODEC_FPMB_STATS_PKT,
  VPX_CODEC_PSNR_PKT,
  VPX_CODEC_CUSTOM_PKT = 256
} vpx_codec_cx_pkt_kind;

// Structs
typedef const struct vpx_codec_iface vpx_codec_iface_t;

typedef long vpx_codec_flags_t;

typedef struct vpx_codec_priv vpx_codec_priv_t;

typedef struct vpx_codec_ctx {
  const char *name;
  vpx_codec_iface_t *iface;
  vpx_codec_err_t err;
  const char *err_detail;
  vpx_codec_flags_t init_flags;
  union {
    const struct vpx_codec_dec_cfg *dec;
    const struct vpx_codec_enc_cfg *enc;
    const void *raw;
  } config;
  vpx_codec_priv_t *priv;
} vpx_codec_ctx_t;

typedef struct vpx_rational {
  int num;
  int den;
} vpx_rational_t;

typedef struct vpx_fixed_buf {
  void *buf;
  size_t sz;
} vpx_fixed_buf_t;

typedef struct vpx_codec_enc_cfg {
  unsigned int g_usage;
  unsigned int g_threads;
  unsigned int g_profile;
  unsigned int g_w;
  unsigned int g_h;
  vpx_bit_depth_t g_bit_depth;
  unsigned int g_input_bit_depth;
  struct vpx_rational g_timebase;
  vpx_codec_er_flags_t g_error_resilient;
  enum vpx_enc_pass g_pass;
  unsigned int g_lag_in_frames;
  unsigned int rc_dropframe_thresh;
  unsigned int rc_resize_allowed;
  unsigned int rc_scaled_width;
  unsigned int rc_scaled_height;
  unsigned int rc_resize_up_thresh;
  unsigned int rc_resize_down_thresh;
  enum vpx_rc_mode rc_end_usage;
  vpx_fixed_buf_t rc_twopass_stats_in;
  vpx_fixed_buf_t rc_firstpass_mb_stats_in;
  unsigned int rc_target_bitrate;
  unsigned int rc_min_quantizer;
  unsigned int rc_max_quantizer;
  unsigned int rc_undershoot_pct;
  unsigned int rc_overshoot_pct;
  unsigned int rc_buf_sz;
  unsigned int rc_buf_initial_sz;
  unsigned int rc_buf_optimal_sz;
  unsigned int rc_2pass_vbr_bias_pct;
  unsigned int rc_2pass_vbr_minsection_pct;
  unsigned int rc_2pass_vbr_maxsection_pct;
  unsigned int rc_2pass_vbr_corpus_complexity;
  enum vpx_kf_mode kf_mode;
  unsigned int kf_min_dist;
  unsigned int kf_max_dist;
  unsigned int ss_number_layers;
  int ss_enable_auto_alt_ref[5];
  unsigned int ss_target_bitrate[5];
  unsigned int ts_number_layers;
  unsigned int ts_target_bitrate[5];
  unsigned int ts_rate_decimator[5];
  unsigned int ts_periodicity;
  unsigned int ts_layer_id[16];
  unsigned int layer_target_bitrate[12];
  int temporal_layering_mode;
  int use_vizier_rc_params;
  vpx_rational_t active_wq_factor;
  vpx_rational_t err_per_mb_factor;
  vpx_rational_t sr_default_decay_limit;
  vpx_rational_t sr_diff_factor;
  vpx_rational_t kf_err_per_mb_factor;
  vpx_rational_t kf_frame_min_boost_factor;
  vpx_rational_t kf_frame_max_boost_first_factor;
  vpx_rational_t kf_frame_max_boost_subs_factor;
  vpx_rational_t kf_max_total_boost_factor;
  vpx_rational_t gf_max_total_boost_factor;
  vpx_rational_t gf_frame_max_boost_factor;
  vpx_rational_t zm_factor;
  vpx_rational_t rd_mult_inter_qp_fac;
  vpx_rational_t rd_mult_arf_qp_fac;
  vpx_rational_t rd_mult_key_qp_fac;
} vpx_codec_enc_cfg_t;

typedef struct vpx_codec_dec_cfg {
  unsigned int threads;
  unsigned int w;
  unsigned int h;
} vpx_codec_dec_cfg_t;

typedef struct vpx_image {
  vpx_img_fmt_t fmt;
  vpx_color_space_t cs;
  vpx_color_range_t range;

  unsigned int w;
  unsigned int h;
  unsigned int bit_depth;

  unsigned int d_w;
  unsigned int d_h;

  unsigned int r_w;
  unsigned int r_h;

  unsigned int x_chroma_shift;
  unsigned int y_chroma_shift;

  unsigned char *planes[4];
  int stride[4];

  int bps;
  void *user_priv;

  unsigned char *img_data;
  int img_data_owner;
  int self_allocd;

  void *fb_priv;
} vpx_image_t;

typedef struct vpx_codec_cx_pkt {
  enum vpx_codec_cx_pkt_kind kind;
  union {
    struct {
      void *buf;
      size_t sz;
      vpx_codec_pts_t pts;
      unsigned long duration;
      vpx_codec_frame_flags_t flags;
      int partition_id;
      unsigned int width[5];
      unsigned int height[5];
      uint8_t spatial_layer_encoded[5];
    } frame;
    vpx_fixed_buf_t twopass_stats;
    vpx_fixed_buf_t firstpass_mb_stats;
    struct vpx_psnr_pkt {
      unsigned int samples[4];
      uint64_t sse[4];
      double psnr[4];
    } psnr;
    vpx_fixed_buf_t raw;

    char pad[128 - sizeof(enum vpx_codec_cx_pkt_kind)];
  } data;
} vpx_codec_cx_pkt_t;

// Methods
vpx_codec_err_t vpx_codec_enc_config_default(vpx_codec_iface_t *iface,
                                             vpx_codec_enc_cfg_t *cfg,
                                             unsigned int usage);

vpx_codec_err_t vpx_codec_enc_init_ver(vpx_codec_ctx_t *ctx,
                                       vpx_codec_iface_t *iface,
                                       const vpx_codec_enc_cfg_t *cfg,
                                       vpx_codec_flags_t flags, int ver);

vpx_codec_err_t vpx_codec_destroy(vpx_codec_ctx_t *ctx);

extern vpx_codec_iface_t *vpx_codec_vp8_cx(void);

extern vpx_codec_iface_t *vpx_codec_vp8_dx(void);

vpx_image_t *vpx_img_alloc(vpx_image_t *img, vpx_img_fmt_t fmt,
                           unsigned int d_w, unsigned int d_h,
                           unsigned int align);

const vpx_codec_cx_pkt_t *vpx_codec_get_cx_data(vpx_codec_ctx_t *ctx,
                                                vpx_codec_iter_t *iter);

vpx_codec_err_t vpx_codec_encode(vpx_codec_ctx_t *ctx, const vpx_image_t *img,
                                 vpx_codec_pts_t pts, unsigned long duration,
                                 vpx_enc_frame_flags_t flags,
                                 vpx_enc_deadline_t deadline);

vpx_codec_err_t vpx_codec_dec_init_ver(vpx_codec_ctx_t *ctx,
                                       vpx_codec_iface_t *iface,
                                       const vpx_codec_dec_cfg_t *cfg,
                                       vpx_codec_flags_t flags, int ver);

vpx_codec_err_t vpx_codec_decode(vpx_codec_ctx_t *ctx, const uint8_t *data,
                                 unsigned int data_sz, void *user_priv,
                                 long deadline);

vpx_image_t *vpx_codec_get_frame(vpx_codec_ctx_t *ctx, vpx_codec_iter_t *iter);

void vpx_img_free(vpx_image_t *img);

int vpx_codec_version(void);

vpx_codec_err_t vpx_codec_control_(vpx_codec_ctx_t *ctx, int ctrl_id, ...);

vpx_codec_err_t vpx_codec_enc_config_set(vpx_codec_ctx_t *ctx, const vpx_codec_enc_cfg_t *cfg);
vpx_image_t *vpx_img_wrap(vpx_image_t *img, vpx_img_fmt_t fmt, unsigned int d_w,
                          unsigned int d_h, unsigned int stride_align,
                          unsigned char *img_data);
const char *vpx_codec_error(const vpx_codec_ctx_t *ctx);
const char *vpx_codec_err_to_string(vpx_codec_err_t err);
const char *vpx_codec_error_detail(const vpx_codec_ctx_t *ctx);
vpx_codec_err_t vpx_codec_destroy(vpx_codec_ctx_t *ctx);