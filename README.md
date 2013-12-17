Mail Manager Web Service
========================

For use with Mail Manager class:

https://github.com/pwaring/mail-manager

Primarily intended for use within the School of Computer Science at the University of Manchester.

Parameters
----------

The following parameters should be supplied:

 * `username`: Student database username.
 * `password`: Student database password.
 * `host`: Student database host.
 * `dbname`: Student database name.
 * `subject`: Email subject.
 * `recipient`: Email recipient.
 * `body`: Email body.

If you use the Mail Manager class (recommended), it will automatically set the above parameters for you.

Recipient domains
-----------------

This web service will only allow the sending of emails to domains specified in the `recipient-domains` file (one domain per line).
