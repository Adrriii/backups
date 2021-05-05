#!/bin/sh

TABLE=$1
echo "Creating backup for $TABLE\n"
mysqldump --single-transaction -h osudaily.net -u backup -p***REMOVED*** $TABLE > $(date +"%Y-%m-%d")-$TABLE.sql
