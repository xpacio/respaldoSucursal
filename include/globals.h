#pragma once

extern long long g_bytes_xfer;
extern int g_chunks_xfer;
extern long long g_size_inc;
extern long long g_uncomp;
extern long long g_comp;

#define VERSION "260607A" // placeholder; will be updated per commit sequence
#define DEFAULT_URL "http://respaldosucursal.servicios.care"
#define CHUNK_MIN 65536
#define CHUNK_MAX 1048576
#define MAX_CHUNKS 8192
