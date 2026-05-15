CC = x86_64-w64-mingw32-gcc
CFLAGS = -Os -s -fdata-sections -ffunction-sections -std=c11 \
         -DWIN32_LEAN_AND_MEAN -DXXH_INLINE_ALL \
         -DMINIZ_NO_ARCHIVE_APIS -DMINIZ_NO_STDIO -DMINIZ_NO_TIME \
         -DMINIZ_NO_ZLIB_COMPATIBLE_NAMES
LDFLAGS = -lwininet -lws2_32 -Wl,--gc-sections
TARGET = cli.exe
SRCS = cli.c miniz.c miniz_tdef.c miniz_tinfl.c

.PHONY: all clean

all: $(TARGET)

$(TARGET): $(SRCS) xxhash.h miniz.h
	$(CC) $(CFLAGS) -o $@ $(SRCS) $(LDFLAGS)

clean:
	rm -f $(TARGET)
