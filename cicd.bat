git add .
git commit -m "autocomit :: %date% %time% %computername%"
ssh sync "cd /var/www/respaldoSucursal/ && git stash"
ssh sync "cd /var/www/respaldoSucursal/ && git pull origin evo"
