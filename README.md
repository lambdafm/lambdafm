### Dependencies
`php5-cli php5-curl php5-json php5-simplexml`

### Usage
1. Create `authcredentials.php` according to template from `lambdafm.php`
2. **`chmod 400` it!**
3. Tweak `lambdafm.php` if you wish
4. Make sure the script dir is writable by the user the script runs under
5. Add `start.sh` to a cron job with the interval of several minutes, optionally with `2>&1 >> logfile`
6. **Do not let anyone touch `*.latest_timestamp` files!**
7. Ready!
