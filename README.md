# backups

Quick & Easy backup solution in PHP 7.4 for MySQL/MariaDB and/or linux files and directories.
This is basically just a script that makes it easy for anyone to automate simple backups without much knowledge.

## Install

- Clone this repository where you wish to hold your backups
- Copy `config.php.copy` to `config.php`
- Fill in the required details (explained below)

## Configure

The `config.php` file simply initializes required variables

- `DAYS_KEEP` : Amount of days that will not be deleted starting from the current date.
- `DAYS_FOREVER` : Array of days (format DD) that will never be deleted.

- `SERVERS` : Dictionnary of Server. Each will be processed iteratively. The Key will be used to name this server
  - `Server.ADDRESS` : The host address
  - `Server.SFTPUSER` : The SFTP account username *[Required only if FILES or DIRS is not empty]*
  - `Server.SFTPPASS` : The SFTP account password *[Required only if FILES or DIRS is not empty]*
  - `Server.DBUSER` : The MySQL/MariaDB account username *[Required only if DATABASES is not empty]*
  - `Server.DBPASS` : The MySQL/MariaDB account password *[Required only if DATABASES is not empty]*
  - `Server.DATABASES` : Array of database names that are accessible by DBUSER
  - `Server.FILES` : Array of absolute file paths accessible by SFTPUSER
  - `Server.DIRS` : Array of absolute directory paths accessible by SFTPUSER
  
## Plan backups

This backup solution is preferrably ran each day. If you need multiple backup hours throughout the day, duplicate the repository and setup your automated task solution accordingly.

### Linux 

- From a user account with sufficient permissions to access your backup directory, run `crontab -e` and add this line at the end of the file :
`MM HH * * * cd BACKUP_PATH; php run.php;`
- Replace BACKUP_PATH with the absolute path where you cloned this repository (for example : `/home/user/backups/`), it should contain the file `run.php` and `config.php`
- Replace HH with the hour of the backup schedule (00 to 23)
- Replace MM with the minute of the backup schedule (00 to 59)

Example of a backup that is ran each day at 8:30 PM with the project cloned in the root folder :
`30 20 * * * cd /backups; php run.php;`

### Windows

- Open the `Task Scheduler`
- Create a new task with `Action > Create Basic Task`
- Follow the steps according to your preferences and select `Start a Program`
- The program you need to start is php so you have to find its full path, then you need to pass it `-f BACKUP_PATH`

This will prompt a command line window each time this runs, you may want to find a way to hide it if this is a computer you are actively using at the time of the backup.

## Steps order

You may want to know in which order things are processed in order to obtain the most critical items first.

- Each server is processed iteratively, starting from the first specificed one
  - Within a server, each database is processed in the specified order
  - Each file is then processed
  - Each directory is then recursively and fully processed
  
This means that, if for the same server you want to process the files first and then the database, your config should look like this :

- Server_files
  - Host info
  - SFTP info
  - Empty Databases array
  - Files
  - Directories
- Server_db
  - Host info
  - DB info
  - Databases
  - Empty Files array
  - Empty Directories array
  
## Planned features
  
In the future, I want to implement (from top priority to low priority) :
- Backup output folder for a server
- Config folder instead of file
- Process the different backup types in whichever order it is specified in the config
- Server z-index to give priority (when config is in a folder and not a single array)
