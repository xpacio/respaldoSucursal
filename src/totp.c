#include "../include/totp.h"
#include "../include/xxh3.h"
#include <time.h>
#include <stdio.h>
#include <string.h>

void totp_gen(const char *rbfid, char *out) {
    char buf[128];
    unsigned long long t = (unsigned long long)time(NULL)/100ULL;
    snprintf(buf,sizeof(buf),"%llu", t);
    strncat(buf, rbfid, sizeof(buf)-strlen(buf)-1);
    xxh3_rev_b64(buf, strlen(buf), out);
}
