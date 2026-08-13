// Structs
typedef struct SwsContext SwsContext;

typedef struct SwsVector {
    double *coeff;
    int length;
} SwsVector;

typedef struct SwsFilter {
    SwsVector *lumH;
    SwsVector *lumV;
    SwsVector *chrH;
    SwsVector *chrV;
} SwsFilter;

// Functions
SwsContext *sws_getCachedContext(SwsContext *context, int srcW, int srcH, enum AVPixelFormat srcFormat, int dstW, int dstH, enum AVPixelFormat dstFormat, int flags, SwsFilter *srcFilter, SwsFilter *dstFilter, const double *param);
int sws_scale(SwsContext *c, const uint8_t *const srcSlice[], const int srcStride[], int srcSliceY, int srcSliceH, uint8_t *const dst[], const int dstStride[]);
void sws_freeContext(SwsContext *swsContext);