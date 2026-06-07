#include "../include/http.h"
#include "../include/globals.h"
#include "../include/log.h"
#include "../include/xxh3.h"
#include "../include/buf.h"
#include <windows.h>
#include <wininet.h>
#include <stdio.h>
#include <string.h>
#include <stdlib.h>

static int http_req_internal(const char *action, const char *rbfid, const char *body, Buf *resp) {
    char url[512], host[128], path[384]; int port;
    snprintf(url,sizeof(url),"%s/api/%s/%s",DEFAULT_URL,action,rbfid);
    // simple parse
    const char *p=url;
    if(strncmp(p,"https://",8)==0) p+=8; else if(strncmp(p,"http://",7)==0) p+=7;
    const char *slash=strchr(p,'/'); if(slash) { int n=(int)(slash-p); strncpy(host,p,n); host[n]=0; strncpy(path,slash,sizeof(path)); } else { strncpy(host,p,sizeof(host)); strcpy(path,"/"); }
    char totp[64]; totp_gen(rbfid,totp);
    char ts_str[32]; snprintf(ts_str,sizeof(ts_str),"%lld",(long long)time(NULL));
    HINTERNET hInet=InternetOpenA("respcli/1.0",INTERNET_OPEN_TYPE_PRECONFIG,NULL,NULL,0);
    if (!hInet) return -1;
    HINTERNET hConn=InternetConnectA(hInet,host,port,NULL,NULL,INTERNET_SERVICE_HTTP,0,0);
    if (!hConn) { InternetCloseHandle(hInet); return -1; }
    HINTERNET hReq=HttpOpenRequestA(hConn,"POST",path,NULL,NULL,NULL,0,0);
    if (!hReq) { InternetCloseHandle(hConn); InternetCloseHandle(hInet); return -1; }
    char hdrs[1024]; wsprintfA(hdrs,"Content-Type: application/json\r\nX-RBFID: %s\r\nX-TOTP-Token: %s\r\nX-Timestamp: %s\r\nX-Agent-ID: %s\r\n",rbfid,totp,ts_str,"");
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

int http_req(const char *action, const char *rbfid, const char *body, void *resp_buf_out) {
    Buf *resp = (Buf*)resp_buf_out;
    return http_req_internal(action, rbfid, body, resp);
}
