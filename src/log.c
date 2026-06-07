#include "../include/log.h"
#include <time.h>
#include <stdio.h>
#include <stdarg.h>
#include <stdlib.h>

void log_msg(const char *tag, const char *fmt, ...) {
    time_t t = time(NULL); struct tm *lt = localtime(&t);
    va_list ap; va_start(ap, fmt);
    char buf[4096]; vsnprintf(buf, sizeof(buf), fmt, ap); va_end(ap);
    printf("[%02d:%02d:%02d] [%s] %s\n", lt->tm_hour, lt->tm_min, lt->tm_sec, tag, buf);
    FILE *f = fopen("logs\\cli.log", "a");
    if (f) { fprintf(f, "[%04d-%02d-%02d %02d:%02d:%02d] [%s] %s\n",
        lt->tm_year+1900, lt->tm_mon+1, lt->tm_mday,
        lt->tm_hour, lt->tm_min, lt->tm_sec, tag, buf); fclose(f); }
}
