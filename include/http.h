#pragma once

int http_req(const char *action, const char *rbfid, const char *body, void *resp_buf_out);

// resp_buf_out is expected to be a Buf* (from include/buf.h)
