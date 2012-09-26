Add the following script to your DeployStudio workflow:

#!/bin/sh

curl http://yourserver/deploy_scripts/munkiserver.php?mac=${DS_PRIMARY_MAC_ADDRESS}&domain=${DS_ASSIGNED_DOMAIN}&name=${DS_HOSTNAME}&group=${DS_COMPUTER_GROUP}&token=<insert_token_here>

exit 0
