#include "../include/xxh3.h"
#include "../include/b64.h"
#include <stdio.h>
#include <string.h>

void xxh3_bin(const void *d, size_t n, unsigned char out[8]) {
    XXH64_hash_t h = XXH3_64bits(d, n);
    memcpy(out, &h, 8);
}
void xxh3_file(const char *path, unsigned char out[8]) {
    FILE *f = fopen(path, "rb"); if (!f) { memset(out,0,8); return; }
    XXH3_state_t *st = XXH3_createState(); XXH3_64bits_reset(st);
    unsigned char buf[131072]; size_t r;
    while ((r=fread(buf,1,sizeof(buf),f))>0) XXH3_64bits_update(st,buf,r);
    fclose(f);
    XXH64_hash_t h = XXH3_64bits_digest(st); XXH3_freeState(st);
    memcpy(out, &h, 8);
}
void xxh3_rev_b64(const void *d, size_t n, char *out) {
    unsigned char hb[8]; xxh3_bin(d, n, hb);
    unsigned char rv[8]; for(int i=0;i<8;i++) rv[i]=hb[7-i];
    b64enc_nopad(rv, 8, out);
}
