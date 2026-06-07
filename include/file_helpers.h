#pragma once

int fexists(const char *p);
int is_dir(const char *p);
long long fsize(const char *p);
long long fmtime(const char *p);
void mkdir_p(const char *p);
void find_ci(const char *dir, const char *fname, char *out, int ol);
int wcmatch(const char *s, const char *m);
