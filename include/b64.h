#pragma once

void b64enc(const unsigned char *in, size_t len, char *out);
void b64enc_nopad(const unsigned char *in, size_t len, char *out);
int b64dec(const char *in, unsigned char *out, int out_max);
