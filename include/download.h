#pragma once

#include "config.h"

void download_files(const char *service, const Location *loc, const char *src_dir, const char *dest_dir, const char *work_dir, char files[][64], int fc, int *ok_count, char ok_names[][64]);
