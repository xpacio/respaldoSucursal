#include "../include/orchestrator.h"
#include "../include/log.h"
#include "../include/config.h"
#include "../include/http.h"
#include <windows.h>
#include <string.h>

void execute_service(const char *sname, Location *loc) {
    log_info("=== Service Start: %s (%s) ===", sname, loc->rbfid);
    // Minimal: request service_config and log
    char sb[256]; snprintf(sb,sizeof(sb),"{\"service\":\"%s\"}",sname);
    Buf cr; binit(&cr);
    if(http_req("service_config",loc->rbfid,sb,&cr)!=0) { bfree(&cr); log_error("service_config request failed"); return; }
    bfree(&cr);
    log_info("=== Service End: %s ===",sname);
}

void run_orchestrator(void) {
    log_info("Orchestrator started.");
    if(cfg.locCount==0) { scan_disk(); if(cfg.locCount==0) { cfg_save("config.json"); return; } }
    cfg_save("config.json");
    while(1) {
        for(int i=0;i<cfg.locCount;i++) {
            Location *loc=&cfg.locs[i];
            Buf r; binit(&r);
            http_req("heartbeat",loc->rbfid,"{}",&r); bfree(&r);
            binit(&r);
            if(http_req("schedule",loc->rbfid,"{}",&r)==0 && json_bool(r.d,"ok",0)) {
                char svc[16][64]; int sn=json_str_array(r.d,"services",svc,16);
                for(int s=0;s<sn;s++) execute_service(svc[s],loc);
            }
            bfree(&r);
        }
        Sleep(20000);
    }
}
