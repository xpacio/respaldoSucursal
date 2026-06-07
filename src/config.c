#include "../include/config.h"
#include <stdio.h>
#include <string.h>
#include <stdlib.h>
#include <direct.h>
#include <windows.h>

Config cfg;

void cfg_load(const char *p) {
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

void cfg_save(const char *p) {
    FILE *f=fopen(p,"w"); if(!f) return;
    fprintf(f,"{\n  \"url\": \"%s\",\n  \"locations\": [\n",cfg.url);
    for(int i=0;i<cfg.locCount;i++) fprintf(f,"    { \"rbfid\": \"%s\", \"base\": \"%s\", \"work\": \"%s\" }%s\n",cfg.locs[i].rbfid,cfg.locs[i].base,cfg.locs[i].work,i<cfg.locCount-1?",":"");
    fprintf(f,"  ]\n}\n"); fclose(f);
}

static int fexists_local(const char *p) { return GetFileAttributesA(p)!=INVALID_FILE_ATTRIBUTES; }
static int is_dir_local(const char *p) { DWORD a=GetFileAttributesA(p); return a!=INVALID_FILE_ATTRIBUTES && (a&FILE_ATTRIBUTE_DIRECTORY); }

int scan_disk(void) {
    const char *drives[]={"C","D","E","F","G","H"};
    int added=0;
    for(int i=0;i<6&&cfg.locCount<16;i++) {
        char base[260]; wsprintfA(base,"%s:\\pvsi",drives[i]);
        if(!is_dir_local(base)) continue;
        char rbfid[64]={0};
        char rf[260]; wsprintfA(rf,"%s\\.rbfid",base);
        if(fexists_local(rf)) { FILE *f=fopen(rf,"r"); if(f) { fgets(rbfid,64,f); fclose(f); } int k=(int)strlen(rbfid); while(k>0&&(rbfid[k-1]=='\r'||rbfid[k-1]=='\n')) rbfid[--k]=0; }
        if(!rbfid[0]) {
            char ini[260]; wsprintfA(ini,"%s\\rbf\\rbf.ini",base);
            if(fexists_local(ini)) { FILE *f=fopen(ini,"r"); if(f) { char l[256]; while(fgets(l,256,f)) {
                if(strstr(l,"_suc=")||strstr(l,"_SUC=")) { char *v=strchr(l,'='); if(v) { v++; while(*v==' '||*v=='\t') v++; strncpy(rbfid,v,63); int kk=(int)strlen(rbfid); while(kk>0&&(rbfid[kk-1]=='\r'||rbfid[kk-1]=='\n')) rbfid[--kk]=0; break; } }
            } fclose(f); }
        }
        if(rbfid[0]) {
            Location *l=&cfg.locs[cfg.locCount++];
            strncpy(l->rbfid,rbfid,sizeof(l->rbfid)-1);
            strncpy(l->base,base,sizeof(l->base)-1);
            wsprintfA(l->work,"%s\\quickbck",base);
            added++;
        }
    }
    return added;
}
