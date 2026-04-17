clear
git pull
git push
if [ ! -d "logs" ]; then
    mkdir logs
fi
chmod -Rv a+w logs/*.log
chmod -Rv a+w *
git log --oneline | head
