#!/bin/bash
SOURCE=/home/gyni/web/gyni/
DESTINATION=/var/www/html/
LOG=/var/www/hugo.log

TEMP=`mktemp -d`
echo "Building from $SOURCE"
hugo --source="$SOURCE" --destination="$TEMP" --logFile="$LOG"
if [ $? -eq 0 ]; then
    echo "Syncing to $DESTINATION"
    rsync -aq --delete "$TEMP/" "$DESTINATION"
fi
echo "Cleaning up"
rm -r $TEMP
echo "Setting up file permissions"
chown -R gyni:www-data /var/www/html/
find /var/www/html -type d -exec chmod 755 {} \;
find /var/www/html -type f -exec chmod 644 {} \;
echo "Setup permissions successfully"
echo "Restarting apache2 service"
systemctl restart apache2
echo "Success, website published"