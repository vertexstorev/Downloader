#!/data/data/com.termux/files/usr/bin/bash
termux-wake-lock
PHP_CLI_SERVER_WORKERS=4 php -S 0.0.0.0:8000 &
sleep 2
proot-distro login ubuntu -- ngrok http 127.0.0.1:8000 &
