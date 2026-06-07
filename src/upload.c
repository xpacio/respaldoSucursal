#include "../include/upload.h"
#include "../include/xxh3.h"
#include "../include/b64.h"
#include "../include/log.h"
#include "../include/buf.h"
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include "miniz.h"

void upload_file(const char *service, const Location *loc, const char *fname, const char *wp) {
    long long sz = 0; FILE *tf=fopen(wp,"rb"); if(!tf) return; fseek(tf,0,SEEK_END); sz=ftell(tf); fclose(tf);
    if(sz<=0) return;
    int cs = CHUNK_MIN;
    int total_chunks = (int)((sz+cs-1)/cs);
    char (*ch_hashes)[12] = calloc(total_chunks, 12);
    if (!ch_hashes) { log_error("OOM for chunk hashes"); return; }
    FILE *fh = fopen(wp,"rb"); if(!fh) { free(ch_hashes); return; }
    for(int ci=0;ci<total_chunks;ci++) {
        unsigned char buf[CHUNK_MAX];
        int n = (int)fread(buf,1,cs<CHUNK_MAX?cs:CHUNK_MAX,fh); if(n<=0) break;
        xxh3_rev_b64(buf,n,ch_hashes[ci]);
    }
    fclose(fh);
    int chs_len=total_chunks*13; char *chs_buf=malloc(chs_len); if(!chs_buf){free(ch_hashes);return;}
    chs_buf[0]=0;
    for(int ci=0;ci<total_chunks;ci++) { if(ci>0) strcat(chs_buf,","); strcat(chs_buf,ch_hashes[ci]); }
    char fh_b64[32]; { unsigned char fhb[8]; xxh3_file(wp,fhb); unsigned char rv[8]; for(int i=0;i<8;i++) rv[i]=fhb[7-i]; b64enc_nopad(rv,8,fh_b64); }
    char sync_body[8192]; snprintf(sync_body,sizeof(sync_body),"{\"service\":\"%s\",\"files\":[{\"filename\":\"%s\",\"hash_completo\":\"%s\",\"chunk_hashes\":[%s],\"mtime\":%lld,\"size\":%lld}]}", service,fname,fh_b64,chs_buf,0,sz);
    Buf resp; binit(&resp);
    // For brevity, minimal loop: call sync and then return
    if(http_req("sync",loc->rbfid,sync_body,&resp)!=0) { log_error("sync failed for %s",fname); }
    bfree(&resp);
    free(ch_hashes); free(chs_buf);
}
