
#!/bin/sh

do_backup()
{
	TABLE=$1
	echo "Creating backup for $TABLE\n"
	mysqldump --single-transaction -h ***REMOVED*** -u backup -p***REMOVED*** $TABLE > $(date +"%Y-%m-%d")-$TABLE.sql
}

do_backup armap
do_backup arma
do_backup stats

