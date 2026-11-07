#!/bin/sh
# supervisord event listener (docker/supervisord.conf): a program that has failed for good (FATAL: it would not stay up
# after its start retries) stops supervisord, and with it the container, so Docker's restart policy starts it afresh
# instead of leaving a container without its API running.
printf 'READY\n'
while read -r header; do
	len=$(printf '%s\n' "$header" | sed -n 's/.*len:\([0-9]*\).*/\1/p')
	payload=$(head -c "${len:-0}")
	echo "supervisor-fatal: ${payload}, stopping the container" >&2
	kill -TERM 1
	printf 'RESULT 2\nOK'
	printf 'READY\n'
done
