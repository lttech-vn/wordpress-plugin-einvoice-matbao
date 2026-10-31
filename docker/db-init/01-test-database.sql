-- Separate database for the PHPUnit integration suites so tests never touch the dev site.
CREATE DATABASE IF NOT EXISTS wordpress_test;
GRANT ALL PRIVILEGES ON wordpress_test.* TO 'wordpress'@'%';
FLUSH PRIVILEGES;
