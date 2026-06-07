#include "../include/jsonmin.h"
#include <string.h>
#include <ctype.h>
#include <stdlib.h>

const char *json_find(const char *j, const char *key) {
    char n[64]; snprintf(n,sizeof(n),"\"%s\"",key); return strstr(j,n);
}
const char *json_val(const char *j, const char *key) {
    const char *p=json_find(j,key); if(!p) return NULL;
    p=strchr(p,':'); if(!p) return NULL; p++; while(*p==' ') p++; return p;
}
int json_bool(const char *j, const char *key, int def) {
    const char *p=json_val(j,key); if(!p) return def;
    if(strncmp(p,"true",4)==0) return 1; if(strncmp(p,"false",5)==0) return 0; return def;
}
int json_int(const char *j, const char *key, int def) {
    const char *p=json_val(j,key); if(!p) return def;
    if(*p=='"') p++; return atoi(p);
}
const char *json_str(const char *j, const char *key, char *out, int ol) {
    const char *p=json_val(j,key); if(!p) return NULL;
    if(*p!='"') return NULL; p++; int i=0;
    while(*p && *p!='"' && i<ol-1) { if(*p=='\\' && *(p+1)=='"') { out[i++]='"'; p+=2; } else { out[i++]=*p++; } }
    out[i]=0; return p;
}
int json_str_array(const char *j, const char *key, char out[][64], int max) {
    const char *p=json_find(j,key); if(!p) return 0;
    p=strchr(p,'['); if(!p) return 0; p++; int c=0;
    while(*p && *p!=']' && c<max) {
        while(*p==' '||*p==',') p++;
        if(*p=='"') { p++; int i=0; while(*p && *p!='