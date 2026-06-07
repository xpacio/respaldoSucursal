#include "../include/http.h"
#include "../include/globals.h"
#include "../include/log.h"
#include "../include/totp.h"
#include "../include/buf.h"
#include "../include/agent.h"
#include <windows.h>
#include <wininet.h>
#include <stdio.h>
#include <string.h>
#include <stdlib.h>

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

int http_req(const char *action, const char *rbfid, const char *body, void *resp_buf_out) {
    char url[512], host[128], path[384]; int port;
    snprintf(url,sizeof(url),"%s/api/%s/%s",DEFAULT_URL,action,rbfid);
    parse_url(url,host,sizeof(host),path,sizeof(path),&port);
    char totp[64]; totp_gen(rbfid,totp);
    char ts_str[32]; snprintf(ts_str,sizeof(ts_str),"%lld",(long long)time(NULL));

    int max_attempts = 3;
    int attempt = 0;
    Buf *resp = (Buf*)resp_buf_out;
    while(attempt < max_attempts) {
        attempt++;
        HINTERNET hInet=InternetOpenA("respcli/1.0",INTERNET_OPEN_TYPE_PRECONFIG,NULL,NULL,0);
        if (!hInet) { log_error("InternetOpenA failed"); return -1; }
        HINTERNET hConn=InternetConnectA(hInet,host,port,NULL,NULL,INTERNET_SERVICE_HTTP,0,0);
        if (!hConn) { InternetCloseHandle(hInet); log_error("InternetConnectA failed"); return -1; }
        HINTERNET hReq=HttpOpenRequestA(hConn,"POST",path,NULL,NULL,NULL,0,0);
        if (!hReq) { InternetCloseHandle(hConn); InternetCloseHandle(hInet); log_error("HttpOpenRequestA failed"); return -1; }
        load_agent_id();
        char hdrs[1024];
        wsprintfA(hdrs,"Content-Type: application/json\r\nX-RBFID: %s\r\nX-TOTP-Token: %s\r\nX-Timestamp: %s\r\nX-Agent-ID: %s\r\n",rbfid,totp,ts_str,cfg.agent_id);
        HttpAddRequestHeadersA(hReq,hdrs,-1,HTTP_ADDREQ_FLAG_ADD);
        BOOL ok=HttpSendRequestA(hReq,NULL,0,(void*)body,(int)strlen(body));
        if (!ok) { InternetCloseHandle(hReq); InternetCloseHandle(hConn); InternetCloseHandle(hInet); log_error("HttpSendRequestA failed (attempt %d)", attempt); }
        else {
            DWORD status=0,ss=sizeof(status);
            HttpQueryInfoA(hReq,HTTP_QUERY_STATUS_CODE|HTTP_QUERY_FLAG_NUMBER,&status,&ss,NULL);
            if (status!=200) {
                const char *err_msg="HTTP Error";
                switch(status) {
                    case 401: err_msg="Unauthorized (401) - Auth failed"; break;
                    case 403: err_msg="Forbidden (403) - Access denied"; break;
                    case 404: err_msg="Not Found (404) - Resource not found"; break;
                    case 500: err_msg="Server Error (500) - Internal server error"; break;
                    default: break;
                }
                log_error("HTTP request failed: %s | action=%s, rbfid=%s, status=%lu",err_msg,action,rbfid,status);
                InternetCloseHandle(hReq); InternetCloseHandle(hConn); InternetCloseHandle(hInet);
            } else {
                bfree(resp); binit(resp);
                char buf[4096]; DWORD rd;
                while (InternetReadFile(hReq,buf,sizeof(buf),&rd) && rd>0) bput(resp,buf,(int)rd);
                bputs(resp,"");
                InternetCloseHandle(hReq); InternetCloseHandle(hConn); InternetCloseHandle(hInet);
                return 0;
            }
        }
        // backoff
        int backoff = 1000 * (1 << (attempt-1)); if(backoff>8000) backoff=8000;
        Sleep(backoff);
    }
    return -1;
}
