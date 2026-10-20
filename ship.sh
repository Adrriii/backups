#!/bin/bash
# Copies today's backups (YYYY-MM-DD-*) from one directory to another, at the lowest CPU and IO
# priority on both ends, then deletes the destination's backups older than <keep days>.
# Either side may be remote (host:dir). Extra arguments go to rsync, e.g. --bwlimit=15m,
# --fuzzy (send only what changed since yesterday's file: run.php writes rsyncable dumps), -W.
#
# --tree: the source holds one directory per night (YYYY-MM-DD/, e.g. hard-linked snapshots): the whole
# tree is copied with its hard links, so a night costs only what changed. <keep days> must be at least
# the source's own, or the nights in between are sent again every night.
#
# Usage: ship.sh [--tree] <source dir> <destination dir> <keep days> [rsync options...]
set -euo pipefail

TREE=false
if [ "${1:-}" = --tree ]; then TREE=true; shift; fi
SRC=${1:?usage: ship.sh [--tree] <source dir> <destination dir> <keep days> [rsync options...]}
DEST=${2:?}
KEEP=${3:?}
shift 3
day=$(date +%F)
low='nice -n 19 ionice -c3'

# runs a command where <dir> lives
at() { # at <host:dir or dir> <command>
	case $1 in
	*:*) ssh -o BatchMode=yes "${1%%:*}" "$2" ;;
	*) bash -c "$2" ;;
	esac
}
path() { case $1 in *:*) echo "${1#*:}" ;; *) echo "$1" ;; esac }

at "$DEST" "mkdir -p '$(path "$DEST")'"
if $TREE; then
	# shellcheck disable=SC2086
	$low rsync -aH --exclude='.*.partial' --rsync-path="$low rsync" "$@" "$SRC/" "$DEST/"
	at "$DEST" "find '$(path "$DEST")' -mindepth 1 -maxdepth 1 -type d -name '20??-??-??' -mtime +$KEEP -exec rm -rf {} +"
else
	# the source's glob is expanded where the source lives
	src="$SRC/$day-*"
	case $SRC in *:*) ;; *) src=$(compgen -G "$src" | tr '\n' ' ') ;; esac
	[ -n "$src" ] || { echo "ship: nothing from $day in $SRC" >&2; exit 1; }
	# shellcheck disable=SC2086
	$low rsync -a --rsync-path="$low rsync" "$@" $src "$DEST/"
	at "$DEST" "find '$(path "$DEST")' -maxdepth 1 -name '20??-??-??-*' -mtime +$KEEP -exec rm -rf {} +"
fi
echo "ship: $SRC -> $DEST ($(at "$DEST" "du -sh '$(path "$DEST")' | cut -f1") kept there)"
