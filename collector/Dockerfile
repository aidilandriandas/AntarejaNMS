# Build: dari root repo -> docker build -f collector/Dockerfile -t antareja-collector .
FROM golang:1.26-alpine AS build
WORKDIR /src
COPY collector/go.mod collector/go.sum ./
RUN go mod download
COPY collector/ ./
ARG VERSION=0.4.0
# timetzdata: zona waktu tertanam di biner (tidak perlu paket tzdata)
RUN CGO_ENABLED=0 go build -trimpath -tags timetzdata -ldflags "-s -w -X main.version=${VERSION}" -o /out/antareja-collector ./cmd/antareja-collector

FROM alpine:3.22
RUN adduser -D -H -u 10001 antareja \
    && mkdir -p /var/lib/antareja/spool && chown -R antareja /var/lib/antareja && chmod 700 /var/lib/antareja/spool
COPY --from=build /out/antareja-collector /usr/local/bin/antareja-collector
COPY db/migrations /app/migrations
USER antareja
# Ping memakai ICMP tanpa hak istimewa (socket datagram); Docker mengizinkannya lewat
# net.ipv4.ping_group_range bawaan container, jadi tidak perlu root maupun CAP_NET_RAW.
ENV ANTAREJA_MIGRATIONS_DIR=/app/migrations ANTAREJA_PING_PRIVILEGED=false
HEALTHCHECK --interval=30s --timeout=5s --start-period=120s CMD ["antareja-collector", "health"]
ENTRYPOINT ["antareja-collector"]
CMD ["run"]
