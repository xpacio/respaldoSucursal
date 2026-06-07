#include "../include/file_helpers.h"
#include <windows.h>
#include <direct.h>
#include <string.h>
#include <ctype.h>

int fexists(const char *p) { return GetFileAttributesA(p)!=INVALID_FILE_ATTRIBUTES; }
int is_dir(const char *p) { DWORD a=GetFileAttributesA(p); return a!=INVALID_FILE_ATTRIBUTES && (a&FILE_ATTRIBUTE_DIRECTORY); }
long long fsize(const char *p) {
    WIN32_FILE_ATTRIBUTE_DATA i; if(!GetFileAttributesExA(p,GetFileExInfoStandard,&i)) return 0;
    return ((long long)i.nFileSizeHigh<<32)|i.nFileSizeLow;
}
long long fmtime(const char *p) {
    WIN32_FILE_ATTRIBUTE_DATA i; if(!GetFileAttributesExA(p,GetFileExInfoStandard,&i)) return 0;
    SYSTEMTIME st; FileTimeToSystemTime(&i.ftLastWriteTime,&st);
    struct tm tm={st.wSecond,st.wMinute,st.wHour,st.wDay,st.wMonth-1,st.wYear-1900};
    return (long long)mktime(&tm);
}
void mkdir_p(const char *p) {
    char t[260]; strncpy(t,p,260); t[259]=0;
    for(char *c=t+3;*c;c++) if(*c=='\\') { *c=0; _mkdir(t); *c='\\'; }
    _mkdir(t);
}
void find_ci(const char *dir, const char *fname, char *out, int ol) {
    out[0]=0; char full[260]; wsprintfA(full,"%s\\%s",dir,fname);
    if(fexists(full)) { strncpy(out,full,ol-1); return; }
    char pat[260]; wsprintfA(pat,"%s\\*",dir);
    WIN32_FIND_DATAA fd; HANDLE h=FindFirstFileA(pat,&fd);
    if(h==INVALID_HANDLE_VALUE) return;
    char up[64]; int i; for(i=0;fname[i];i++) up[i]=toupper((unsigned char)fname[i]); up[i]=0;
    do {
        if(fd.dwFileAttributes&FILE_ATTRIBUTE_DIRECTORY) continue;
        char fu[64]; for(i=0;fd.cFileName[i];i++) fu[i]=toupper((unsigned char)fd.cFileName[i]); fu[i]=0;
        if(strcmp(fu,up)==0) { wsprintfA(out,"%s\\%s",dir,fd.cFileName); break; }
    } while(FindNextFileA(h,&fd));
    FindClose(h);
}
int wcmatch(const char *s, const char *m) {
    for(;;) {
        if(*m=='*') { m++; if(!*m) return 1; while(*s) { if(wcmatch(s,m)) return 1; s++; } return 0; }
        else if(*m=='?') { if(!*s) return 0; s++; m++; }
        else { if(toupper((unsigned char)*s)!=toupper((unsigned char)*m)) return 0; if(!*s) return 1; s++; m++; }
    }
}
