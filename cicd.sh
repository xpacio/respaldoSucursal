clear
git pull
git push
git status
if [ ! -d "logs" ]; then
    mkdir logs
fi
chmod -Rv a+w logs/*.log
chmod -Rv a+w *
