#include "../include/buf.h"
#include <stdlib.h>
#include <string.h>

void binit(Buf *b) { memset(b,0,sizeof(*b)); }
int bput(Buf *b, const void *s, int l) {
    if (b->len+l>b->cap) {
        int nc=b->cap?b->cap*2:8192; while(nc<b->len+l) nc*=2;
        char *t=realloc(b->d,nc); if(!t) return 0; b->d=t; b->cap=nc;
    }
    memcpy(b->d+b->len,s,l); b->len+=l; return 1;
}
int bputs(Buf *b, const char *s) { return bput(b,s,(int)strlen(s)); }
void bfree(Buf *b) { free(b->d); b->d=NULL; b->len=b->cap=0; }
