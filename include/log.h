#pragma once

#include <stdio.h>
#include <stdarg.h>

void log_msg(const char *tag, const char *fmt, ...);
#define log_info(...)  log_msg("INFO", __VA_ARGS__)
#define log_error(...) log_msg("ERROR", __VA_ARGS__)
#define log_debug(...) log_msg("DEBUG", __VA_ARGS__)
