# Apache deployment

`tekg.conf` serves TE-Dee from its canonical `/tedee/` URL and permanently
redirects legacy `/TE-/` requests to their equivalent `/tedee/` paths. It does
not modify the existing `zjubio` virtual host or its aliases.

After PHP 8.4 and the required extensions are installed, an administrator can
enable the configuration with:

```bash
sudo install -m 0644 /app/tekg/app/deploy/apache/tekg.conf /etc/apache2/conf-available/tekg.conf
sudo a2enmod proxy proxy_fcgi setenvif rewrite headers
sudo a2enconf tekg
sudo apache2ctl configtest
sudo apache2ctl graceful
```

The canonical Alias is handled by the dedicated PHP 8.4 FPM listener on
`127.0.0.1:9084`; the container's existing PHP 7.4 configuration remains
unchanged for other projects. The application requires PHP extensions for
`curl`, `mbstring`, and `pdo_mysql`. The configuration intentionally disables
directory listings, allows the runtime-data symbolic links, and blocks direct
HTTP access to machine-local configuration files and dotfiles.

Before enabling the site, grant the Apache `www-data` account read and
directory-traversal access to `/app/tekg/app` and `/data/tekg/runtime`. Do not
grant write access to the runtime datasets or server-local secrets.
