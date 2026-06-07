#include "../include/download.h"
#include "../include/b64.h"
#include "../include/xxh3.h"
#include "../include/log.h"
#include "../include/buf.h"
#include <stdio.h>
#include <stdlib.h>
#include <string.h>

void download_files(const char *service, const Location *loc, const char *src_dir, const char *dest_dir, const char *work_dir, char files[][64], int fc, int *ok_count, char ok_names[][64]) {
    char local_json[32768]={0};
    strcat(local_json, "{\"service\":\""); strcat(local_json, service); strcat(local_json, "\",\"files\":[");
    int first=1;
    for(int f=0;f<fc;f++) { char *item=files[f]; if(!item[0]) continue; char sf[260]; snprintf(sf,sizeof(sf),"%s\\%s",src_dir,item); if(!first) strcat(local_json,","); first=0; char entry[512]; snprintf(entry,sizeof(entry),"{\"filename\":\"%s\",\"size\":%lld,\"hash\":\"%s\",\"mtime\":%lld}",item,0,"",0); strcat(local_json,entry); }
    strcat(local_json, "]}");
    Buf resp; binit(&resp);
    if(http_req("download_list",loc->rbfid,local_json,&resp)!=0 || !json_bool(resp.d,"ok",0)) { log_info("Download list failed"); bfree(&resp); return; }
    bfree(&resp);
}
