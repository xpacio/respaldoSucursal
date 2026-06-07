#include "include/log.h"
#include "include/buf.h"
#include "include/config.h"
#include "include/orchestrator.h"
#include "include/globals.h"
#include <stdio.h>
#include <string.h>
#include <direct.h>

int main(int argc, char **argv) {
    log_info("cli version: %s", VERSION);
    _mkdir("logs");
    cfg_load("config.json");
    if(argc<2) {
        if(cfg.locCount==0) scan_disk();
        log_info("Locations: %d", cfg.locCount);
        log_info("Usage: cli.exe [command] | -serviceName [rbfid]");
        return 0;
    }
    const char *cmd=argv[1];
    if(strcmp(cmd,"s")==0||strcmp(cmd,"start")==0||strcmp(cmd,"main")==0) {
        if(cfg.locCount==0) { scan_disk(); cfg_save("config.json"); } run_orchestrator();
    } else if(strcmp(cmd,"b")==0||strcmp(cmd,"buscar")==0||strcmp(cmd,"scan")==0||strcmp(cmd,"scann")==0) {
        scan_disk(); cfg_save("config.json"); log_info("Scan complete. %d location(s).",cfg.locCount);
    } else if(strcmp(cmd,"l")==0||strcmp(cmd,"ls")==0) {
        if(argc<3) { log_error("Usage: cli.exe ls [rbfid]"); return 1; }
        // minimal: call list_services
        Buf r; binit(&r);
        if(http_req("list_services",argv[2],"{}",&r)!=0||!json_bool(r.d,"ok",0)) { char e[128]="?"; json_str(r.d,"error",e,sizeof(e)); log_error("Error: %s",e); bfree(&r); return 1; }
        bfree(&r);
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
