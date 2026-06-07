#pragma once

#include <stddef.h>

void xxh3_bin(const void *d, size_t n, unsigned char out[8]);
void xxh3_file(const char *path, unsigned char out[8]);
void xxh3_rev_b64(const void *d, size_t n, char *out);
