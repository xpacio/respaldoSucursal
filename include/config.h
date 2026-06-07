#pragma once

#include "jsonmin.h"

void cfg_load(const char *p);
void cfg_save(const char *p);
int scan_disk(void);

typedef struct { char rbfid[64]; char base[260]; char work[260]; } Location;
typedef struct { Location locs[16]; int locCount; char url[256]; char agent_id[20]; } Config;

extern Config cfg;
