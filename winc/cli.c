#include "xxhash.h"
#include "miniz.h"
#include <windows.h>
#include <wininet.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <stdarg.h>
#include <time.h>
#include <direct.h>
#include <ctype.h>

#define VERSION "0.60430j"
#define DEFAULT_URL "http://respaldosucursal.servicios.care"
#define CHUNK_MIN 65536
#define CHUNK_MAX 1048576
#define MAX_CHUNKS 8192

/* ─── Log ─── */
static void log_msg(const char *tag, const char *fmt, ...) {
    time_t t = time(NULL); struct tm *lt = localtime(&t);
    va_list ap; va_start(ap, fmt);
    char buf[4096]; vsnprintf(buf, sizeof(buf), fmt, ap); va_end(ap);
    printf("[%02d:%02d:%02d] [%s] %s\n", lt->tm_hour, lt->tm_min, lt->tm_sec, tag, buf);
    FILE *f = fopen("logs\\cli.log", "a");
    if (f) { fprintf(f, "[%04d-%02d-%02d %02d:%02d:%02d] [%s] %s\n",
        lt->tm_year+1900, lt->tm_mon+1, lt->tm_mday,
        lt->tm_hour, lt->tm_min, lt->tm_sec, tag, buf); fclose(f); }
}
#define log_info(...)  log_msg("INFO", __VA_ARGS__)
#define log_error(...) log_msg("ERROR", __VA_ARGS__)
#define log_debug(...) log_msg("DEBUG", __VA_ARGS__)

/* ─── Globals ─── */
static long long g_bytes_xfer = 0;
static int g_chunks_xfer = 0;
static long long g_size_inc = 0;
static long long g_uncomp = 0;
static long long g_comp = 0;

