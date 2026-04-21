git add .
git commit -m "autocomit :: %date% %time% %computername%"
timeout 2
ssh sync "cd /var/www/respaldoSucursal/ && git stash"
ssh sync "cd /var/www/respaldoSucursal/ && git pull origin evo"
