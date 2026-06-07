#include "../include/b64.h"
#include <string.h>
#include <stdlib.h>

static const char B64T[] = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/";

void b64enc(const unsigned char *in, size_t len, char *out) {
    size_t i, j = 0;
    for (i = 0; i + 2 < len; i += 3) {
        unsigned v = ((unsigned)in[i]<<16) | ((unsigned)in[i+1]<<8) | in[i+2];
        out[j++]=B64T[(v>>18)&0x3F]; out[j++]=B64T[(v>>12)&0x3F];
        out[j++]=B64T[(v>>6)&0x3F];  out[j++]=B64T[v&0x3F];
    }
    if (i < len) {
        unsigned v = (unsigned)in[i]<<16;
        if (i+1<len) v |= (unsigned)in[i+1]<<8;
        out[j++]=B64T[(v>>18)&0x3F]; out[j++]=B64T[(v>>12)&0x3F];
        out[j++]=(i+1<len)?B64T[(v>>6)&0x3F]:'='; out[j++]='=';
    }
    out[j]=0;
}
void b64enc_nopad(const unsigned char *in, size_t len, char *out) {
    b64enc(in, len, out);
    int k=(int)strlen(out); while(k>0 && out[k-1]=='=') k--; out[k]=0;
}

static unsigned char b64r_table[256];
static int b64r_init = 0;
int b64dec(const char *in, unsigned char *out, int out_max) {
    if(!b64r_init){
        for(int i=0;i<256;i++) b64r_table[i]=0xFF;
        for(int i=0;i<64;i++) b64r_table[(unsigned char)B64T[i]] = (unsigned char)i;
        b64r_init = 1;
    }
    int len=(int)strlen(in); if(len%4!=0) return -1;
    int oi=0;
    for(int i=0;i<len&&oi<out_max;i+=4){
        unsigned v=0;
        for(int j=0;j<4;j++){
            unsigned char ci=(unsigned char)in[i+j];
            if(ci=='=') break;
            unsigned char val = b64r_table[ci];
            if(val==0xFF) return -1;
            v=(v<<6)|val;
        }
        if(oi<out_max)out[oi++]=(unsigned char)(v>>16);
        if(oi<out_max&&in[i+2]!='=')out[oi++]=(unsigned char)(v>>8);
        if(oi<out_max&&in[i+3]!='=')out[oi++]=v;
    }
    return oi;
}
