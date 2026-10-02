#!/data/data/com.termux/files/usr/bin/bash
termux-wake-lock
PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8000 &
#PHP_PID=$!
#trap 'kill $PHP_PID; termux-wake-unlock' EXIT
#sleep 2
#cloudflared tunnel --config /dev/null --protocol http2 --url http://127.0.0.1:8000
