#pragma once

const char *json_find(const char *j, const char *key);
const char *json_val(const char *j, const char *key);
int json_bool(const char *j, const char *key, int def);
int json_int(const char *j, const char *key, int def);
const char *json_str(const char *j, const char *key, char *out, int ol);
int json_str_array(const char *j, const char *key, char out[][64], int max);
int json_int_array(const char *j, const char *key, int *out, int max);
