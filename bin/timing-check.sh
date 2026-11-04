#!/usr/bin/env bash
#
# Simple timing check for learner pages (phase 1 alpha gate).
#
# Fetches each URL N times and prints the median and slowest time to first
# byte and total time. Pass a logged-in cookie to measure the learner view.
#
# Usage:
#   bin/timing-check.sh [-n runs] [-c "cookie header"] url [url...]
#
# Example against wp-env with seed data (logged out):
#   bin/timing-check.sh http://localhost:8888/courses/ \
#     http://localhost:8888/lessons/testing-with-a-keyboard/
set -euo pipefail

runs=10
cookie=""
while getopts "n:c:" opt; do
	case "$opt" in
		n) runs="$OPTARG" ;;
		c) cookie="$OPTARG" ;;
		*) echo "Usage: $0 [-n runs] [-c cookie] url..." >&2; exit 2 ;;
	esac
done
shift $((OPTIND - 1))
[ "$#" -gt 0 ] || { echo "Usage: $0 [-n runs] [-c cookie] url..." >&2; exit 2; }

median() { sort -n | awk '{ a[NR] = $1 } END { if (NR % 2) print a[(NR + 1) / 2]; else printf "%.4f\n", (a[NR / 2] + a[NR / 2 + 1]) / 2 }'; }
slowest() { sort -n | tail -1; }

printf '%-60s %10s %10s %10s %10s\n' "URL" "ttfb med" "ttfb max" "total med" "total max"
for url in "$@"; do
	# Warm the object and opcode caches once.
	curl -s -o /dev/null ${cookie:+-H "Cookie: $cookie"} "$url"
	ttfb=""
	total=""
	for _ in $(seq "$runs"); do
		line=$(curl -s -o /dev/null ${cookie:+-H "Cookie: $cookie"} -w '%{time_starttransfer} %{time_total}' "$url")
		ttfb+="${line%% *}"$'\n'
		total+="${line##* }"$'\n'
	done
	printf '%-60s %10s %10s %10s %10s\n' "${url:0:60}" \
		"$(printf '%s' "$ttfb" | median)" "$(printf '%s' "$ttfb" | slowest)" \
		"$(printf '%s' "$total" | median)" "$(printf '%s' "$total" | slowest)"
done
