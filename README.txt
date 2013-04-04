Add the following script to your DeployStudio workflow:

#!/bin/sh

ip6addr=$(ifconfig en0 | grep inet6 | grep autoconf | grep -v temporary | awk '{print $2}')

curl http://yourserver/deploy_scripts/munkiserver.php?mac=${DS_PRIMARY_MAC_ADDRESS}&domain=${DS_ASSIGNED_DOMAIN}&name=${DS_HOSTNAME}&group=${DS_COMPUTER_GROUP}&token=<insert_token_here>

curl http://yourserver/deploy_scripts/proteus.php?mac=${DS_PRIMARY_MAC_ADDRESS}&domain=${DS_ASSIGNED_DOMAIN}&name=${DS_HOSTNAME}&group=${DS_COMPUTER_GROUP}&token=<insert_token_here>&ip6=${ip6addr}

exit 0

To specify IPv4 addresses for host names, create a file ip.txt with name (without domain) and IP separated by a semicolon, one line for each host.