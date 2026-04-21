git fetch
git pull
git status
git add .
git commit -m "autocomit :: %date% %time% %computername%"
git push
timeout 2
ssh sync "cd /var/www/respaldoSucursal/ && git stash"
ssh sync "cd /var/www/respaldoSucursal/ && git pull"
::ssh sync "cd /var/www/respaldoSucursal/ && git pull origin evo"
