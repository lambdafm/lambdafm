#!/bin/bash
cd `dirname $0`
echo -n "Check started "
date -u
/usr/bin/php ./lambdafm.php
