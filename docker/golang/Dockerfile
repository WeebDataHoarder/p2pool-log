FROM golang:1.19-alpine

RUN apk update && apk add --no-cache \
    git gcc g++ musl-dev bash autoconf automake cmake make libtool gettext

RUN git clone --depth 1 --branch master https://github.com/tevador/RandomX.git /tmp/RandomX && cd /tmp/RandomX && \
    mkdir build && cd build && \
    cmake .. -DCMAKE_BUILD_TYPE=Release -D CMAKE_INSTALL_PREFIX:PATH=/usr && \
    make -j$(nproc) && \
    make install