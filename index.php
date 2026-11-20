<?php

/*
 * Only reached when the web server can't rewrite URLs (see .htaccess):
 * send visitors to the app's public folder.
 */
header('Location: public/', true, 302);