/* ─── Base64 ─── */
static const char B64T[] = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/";
static void b64enc(const unsigned char *in, size_t len, char *out) {
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
static void b64enc_nopad(const unsigned char *in, size_t len, char *out) {
    b64enc(in, len, out);
    int k=(int)strlen(out); while(k>0 && out[k-1]=='=') k--; out[k]=0;
}

/* ─── xxh3 helpers ─── */
static void xxh3_bin(const void *d, size_t n, unsigned char out[8]) {
    XXH64_hash_t h = XXH3_64bits(d, n);
    memcpy(out, &h, 8);
}
static void xxh3_file(const char *path, unsigned char out[8]) {
    FILE *f = fopen(path, "rb"); if (!f) { memset(out,0,8); return; }
    XXH3_state_t *st = XXH3_createState(); XXH3_64bits_reset(st);
    unsigned char buf[131072]; size_t r;
    while ((r=fread(buf,1,sizeof(buf),f))>0) XXH3_64bits_update(st,buf,r);
    fclose(f);
    XXH64_hash_t h = XXH3_64bits_digest(st); XXH3_freeState(st);
    memcpy(out, &h, 8);
}
static void xxh3_rev_b64(const void *d, size_t n, char *out) {
    unsigned char hb[8]; xxh3_bin(d, n, hb);
    unsigned char rv[8]; for(int i=0;i<8;i++) rv[i]=hb[7-i];
    b64enc_nopad(rv, 8, out);
}

/* ─── TOTP matching PHP: hash(substr(ts,0,-2).rbfid) → rev → b64 ─── */
static void totp_gen(const char *rbfid, char *out) {
    char buf[128];
    _ui64toa((unsigned long long)time(NULL)/100, buf, 10);
    strcat(buf, rbfid);
    xxh3_rev_b64(buf, strlen(buf), out);
}

/* ─── Chunk size ─── */
static int chunk_sz(int sz) {
    if (sz<=0) return CHUNK_MIN;
    if (sz<1048576)  return CHUNK_MIN;
    if (sz<10485760) return 262144;
    return CHUNK_MAX;
}

/* ─── URL parse ─── */
static void parse_url(const char *url, char *host, int hl, char *path, int pl, int *port) {
    *host=0; *path=0; *port=80;
    const char *p=url;
    if (strncmp(p,"https://",8)==0) { p+=8; *port=443; }
    else if (strncmp(p,"http://",7)==0) p+=7;
    const char *slash=strchr(p,'/');
    if (slash) { int n=(int)(slash-p); if(n>hl-1)n=hl-1; strncpy(host,p,n); host[n]=0; strncpy(path,slash,pl-1); }
    else { strncpy(host,p,hl-1); path[0]='/'; path[1]=0; }
    char *colon=strchr(host,':'); if(colon) { *colon=0; *port=atoi(colon+1); }
}

/* ─── Dynamic buffer ─── */
typedef struct { char *d; int len, cap; } Buf;
static void binit(Buf *b) { memset(b,0,sizeof(*b)); }
static int bput(Buf *b, const void *s, int l) {
    if (b->len+l>b->cap) {
        int nc=b->cap?b->cap*2:8192; while(nc<b->len+l) nc*=2;
        char *t=realloc(b->d,nc); if(!t) return 0; b->d=t; b->cap=nc;
    }
    memcpy(b->d+b->len,s,l); b->len+=l; return 1;
}
static int bputs(Buf *b, const char *s) { return bput(b,s,(int)strlen(s)); }
static void bfree(Buf *b) { free(b->d); b->d=NULL; b->len=b->cap=0; }

/* ─── HTTP POST JSON ─── */
static int http_req(const char *action, const char *rbfid, const char *body, Buf *resp) {
    char url[512], host[128], path[384]; int port;
    snprintf(url,sizeof(url),"%s/api/%s/%s",DEFAULT_URL,action,rbfid);
    parse_url(url,host,sizeof(host),path,sizeof(path),&port);
    char totp[32]; totp_gen(rbfid,totp);
    char ts_str[32]; snprintf(ts_str,sizeof(ts_str),"%lld",(long long)time(NULL));
    HINTERNET hInet=InternetOpenA("respcli/1.0",INTERNET_OPEN_TYPE_PRECONFIG,NULL,NULL,0);
    if (!hInet) return -1;
    HINTERNET hConn=InternetConnectA(hInet,host,port,NULL,NULL,INTERNET_SERVICE_HTTP,0,0);
    if (!hConn) { InternetCloseHandle(hInet); return -1; }
    HINTERNET hReq=HttpOpenRequestA(hConn,"POST",path,NULL,NULL,NULL,0,0);
    if (!hReq) { InternetCloseHandle(hConn); InternetCloseHandle(hInet); return -1; }
    char hdrs[1024];
    wsprintfA(hdrs,"Content-Type: application/json\r\nX-RBFID: %s\r\nX-TOTP-Token: %s\r\nX-Timestamp: %s\r\n",rbfid,totp,ts_str);
    HttpAddRequestHeadersA(hReq,hdrs,-1,HTTP_ADDREQ_FLAG_ADD);
    BOOL ok=HttpSendRequestA(hReq,NULL,0,(void*)body,(int)strlen(body));
    if (!ok) { InternetCloseHandle(hReq); InternetCloseHandle(hConn); InternetCloseHandle(hInet); return -1; }
    DWORD status=0,ss=sizeof(status);
    HttpQueryInfoA(hReq,HTTP_QUERY_STATUS_CODE|HTTP_QUERY_FLAG_NUMBER,&status,&ss,NULL);
    if (status!=200) { InternetCloseHandle(hReq); InternetCloseHandle(hConn); InternetCloseHandle(hInet); return -1; }
    bfree(resp); binit(resp);
    char buf[4096]; DWORD rd;
    while (InternetReadFile(hReq,buf,sizeof(buf),&rd) && rd>0) bput(resp,buf,(int)rd);
    bputs(resp,"");
    InternetCloseHandle(hReq); InternetCloseHandle(hConn); InternetCloseHandle(hInet);
    return 0;
}

/* ─── Minimal JSON ─── */
static const char *json_find(const char *j, const char *key) {
    char n[64]; wsprintfA(n,"\"%s\"",key); return strstr(j,n);
}
static const char *json_val(const char *j, const char *key) {
    const char *p=json_find(j,key); if(!p) return NULL;
    p=strchr(p,':'); if(!p) return NULL; p++; while(*p==' ') p++; return p;
}
static int json_bool(const char *j, const char *key, int def) {
    const char *p=json_val(j,key); if(!p) return def;
    if(strncmp(p,"true",4)==0) return 1; if(strncmp(p,"false",5)==0) return 0; return def;
}
static int json_int(const char *j, const char *key, int def) {
    const char *p=json_val(j,key); if(!p) return def;
    if(*p=='"') p++; return atoi(p);
}
static const char *json_str(const char *j, const char *key, char *out, int ol) {
    const char *p=json_val(j,key); if(!p) return NULL;
    if(*p!='"') return NULL; p++; int i=0;
    while(*p && *p!='"' && i<ol-1) out[i++]=*p++; out[i]=0; return p;
}
static int json_str_array(const char *j, const char *key, char out[][64], int max) {
    const char *p=json_find(j,key); if(!p) return 0;
    p=strchr(p,'['); if(!p) return 0; p++; int c=0;
    while(*p && *p!=']' && c<max) {
        while(*p==' '||*p==',') p++;
        if(*p=='"') { p++; int i=0; while(*p && *p!='"' && i<63) out[c][i++]=*p++; out[c][i]=0; if(i>0) c++; if(*p=='"') p++; }
        else if(*p==']') break; else p++;
    } return c;
}
static int json_int_array(const char *j, const char *key, int *out, int max) {
    const char *p=json_find(j,key); if(!p) return 0;
    p=strchr(p,'['); if(!p) return 0; p++; int c=0;
    while(*p && *p!=']' && c<max) {
        while(*p==' '||*p==',') p++;
        if(isdigit((unsigned char)*p)||*p=='-') { out[c++]=atoi(p); while(isdigit((unsigned char)*p)||*p=='-') p++; }
        else if(*p==']') break; else p++;
    } return c;
}

/* ─── Config ─── */
typedef struct { char rbfid[64]; char base[260]; char work[260]; } Location;
typedef struct { Location locs[16]; int locCount; char url[256]; } Config;
static Config cfg;

static void cfg_load(const char *p) {
    memset(&cfg,0,sizeof(cfg)); strcpy(cfg.url,DEFAULT_URL);
    FILE *f=fopen(p,"rb"); if(!f) return;
    fseek(f,0,SEEK_END); long sz=ftell(f); fseek(f,0,SEEK_SET);
    if(sz<=0||sz>65536) { fclose(f); return; }
    char *b=malloc(sz+1); fread(b,1,sz,f); b[sz]=0; fclose(f);
    json_str(b,"url",cfg.url,sizeof(cfg.url));
    const char *q=strstr(b,"\"locations\""); if(q&&(q=strchr(q,'['))) { q++;
        while(*q&&*q!=']'&&cfg.locCount<16) {
            const char *ob=strchr(q,'{'); if(!ob) break;
            const char *eb=strchr(ob,'}'); if(!eb) break;
            int ol=(int)(eb-ob+1); if(ol>2048) { q=eb+1; continue; }
            char t[2048]; strncpy(t,ob,ol); t[ol]=0;
            Location *l=&cfg.locs[cfg.locCount];
            json_str(t,"rbfid",l->rbfid,sizeof(l->rbfid));
            json_str(t,"base",l->base,sizeof(l->base));
            json_str(t,"work",l->work,sizeof(l->work));
            if(l->rbfid[0]&&l->base[0]) cfg.locCount++;
            q=eb+1;
        }
    }
    free(b);
}
static void cfg_save(const char *p) {
    FILE *f=fopen(p,"w"); if(!f) return;
    fprintf(f,"{\n  \"url\": \"%s\",\n  \"locations\": [\n",cfg.url);
    for(int i=0;i<cfg.locCount;i++) fprintf(f,"    { \"rbfid\": \"%s\", \"base\": \"%s\", \"work\": \"%s\" }%s\n",cfg.locs[i].rbfid,cfg.locs[i].base,cfg.locs[i].work,i<cfg.locCount-1?",":"");
    fprintf(f,"  ]\n}\n"); fclose(f);
}

/* ─── File helpers ─── */
static int fexists(const char *p) { return GetFileAttributesA(p)!=INVALID_FILE_ATTRIBUTES; }
static int is_dir(const char *p) { DWORD a=GetFileAttributesA(p); return a!=INVALID_FILE_ATTRIBUTES && (a&FILE_ATTRIBUTE_DIRECTORY); }
static long long fsize(const char *p) {
    WIN32_FILE_ATTRIBUTE_DATA i; if(!GetFileAttributesExA(p,GetFileExInfoStandard,&i)) return 0;
    return ((long long)i.nFileSizeHigh<<32)|i.nFileSizeLow;
}
static long long fmtime(const char *p) {
    WIN32_FILE_ATTRIBUTE_DATA i; if(!GetFileAttributesExA(p,GetFileExInfoStandard,&i)) return 0;
    SYSTEMTIME st; FileTimeToSystemTime(&i.ftLastWriteTime,&st);
    struct tm tm={st.wSecond,st.wMinute,st.wHour,st.wDay,st.wMonth-1,st.wYear-1900};
    return (long long)mktime(&tm);
}
static void mkdir_p(const char *p) {
    char t[260]; strncpy(t,p,260); t[259]=0;
    for(char *c=t+3;*c;c++) if(*c=='\\') { *c=0; _mkdir(t); *c='\\'; }
    _mkdir(t);
}

/* ─── Scan disk ─── */
static int scan_disk(void) {
    const char *drives[]={"C","D","E","F","G","H"};
    int added=0;
    for(int i=0;i<6&&cfg.locCount<16;i++) {
        char base[260]; wsprintfA(base,"%s:\\pvsi",drives[i]);
        if(!is_dir(base)) continue;
        char rbfid[64]={0};
        char rf[260]; wsprintfA(rf,"%s\\.rbfid",base);
        if(fexists(rf)) { FILE *f=fopen(rf,"r"); if(f) { fgets(rbfid,64,f); fclose(f); } int k=(int)strlen(rbfid); while(k>0&&(rbfid[k-1]=='\r'||rbfid[k-1]=='\n')) rbfid[--k]=0; }
        if(!rbfid[0]) {
            char ini[260]; wsprintfA(ini,"%s\\rbf\\rbf.ini",base);
            if(fexists(ini)) { FILE *f=fopen(ini,"r"); if(f) { char l[256]; while(fgets(l,256,f)) if(strstr(l,"_suc=")||strstr(l,"_SUC=")) { char *v=strchr(l,'='); if(v) { v++; while(*v==' '||*v=='"') v++; int k=0; while(*v&&*v!='\r'&&*v!='\n'&&*v!='"') rbfid[k++]=*v++; rbfid[k]=0; } break; } fclose(f); } }
        }
        if(rbfid[0]) {
            Location *l=&cfg.locs[cfg.locCount++];
            strncpy(l->rbfid,rbfid,sizeof(l->rbfid)-1);
            strncpy(l->base,base,sizeof(l->base)-1);
            wsprintfA(l->work,"%s\\quickbck",base);
            log_info("Found [%s] at %s",l->rbfid,l->base); added++;
        }
    }
    return added;
}

/* ─── Find file case-insensitive ─── */
static void find_ci(const char *dir, const char *fname, char *out, int ol) {
    out[0]=0; char full[260]; wsprintfA(full,"%s\\%s",dir,fname);
    if(fexists(full)) { strncpy(out,full,ol-1); return; }
    char pat[260]; wsprintfA(pat,"%s\\*",dir);
    WIN32_FIND_DATAA fd; HANDLE h=FindFirstFileA(pat,&fd);
    if(h==INVALID_HANDLE_VALUE) return;
    char up[64]; int i; for(i=0;fname[i];i++) up[i]=toupper((unsigned char)fname[i]); up[i]=0;
    do {
        if(fd.dwFileAttributes&FILE_ATTRIBUTE_DIRECTORY) continue;
        char fu[64]; for(i=0;fd.cFileName[i];i++) fu[i]=toupper((unsigned char)fd.cFileName[i]); fu[i]=0;
        if(strcmp(fu,up)==0) { wsprintfA(out,"%s\\%s",dir,fd.cFileName); break; }
    } while(FindNextFileA(h,&fd));
    FindClose(h);
}

/* ─── Wildcard match ─── */
static int wcmatch(const char *s, const char *m) {
    for(;;) {
        if(*m=='*') { m++; if(!*m) return 1; while(*s) { if(wcmatch(s,m)) return 1; s++; } return 0; }
        else if(*m=='?') { if(!*s) return 0; s++; m++; }
        else { if(toupper((unsigned char)*s)!=toupper((unsigned char)*m)) return 0; if(!*s) return 1; s++; m++; }
    }
}

/* ─── Upload file ─── */
static void upload_file(const char *service, const Location *loc, const char *fname, const char *wp) {
    long long sz = fsize(wp); if(sz<=0) return;
    int cs = chunk_sz((int)sz);
    int total_chunks = (int)((sz+cs-1)/cs);
    char (*ch_hashes)[12] = calloc(total_chunks, 12);
    if (!ch_hashes) { log_error("OOM for chunk hashes"); return; }

    /* Compute chunk hashes */
    FILE *fh = fopen(wp,"rb"); if(!fh) { free(ch_hashes); return; }
    for(int ci=0;ci<total_chunks;ci++) {
        unsigned char buf[CHUNK_MAX];
        int n = (int)fread(buf,1,cs<CHUNK_MAX?cs:CHUNK_MAX,fh); if(n<=0) break;
        xxh3_rev_b64(buf,n,ch_hashes[ci]);
    }
    fclose(fh);
    /* Build comma-separated for JSON array */
    int chs_len=total_chunks*13; char *chs_buf=malloc(chs_len); if(!chs_buf){free(ch_hashes);return;}
    chs_buf[0]=0;
    for(int ci=0;ci<total_chunks;ci++) {
        if(ci>0) strcat(chs_buf,",");
        strcat(chs_buf,ch_hashes[ci]);
    }

    /* Full file hash */
    char fh_b64[32]; { unsigned char fhb[8]; xxh3_file(wp,fhb); unsigned char rv[8]; for(int i=0;i<8;i++) rv[i]=fhb[7-i]; b64enc_nopad(rv,8,fh_b64); }

    char sync_body[8192];
    snprintf(sync_body,sizeof(sync_body),
        "{\"service\":\"%s\",\"files\":[{\"filename\":\"%s\",\"hash_completo\":\"%s\",\"chunk_hashes\":[%s],\"mtime\":%lld,\"size\":%lld}]}",
        service,fname,fh_b64,chs_buf,fmtime(wp),sz);

    Buf resp; binit(&resp);

    while(1) {
        bfree(&resp);
        if(http_req("sync",loc->rbfid,sync_body,&resp)!=0) { log_error("sync failed for %s, retry",fname); Sleep(2000); continue; }

        /* Track size increase from file_changes */
        const char *fc=strstr(resp.d,"\"file_changes\"");
        if(fc) { const char *as=strchr(fc,'['),*ae=strchr(fc,']'); if(as&&ae&&as<ae) {
            int dl=(int)(ae-as+1); if(dl<4096) { char ft[4096]; strncpy(ft,as,dl-1); ft[dl-1]=0;
                const char *cp=ft; while((cp=strstr(cp,"\"diff_bytes\""))!=NULL) { const char *vp=strchr(cp,':'); if(vp) { vp++; while(*vp==' ') vp++; g_size_inc+=atoi(vp); cp=vp+1; } }
            }
        }}

        if(!json_bool(resp.d,"needs_upload",0)) {
            log_info("  Sync %s: COMPLETO",fname);
            /* Display file_changes details */
            const char *fcp=strstr(resp.d,"\"file_changes\"");
            if(fcp&&(fcp=strchr(fcp,'['))){fcp++;const char *ob=strchr(fcp,'{');
            if(ob){const char *eb=strchr(ob,'}');if(eb){
                int ol=(int)(eb-ob+1);if(ol>0&&ol<4096){
                    char ft[4096];strncpy(ft,ob,ol);ft[ol]=0;
                    char f[64]={0},h[64]={0},d[260]={0},osf[16]={0},nsf[16]={0},dff[16]={0},tf[32]={0};int gp=0;
                    json_str(ft,"file",f,sizeof(f));
                    json_str(ft,"hash",h,sizeof(h));
                    json_str(ft,"dest",d,sizeof(d));
                    json_str(ft,"old_size_fmt",osf,sizeof(osf));
                    json_str(ft,"new_size_fmt",nsf,sizeof(nsf));
                    json_str(ft,"diff_fmt",dff,sizeof(dff));
                    json_str(ft,"time_diff_fmt",tf,sizeof(tf));
                    gp=json_int(ft,"growth_pct",0);
                    log_info("  [%s] Hash: %s | Dest: %s",f[0]?f:fname,h,d);
                    if(osf[0])log_info("  Size: %s -> %s (diff: %s, %d%%)",osf,nsf,dff,gp);
                    if(tf[0])log_info("  Time diff: %s",tf);
                }}}}
            break;
        }

        int idxs[MAX_CHUNKS],cnt=json_int_array(resp.d,"needs_upload",idxs,MAX_CHUNKS),remain=cnt;
        log_info("  Sync %s: %d chunks pendientes (%.1f%% de desfase)",fname,cnt,(float)cnt/total_chunks*100);

        for(int i=0;i<cnt;i++) {
            int ci=idxs[i];
            long long off=(long long)ci*cs; int rsz=cs; if(off+rsz>sz) rsz=(int)(sz-off);
            fh=fopen(wp,"rb"); if(!fh) continue; fseeko64(fh,off,SEEK_SET);
            unsigned char *cd=malloc(rsz); int act=(int)fread(cd,1,rsz,fh); fclose(fh);
            if(act<=0) { free(cd); continue; }

            g_uncomp+=act;

            mz_ulong comp_len=mz_compressBound(act);
            unsigned char *comp=malloc(comp_len);
            int comp_ok=(mz_compress(comp,&comp_len,cd,act)==MZ_OK);
            if(comp_ok) g_comp+=comp_len; else { comp_len=act; memcpy(comp,cd,act); }

            int b64len=(int)((comp_len+2)/3*4+4);
            char *b64d=malloc(b64len);
            b64enc(comp_ok?comp:cd,comp_ok?(int)comp_len:act,b64d);
            g_bytes_xfer+=comp_ok?(int)comp_len:act;

            int body_len=(int)strlen(b64d)+512;
            char *up_body=malloc(body_len);
            snprintf(up_body,body_len,
                "{\"service\":\"%s\",\"filename\":\"%s\",\"chunk_index\":%d,\"chunk_hash\":\"%s\",\"data\":\"%s\",\"size\":%lld,\"compressed\":%s}",
                service,fname,ci,ch_hashes[ci],b64d,sz,comp_ok?"true":"false");

            for(int att=0;att<3;att++) {
                bfree(&resp);
                int r=http_req("upload",loc->rbfid,up_body,&resp);
                if(r==0&&json_bool(resp.d,"ok",0)) { remain--; g_chunks_xfer++;
                    int ratio=comp_ok?(int)(comp_len*100/act):100;
                    log_info("  [%.1f%%] Uploaded chunk %d of %s (compression: %d%%, %d -> %d bytes)",(float)(cnt-remain)/cnt*100,ci,fname,ratio,act,comp_ok?(int)comp_len:act); break; }
                log_info("  Retry chunk %d (%d/3)",ci,att+1); Sleep(1000);
            }
            free(up_body); free(b64d); free(comp); free(cd);
        }
    }
    free(ch_hashes); free(chs_buf);
    bfree(&resp);
}

/* ─── Base64 decode ─── */
static int b64dec(const char *in, unsigned char *out, int out_max) {
    static const unsigned char b64r[256]={0};
    static int initd=0;
    if(!initd){for(int i=0;i<64;i++){unsigned char c=B64T[i];((unsigned char*)b64r)[c]=i;}initd=1;}
    int len=(int)strlen(in); if(len%4!=0) return -1;
    int oi=0;
    for(int i=0;i<len&&oi<out_max;i+=4){
        unsigned v=0;
        for(int j=0;j<4;j++){
            unsigned char ci=(unsigned char)in[i+j];
            if(ci=='=') break;
            v=(v<<6)|b64r[ci];
        }
        if(oi<out_max)out[oi++]=(unsigned char)(v>>16);
        if(oi<out_max&&in[i+2]!='=')out[oi++]=(unsigned char)(v>>8);
        if(oi<out_max&&in[i+3]!='=')out[oi++]=v;
    }
    return oi;
}

/* ─── Download ─── */
static void download_files(const char *service, const Location *loc, const char *src_dir, const char *dest_dir, const char *work_dir, char files[][64], int fc, int *ok_count, char ok_names[][64]) {
    /* Build local files list */
    char local_json[32768]={0};
    strcat(local_json, "{\"service\":\"");
    strcat(local_json, service);
    strcat(local_json, "\",\"files\":[");
    int first=1;
    for(int f=0;f<fc;f++) {
        char *item=files[f]; if(!item[0]) continue;
        if(strchr(item,'*')||strchr(item,'?')) continue; /* skip masks */
        char sf[260]; find_ci(dest_dir,item,sf,sizeof(sf));
        if(!sf[0]) continue;
        unsigned char fhb[8]; xxh3_file(sf,fhb);
        unsigned char rv[8]; for(int i=0;i<8;i++) rv[i]=fhb[7-i];
        char hb64[32]; b64enc_nopad(rv,8,hb64);
        char up[64]; int ui; for(ui=0;item[ui];ui++) up[ui]=toupper((unsigned char)item[ui]); up[ui]=0;
        if(!first) strcat(local_json,",");
        first=0;
        char entry[512]; snprintf(entry,sizeof(entry),"{\"filename\":\"%s\",\"size\":%lld,\"hash\":\"%s\",\"mtime\":%lld}",up,fsize(sf),hb64,fmtime(sf));
        strcat(local_json, entry);
    }
    strcat(local_json, "]}");

    Buf resp; binit(&resp);
    if(http_req("download_list",loc->rbfid,local_json,&resp)!=0 || !json_bool(resp.d,"ok",0)) {
        log_info("  Download list failed"); bfree(&resp); return;
    }

    int dchunk=json_int(resp.d,"chunk_size",65536);
    /* Parse files array from response */
    const char *fa=strstr(resp.d,"\"files\"");
    if(!fa||!(fa=strchr(fa,'['))){bfree(&resp);return;}
    fa++;
    while(*fa&&*fa!=']'){
        const char *ob=strchr(fa,'{'); if(!ob) break;
        const char *eb=strchr(ob,'}'); if(!eb) break;
        int ol=(int)(eb-ob+1); if(ol>4096){fa=eb+1;continue;}
        char tmp[4096]; strncpy(tmp,ob,ol); tmp[ol]=0;
        char fn[260]={0}; json_str(tmp,"filename",fn,sizeof(fn));
        int fsz=json_int(tmp,"size",0);
        if(!fn[0]){fa=eb+1;continue;}

        char upfn[64]; int ui; for(ui=0;fn[ui];ui++) upfn[ui]=toupper((unsigned char)fn[ui]); upfn[ui]=0;
        char wf[260]; snprintf(wf,sizeof(wf),"%s\\%s",work_dir,upfn);
        char df[260]; snprintf(df,sizeof(df),"%s\\%s",dest_dir,upfn);
        /* Create parent dirs */
        {char pd[260];strncpy(pd,wf,259);pd[259]=0;char *ls=strrchr(pd,'\\');if(ls){*ls=0;mkdir_p(pd);}}
        {char pd2[260];strncpy(pd2,df,259);pd2[259]=0;char *ls2=strrchr(pd2,'\\');if(ls2){*ls2=0;mkdir_p(pd2);}}

        int total=(int)((fsz+dchunk-1)/dchunk);
        char sz_fmt[32]; if(fsz<1048576)snprintf(sz_fmt,sizeof(sz_fmt),"%.2f KB",(double)fsz/1024);else snprintf(sz_fmt,sizeof(sz_fmt),"%.2f MB",(double)fsz/1048576);
        log_info("  Downloading: %s (%s, %d chunks)",fn,sz_fmt,total);
        FILE *fw=fopen(wf,"wb"); if(!fw){fa=eb+1;continue;}
        XXH3_state_t *hst=XXH3_createState(); XXH3_64bits_reset(hst);

        for(int ci=0;ci<total;ci++){
            char dl_body[1024];
            snprintf(dl_body,sizeof(dl_body),"{\"service\":\"%s\",\"filename\":\"%s\",\"chunk_index\":%d,\"size\":%d}",service,fn,ci,fsz);
            bfree(&resp);
            if(http_req("download_file",loc->rbfid,dl_body,&resp)!=0){log_error("  Chunk %d download failed",ci);break;}
            const char *dp=strstr(resp.d,"\"data\"");
            if(!dp){break;}
            dp=strchr(dp,':'); if(!dp) break; dp++; while(*dp==' ') dp++;
            if(*dp!='"') break; dp++;
            const char *eq=strchr(dp,'"'); if(!eq) break;
            int dlen=(int)(eq-dp);
            if(dlen<=0) break;
            char *b64data=malloc(dlen+1); strncpy(b64data,dp,dlen); b64data[dlen]=0;
            unsigned char *raw=malloc(dlen); int rlen=b64dec(b64data,raw,dlen);
            if(rlen>0){fwrite(raw,1,rlen,fw);XXH3_64bits_update(hst,raw,rlen);}
            g_bytes_xfer+=rlen;
            char chunk_hash[32]; {unsigned char hb[8];unsigned char rv[8];xxh3_bin(raw,rlen,hb);for(int j=0;j<8;j++)rv[j]=hb[7-j];b64enc_nopad(rv,8,chunk_hash);}
            free(raw); free(b64data);
            log_info("  [%.0f%%] Chunk %d/%d (%s, %d bytes)",(double)(ci+1)/total*100,ci+1,total,chunk_hash,rlen);
        }
        fclose(fw);
        unsigned char fhb[8]; XXH64_hash_t fh64=XXH3_64bits_digest(hst); XXH3_freeState(hst); memcpy(fhb,&fh64,8);
        unsigned char rv[8]; for(int j=0;j<8;j++) rv[j]=fhb[7-j];
        char fh_b64[32]; b64enc_nopad(rv,8,fh_b64);
        /* Move to dest */
        CopyFileA(wf,df,FALSE);
        long long dsz=fsize(df);
        char dsf[32]; if(dsz<1048576)snprintf(dsf,sizeof(dsf),"%.2f KB",(double)dsz/1024);else snprintf(dsf,sizeof(dsf),"%.2f MB",(double)dsz/1048576);
        log_info("  Saved: %s (%s, hash: %s)",df,dsf,fh_b64);
        if(ok_count&&ok_names) { if(*ok_count<128) { strncpy(ok_names[*ok_count],upfn,63); } (*ok_count)++; }
        fa=eb+1;
    }
    bfree(&resp);
}

/* ─── Execute a single service (upload or download) ─── */
static void execute_service(const char *sname, Location *loc) {
    g_bytes_xfer=g_chunks_xfer=g_size_inc=g_uncomp=g_comp=0;
    /* Heartbeat before executing */
    {time_t n=time(NULL);struct tm *lt=localtime(&n);char ts[16];snprintf(ts,sizeof(ts),"%02d:%02d:%02d",lt->tm_hour,lt->tm_min,lt->tm_sec);
    char hb[512];snprintf(hb,sizeof(hb),"{\"status\":\"running\",\"system_info\":{\"service\":\"%s\",\"start\":\"%s\"}}",sname,ts);
    Buf hbr;binit(&hbr);http_req("heartbeat",loc->rbfid,hb,&hbr);bfree(&hbr);}
    DWORD svc_start=GetTickCount();
    Buf cr; binit(&cr);
    char sb[256]; snprintf(sb,sizeof(sb),"{\"service\":\"%s\"}",sname);
    if(http_req("service_config",loc->rbfid,sb,&cr)!=0) { bfree(&cr); return; }
    char dir[16]="upload",src[260]="{base}",dest_c[260]="{base}",tmp[260]="%tmp%\\respaldoSucursal\\{service}",exc[256]={0};
    json_str(cr.d,"direction",dir,sizeof(dir));
    json_str(cr.d,"source",src,sizeof(src));
    json_str(cr.d,"dest",dest_c,sizeof(dest_c));
    json_str(cr.d,"temp",tmp,sizeof(tmp));
    int rec=json_bool(cr.d,"recursive",0),maxage=json_int(cr.d,"maxage",0);
    json_str(cr.d,"exclude",exc,sizeof(exc));
    char fl[128][64]; int fc=json_str_array(cr.d,"files",fl,128);
    log_info("=== Service Start: %s (%s) ===",sname,loc->rbfid);

    int sok=0,smis=0,sexc=0,fsynced=0; char sok_names[128][64]; char smis_names[128][64];
    if(strcmp(dir,"download")==0) {
        char sdir[260]; strncpy(sdir,src,sizeof(sdir)-1);
        if(strcmp(src,"{base}")==0) strncpy(sdir,loc->base,sizeof(sdir)-1);
        char ddir[260]; strncpy(ddir,dest_c,sizeof(ddir)-1);
        if(strcmp(dest_c,"{base}")==0) strncpy(ddir,loc->base,sizeof(ddir)-1);
        {char *p; while((p=strstr(ddir,"{rbfid}"))) { memmove(p,loc->rbfid,strlen(loc->rbfid)); memmove(p+strlen(loc->rbfid),p+7,strlen(p+7)+1); }}
        char tdir[260];
        {char tb[260];GetTempPathA(260,tb);int tk=(int)strlen(tb);while(tk>0&&(tb[tk-1]=='\\'||tb[tk-1]==' '))tb[--tk]=0;
        snprintf(tdir,sizeof(tdir),"%s\\respaldoSucursal\\%s",tb,sname);}
        download_files(sname,loc,sdir,ddir,tdir,fl,fc,&sok,sok_names); fsynced=sok;
    } else {
        char sdir[260]; strncpy(sdir,src,sizeof(sdir)-1);
        if(strcmp(src,"{base}")==0) strncpy(sdir,loc->base,sizeof(sdir)-1);
        char tbase[260]; GetTempPathA(260,tbase);
        int tk=(int)strlen(tbase); while(tk>0&&(tbase[tk-1]=='\\'||tbase[tk-1]==' ')) tbase[--tk]=0;
        char wdir[260]; snprintf(wdir,sizeof(wdir),"%s\\respaldoSucursal\\%s",tbase,sname);
        mkdir_p(wdir);
        if(!is_dir(sdir)) { log_error("Source dir not found: %s",sdir); }
        else {
            for(int f=0;f<fc;f++) {
                char *item=fl[f]; if(!item[0]) continue;
                if(strchr(item,'*')||strchr(item,'?')) {
                    char cmd[1024];
                    char ma[16]={0}; if(maxage>0) wsprintfA(ma," /maxage:%d",maxage);
                    wsprintfA(cmd,"robocopy %s %s %s /R:1 /W:1 /NJH /NJS /NDL /NC /NS %s%s",sdir,wdir,item,rec?"/S":"/E",ma);
                    log_info("  Running robocopy..."); system(cmd);
                    WIN32_FIND_DATAA fd; char pat[260]; wsprintfA(pat,"%s\\*",wdir);
                    HANDLE h=FindFirstFileA(pat,&fd);
                    if(h!=INVALID_HANDLE_VALUE) { do { if(fd.dwFileAttributes&FILE_ATTRIBUTE_DIRECTORY) continue;
                        char fp[260]; wsprintfA(fp,"%s\\%s",wdir,fd.cFileName);
                        upload_file(sname,loc,fd.cFileName,fp); if(sok<128)strncpy(sok_names[sok],fd.cFileName,63); sok++; fsynced++;
                    } while(FindNextFileA(h,&fd)); FindClose(h); }
                    if(exc[0]) {
                        WIN32_FIND_DATAA fdx; HANDLE hx=FindFirstFileA(pat,&fdx);
                        if(hx!=INVALID_HANDLE_VALUE) { do { if(fdx.dwFileAttributes&FILE_ATTRIBUTE_DIRECTORY) continue;
                            char *m=exc; while(m&&*m) { while(*m==' '||*m==',') m++;
                                char mask[64]; int mi=0; while(*m&&*m!=','&&*m!=' '&&mi<63) mask[mi++]=*m++; mask[mi]=0; if(!mi) continue;
                                if(wcmatch(fdx.cFileName,mask)) { char fp[260]; wsprintfA(fp,"%s\\%s",wdir,fdx.cFileName); DeleteFileA(fp); log_info("  Excluded: %s",fdx.cFileName); sexc++; }
                            }
                        } while(FindNextFileA(hx,&fdx)); FindClose(hx); }
                    }
                } else {
                    char sf[260]; find_ci(sdir,item,sf,sizeof(sf));
                    if(!sf[0]) { log_info("File not found: %s",item); if(smis<128)strncpy(smis_names[smis],item,63); smis++; continue; }
                    char up[64]; int ui; for(ui=0;item[ui];ui++) up[ui]=toupper((unsigned char)item[ui]); up[ui]=0;
                    char df[260]; char p[16][64]; int pc=0;
                    char ti[260]; strncpy(ti,item,260);
                    char *tok=strtok(ti,"\\/");
                    while(tok&&pc<16) { strncpy(p[pc++],tok,63); tok=strtok(NULL,"\\/"); }
                    if(pc>1) { char sub[260]="";
                        for(int pi=0;pi<pc-1;pi++) { strcat(sub,"\\"); strcat(sub,p[pi]); }
                        char sd[260]; snprintf(sd,sizeof(sd),"%s%s",wdir,sub); mkdir_p(sd);
                        snprintf(df,sizeof(df),"%s%s\\%s",wdir,sub,up);
                    } else snprintf(df,sizeof(df),"%s\\%s",wdir,up);
                    mkdir_p(wdir);
                    if(CopyFileA(sf,df,FALSE)) { log_info("--- Processing: %s ---",up); upload_file(sname,loc,up,df); if(sok<128)strncpy(sok_names[sok],up,63); sok++; fsynced++; }
                }
            }
        }
    }
    if(smis>0) {
        char missing_body[8192]={0}; strcat(missing_body,"{\"service\":\"");
        strcat(missing_body,sname); strcat(missing_body,"\",\"missing_files\":[");
        for(int mi=0;mi<smis;mi++) { if(mi>0) strcat(missing_body,","); strcat(missing_body,"\""); strcat(missing_body,smis_names[mi]); strcat(missing_body,"\""); }
        strcat(missing_body,"]}");
        Buf mr; binit(&mr); http_req("missing",loc->rbfid,missing_body,&mr); bfree(&mr);
    }
    log_info("[RESULT] files_count=%d, sync_ok=%d, sync_missing=%d, sync_excluded=%d, files_sync=%d",sok+smis,sok,smis,sexc,fsynced);
    if(sok>0){char okn[4096]={0};int op=0;for(int i=0;i<sok;i++){if(i>0)op+=snprintf(okn+op,sizeof(okn)-op,", ");op+=snprintf(okn+op,sizeof(okn)-op,"%s",sok_names[i]);}log_info("[OK] %s",okn);}
    if(smis>0){char misn[4096]={0};int mp=0;for(int i=0;i<smis;i++){if(i>0)mp+=snprintf(misn+mp,sizeof(misn)-mp,", ");mp+=snprintf(misn+mp,sizeof(misn)-mp,"%s",smis_names[i]);}log_msg("WARN","[MISSING] %s",misn);}
    const char *status="success";
    if(smis>0) status=(sok==0)?"failed":"partial";
    DWORD svc_ms=GetTickCount()-svc_start;
    char df[32],sf2[32];
    snprintf(df,sizeof(df),g_bytes_xfer<1048576?"%.2f KB":"%.2f MB",g_bytes_xfer<1048576?(double)g_bytes_xfer/1024:(double)g_bytes_xfer/1048576);
    snprintf(sf2,sizeof(sf2),g_size_inc<1048576?"%.2f KB":"%.2f MB",g_size_inc<1048576?(double)g_size_inc/1024:(double)g_size_inc/1048576);
    log_info("=== Service End: %s | Status: %s ===",sname,status);
    log_info("  Time: %ldms | Data: %s | Chunks: %d | Size +: %s",svc_ms,df,g_chunks_xfer,sf2);
    if(g_uncomp>0&&g_comp>0){long long saved=g_uncomp-g_comp;int sp=(int)(saved*100/g_uncomp);
        char of[32],cf[32],svf[32];
        snprintf(of,sizeof(of),g_uncomp<1048576?"%.2f KB":"%.2f MB",g_uncomp<1048576?(double)g_uncomp/1024:(double)g_uncomp/1048576);
        snprintf(cf,sizeof(cf),g_comp<1048576?"%.2f KB":"%.2f MB",g_comp<1048576?(double)g_comp/1024:(double)g_comp/1048576);
        snprintf(svf,sizeof(svf),saved<1048576?"%.2f KB":"%.2f MB",saved<1048576?(double)saved/1024:(double)saved/1048576);
        log_info("  Compression: %s -> %s (saved: %s, %d%%)",of,cf,svf,sp);}
    Buf rr; binit(&rr);
    char rb[16384]; int rp=0;
    rp+=snprintf(rb+rp,sizeof(rb)-rp,"{\"service\":\"%s\",\"status\":\"%s\",\"results\":{\"files_count\":%d,\"files_sync\":%d,\"sync_ok\":[",sname,status,sok+smis,fsynced);
    for(int i=0;i<sok;i++){if(i>0)rp+=snprintf(rb+rp,sizeof(rb)-rp,",");rp+=snprintf(rb+rp,sizeof(rb)-rp,"\"%s\"",sok_names[i]);}
    rp+=snprintf(rb+rp,sizeof(rb)-rp,"],\"sync_missing\":[");
    for(int i=0;i<smis;i++){if(i>0)rp+=snprintf(rb+rp,sizeof(rb)-rp,",");rp+=snprintf(rb+rp,sizeof(rb)-rp,"\"%s\"",smis_names[i]);}
    rp+=snprintf(rb+rp,sizeof(rb)-rp,"],\"sync_excluded\":%d},\"execution_time_ms\":%ld}",sexc,svc_ms);
    http_req("service_result",loc->rbfid,rb,&rr); bfree(&rr);
    bfree(&cr);
}

/* ─── Orchestrator ─── */
static void run_orchestrator(void) {
    if(cfg.locCount==0) { scan_disk(); if(cfg.locCount==0) { cfg_save("config.json"); return; } }
    cfg_save("config.json");
    log_info("Orchestrator started with %d locations.",cfg.locCount);
    while(1) {
        DWORD poll_start=GetTickCount();
        for(int i=0;i<cfg.locCount;i++) {
            Location *loc=&cfg.locs[i];
            Buf r; binit(&r);
            {time_t n=time(NULL);struct tm *lt=localtime(&n);char ts[16];snprintf(ts,sizeof(ts),"%02d:%02d:%02d",lt->tm_hour,lt->tm_min,lt->tm_sec);
            char hb[512];snprintf(hb,sizeof(hb),"{\"status\":\"running\",\"system_info\":{\"cycle_start\":\"%s\"}}",ts);
            http_req("heartbeat",loc->rbfid,hb,&r);} bfree(&r);
            binit(&r);
            if(http_req("schedule",loc->rbfid,"{}",&r)==0 && json_bool(r.d,"ok",0)) {
                char svc[16][64]; int sn=json_str_array(r.d,"services",svc,16);
                for(int s=0;s<sn;s++) execute_service(svc[s],loc);
            }
            bfree(&r);
        }
        DWORD el=GetTickCount()-poll_start;
        if(el>50000) { log_info("Overdue (%ldms), re-polling in 10s",el); Sleep(10000); }
        else { DWORD slp=50000-el; if(slp<10000) slp=10000; Sleep(slp); }
    }
}

/* ─── Commands ─── */
static void cmd_status(void) {
    log_info("= ===== Status ===== ="); log_info("Version: %s",VERSION); log_info("Locations: %d",cfg.locCount);
    for(int i=0;i<cfg.locCount;i++) log_info("  [%s] Path: %s",cfg.locs[i].rbfid,cfg.locs[i].base);
}
static void cmd_list(const char *rbfid) {
    Buf r; binit(&r);
    if(http_req("list_services",rbfid,"{}",&r)!=0||!json_bool(r.d,"ok",0)) {
        char e[128]="?"; json_str(r.d,"error",e,sizeof(e)); log_error("Error: %s",e); bfree(&r); return; }
    log_info("Services for [%s]:",rbfid);
    const char *sp=strstr(r.d,"\"services\""); if(sp&&(sp=strchr(sp,'['))) { sp++;
        while(*sp&&*sp!=']') { const char *ob=strchr(sp,'{'); if(!ob) break; const char *eb=strchr(ob,'}'); if(!eb) break;
            int ol=(int)(eb-ob+1); if(ol>2048) { sp=eb+1; continue; } char t[2048]; strncpy(t,ob,ol); t[ol]=0;
            char n[64]={0},tp[16]={0},ls[16]={0},le[32]={0}; int fq=0;
            json_str(t,"name",n,sizeof(n)); json_str(t,"type",tp,sizeof(tp)); fq=json_int(t,"frequency_seconds",0);
            json_str(t,"last_status",ls,sizeof(ls)); json_str(t,"last_execution",le,sizeof(le));
            log_info("  %-20s | %-10s | %-4ds | %-19s | %s",n,tp,fq,le,ls);
            sp=eb+1;
        }
    }
    bfree(&r);
}

int main(int argc, char **argv) {
    log_info("cli version: %s",VERSION); _mkdir("logs"); cfg_load("config.json");
    if(argc<2) {
        if(cfg.locCount==0) scan_disk();
        cmd_status();
        log_info("Usage: cli.exe [command] | -serviceName [rbfid]");
        log_info("Commands: s|start|main, b|buscar|scan, l|ls [rbfid]");
        return 0;
    }
    const char *cmd=argv[1];
    if(strcmp(cmd,"s")==0||strcmp(cmd,"start")==0||strcmp(cmd,"main")==0) {
        if(cfg.locCount==0) { scan_disk(); cfg_save("config.json"); } run_orchestrator();
    } else if(strcmp(cmd,"b")==0||strcmp(cmd,"buscar")==0||strcmp(cmd,"scan")==0||strcmp(cmd,"scann")==0) {
        scan_disk(); cfg_save("config.json"); log_info("Scan complete. %d location(s).",cfg.locCount);
    } else if(strcmp(cmd,"l")==0||strcmp(cmd,"ls")==0) {
        if(argc<3) { log_error("Usage: cli.exe ls [rbfid]"); return 1; } cmd_list(argv[2]);
    } else if(cmd[0]=='-') {
        if(argc<3) { log_error("Usage: cli.exe -serviceName [rbfid]"); return 1; }
        const char *sname=cmd+1; const char *rbfid=argv[2];
        Location *loc=NULL;
        for(int i=0;i<cfg.locCount;i++) if(strcmp(cfg.locs[i].rbfid,rbfid)==0) { loc=&cfg.locs[i]; break; }
        if(!loc) { log_error("RBFID not found: %s",rbfid); return 1; }
        execute_service(sname,loc);
    } else log_error("Unknown: %s",cmd);
    return 0;
}
