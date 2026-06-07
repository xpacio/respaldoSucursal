#pragma once

#include <stddef.h>

typedef struct { char *d; int len, cap; } Buf;

void binit(Buf *b);
int bput(Buf *b, const void *s, int l);
int bputs(Buf *b, const char *s);
void bfree(Buf *b);
