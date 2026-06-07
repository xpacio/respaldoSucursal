CC = x86_64-w64-mingw32-gcc
CFLAGS = -O2 -Wall -Iinclude
LDFLAGS = -lws2_32 -lwininet

SRCS = src/main.c src/log.c src/buf.c src/b64.c src/xxh3.c src/jsonmin.c src/globals.c src/config.c src/http.c src/file_helpers.c src/upload.c src/download.c src/orchestrator.c
OBJS = $(SRCS:.c=.o)

all: cli.exe

cli.exe: $(SRCS)
	$(CC) $(CFLAGS) -o $@ $(SRCS) $(LDFLAGS)

clean:
	rm -f cli.exe *.o
