FROM alpine:3.22
RUN apk add --no-cache net-snmp
COPY snmpd.conf /etc/snmp/snmpd.conf
CMD ["snmpd", "-f", "-Lo", "-C", "-c", "/etc/snmp/snmpd.conf", "udp:161"]
