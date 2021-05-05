#!/bin/sh

do_backup_rg()
{
	TABLE=$1
	echo "Creating backup for $TABLE\n"
	mysqldump --single-transaction -h localhost -u backup -p***REMOVED*** $TABLE > $(date +"%Y-%m-%d")-$TABLE.sql
}

do_backup_rg whattrack
do_backup_rg quaver
